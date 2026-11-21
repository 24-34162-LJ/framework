<?php

/*
 * FILE PURPOSE
 * File   : app/Services/ShipmentStageService.php
 * Feature: Shipments
 * Purpose: Rules for shipment stages: groups, which next stage is allowed, and updating the status.
 */

namespace App\Services;

use App\Models\Billing;
use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\ShipmentStatusUpdate;
use App\Models\Transaction;
use App\Models\TransactionHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ShipmentStageService
{
    /** @return array<string, array{label: string, class: string, stages: list<string>}> */
    public function groups(): array
    {
        $groups = [];
        foreach (ShipmentStage::query()->orderBy('sort_order')->orderBy('stage_id')->get(['stage_name', 'stage_group']) as $stage) {
            $label = (string) $stage->stage_group;
            $key = $this->groupKey($label);
            $groups[$key] ??= ['label' => $label, 'class' => $this->groupClass($label), 'stages' => []];
            $groups[$key]['stages'][] = mb_strtolower(trim((string) $stage->stage_name));
        }

        return $groups;
    }

    /** @return array{label: string, class: string} */
    public function statusInfo(string $status, ?string $group): array
    {
        return [
            'label' => $status === 'Trucking' ? 'Trucking' : ($group ?? (trim($status) !== '' ? $status : 'Unknown')),
            'class' => $this->groupClass((string) $group),
        ];
    }

    public function groupKey(string $group): string
    {
        return match (mb_strtolower(trim($group))) {
            'pending documents' => 'pending', 'customs processing' => 'customs', 'in transit' => 'transit',
            'delivered' => 'delivered', 'on hold' => 'hold', 'cancelled' => 'cancelled',
            default => trim((string) preg_replace('/[^a-z0-9]+/i', '_', mb_strtolower($group)), '_'),
        };
    }

    public function groupClass(string $group): string
    {
        return match (mb_strtolower(trim($group))) {
            'pending documents', 'on hold' => 'amber', 'in transit' => 'teal', 'delivered' => 'green', 'cancelled' => 'red', default => 'blue',
        };
    }

    public function position(Shipment $shipment, ShipmentStage $current, Collection $stages): int
    {
        if (! $this->isException($current)) {
            return (int) $current->sort_order;
        }
        foreach ($shipment->statusUpdates()->orderByDesc('updated_at')->orderByDesc('update_id')->pluck('stage') as $name) {
            $stage = $stages->firstWhere('stage_name', $name);
            if ($stage && ! $this->isException($stage)) {
                return (int) $stage->sort_order;
            }
        }

        return (int) ($stages->first()?->sort_order ?? 0);
    }

    public function isAllowed(ShipmentStage $target, ShipmentStage $current, int $position, string $role, bool $correction): bool
    {
        if ($target->stage_name === $current->stage_name || ($correction && $role !== 'admin')) {
            return false;
        }
        // Cancelling a shipment is an administrator decision (same as the Cancel shipment action)
        if ($target->stage_name === 'Cancelled' && $role !== 'admin') {
            return false;
        }
        if ($correction) {
            return true;
        }
        if ((bool) $current->is_closed) {
            return false;
        }

        return $this->isException($target) || (int) $target->sort_order > $position
            || ((int) $target->sort_order === $position && $this->isException($current));
    }

    public function isException(ShipmentStage $stage): bool
    {
        return in_array($this->groupKey((string) $stage->stage_group), ['hold', 'cancelled'], true);
    }

    /**
     * Saves a stage change: timeline entry, shipment status, transaction status and history,
     * activity log and notifications. The caller has already checked that the change is allowed
     * and should run this inside a DB transaction with the shipment row locked.
     */
    public function applyChange(Shipment $shipment, ShipmentStage $current, ShipmentStage $target, User $actor, CarbonImmutable $at, string $timelineRemarks, string $remarks, bool $correction = false, ?string $holdReason = null): void
    {
        $update = new ShipmentStatusUpdate;
        $update->shipment_id = $shipment->shipment_id;
        $update->stage = $target->stage_name;
        $update->status = $this->updateStatus($target, $correction);
        $update->remarks = $timelineRemarks !== '' ? $timelineRemarks : null;
        $update->updated_by = $actor->user_id;
        $update->updated_at = $at->format('Y-m-d H:i:s');
        $update->save();
        $shipment->current_status = $target->stage_name;
        $shipment->save();

        $transaction = Transaction::query()->where('shipment_id', $shipment->shipment_id)->orderByDesc('transaction_id')->lockForUpdate()->first();
        if (! $transaction) {
            $transaction = Transaction::query()->create(['client_id' => $shipment->client_id, 'shipment_id' => $shipment->shipment_id, 'status' => 'pending']);
        }
        $transaction->billing_id ??= $this->firstBillId($shipment);
        $transaction->status = $this->transactionStatus($target, (string) $transaction->status);
        $transaction->completed_at = $transaction->status === 'completed' ? $at->format('Y-m-d H:i:s') : null;
        $transaction->save();

        $verb = $correction ? 'Stage corrected from ' : 'Moved from ';
        TransactionHistory::query()->create([
            'transaction_id' => $transaction->transaction_id, 'event_type' => 'Status update',
            'description' => mb_substr($verb.$current->stage_name.' to '.$target->stage_name.($timelineRemarks !== '' ? ' — '.$timelineRemarks : ''), 0, 255),
            'performed_by' => $actor->user_id,
        ]);
        app(ActivityLogger::class)->record($actor, 'Shipment Update', 'Status of '.$shipment->reference_no.($correction ? ' corrected from '.$current->stage_name.' to ' : ' updated to ').$target->stage_name.($target->stage_name === 'On Hold' ? ' ('.$holdReason.')' : ''));
        app(NotificationService::class)->forShipment($shipment, $actor, 'status', 'Shipment status updated', $shipment->reference_no.' is now '.$target->stage_name.($remarks !== '' ? ': '.$remarks : ''));
    }

    /**
     * Delivery "In Transit" → shipment "Trucking"; "Delivered" → "Delivered"; "Cancelled" → "Cancelled".
     * An open shipment follows the Update Status modal rules (forward, or Cancelled at any time).
     * A closed shipment (Delivered/Cancelled) changes only when an admin saves, recorded as a
     * correction, the same as "Correct a previous stage". Archived shipments never change.
     * Returns the new stage name when the shipment moved. Run inside a DB transaction.
     */
    public function syncFromDelivery(Shipment $shipment, string $deliveryStatus, User $actor): ?string
    {
        [$current, $target] = $this->deliveryTarget($shipment, $deliveryStatus);
        if (! $current || ! $target) {
            return null;
        }
        // Forward only: the delivery form never corrects a closed or later stage backwards
        $stages = ShipmentStage::query()->orderBy('sort_order')->orderBy('stage_id')->get();
        if (! $this->isAllowed($target, $current, $this->position($shipment, $current, $stages), (string) $actor->role, false)
            || $this->deliveryBlocker($shipment, $deliveryStatus) !== null) {
            return null;
        }

        $at = CarbonImmutable::now();
        $lastAt = ShipmentStatusUpdate::query()->where('shipment_id', $shipment->shipment_id)->max('updated_at');
        if ($lastAt && CarbonImmutable::parse($lastAt)->gt($at)) {
            $at = CarbonImmutable::parse($lastAt);
        }
        $note = 'Set from delivery details.';
        $this->applyChange($shipment, $current, $target, $actor, $at, $note, $note, false);

        return $target->stage_name;
    }

    /**
     * Saving Customs Details moves the shipment forward to the furthest customs stage those details
     * support, under the same rules as Update Status. Green lane has no examination, so it goes from
     * Duties paid straight to Released/cleared. Never backwards, never out of On Hold / Cancelled,
     * closed or archived shipments. Returns [new stage or null, why it stopped short or null].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function syncFromCustoms(Shipment $shipment, User $actor): array
    {
        $consistency = app(ShipmentConsistencyService::class);
        $customs = $consistency->customsFor($shipment);
        $current = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
        if (! $customs || ! $current || $shipment->archived_at !== null || (bool) $current->is_closed || $this->isException($current)) {
            return [null, null];
        }
        $targetName = $consistency->furthestSupportedStage($customs);
        if ($targetName === 'Examination' && $customs->examination_lane === 'green') {
            $targetName = 'Duties paid';   // green lane: no physical examination
        }
        $note = null;
        // The cargo must have arrived to be examined or released
        if (in_array($targetName, ['Examination', 'Released/cleared'], true) && ! $shipment->actual_arrival_date) {
            $note = $targetName.' also needs the Actual Arrival date (set it with Update Status).';
            $targetName = 'Duties paid';
        }
        if ($targetName === 'Released/cleared' && $consistency->releaseBlocked($targetName, $consistency->unpaidBalance($shipment))) {
            $note = 'Released/cleared waits until the bills are paid.';
            $targetName = $customs->examination_lane === 'green' ? 'Duties paid' : 'Examination';
        }
        $target = $targetName ? ShipmentStage::query()->where('stage_name', $targetName)->first() : null;
        if (! $target || (int) $target->sort_order <= (int) $current->sort_order) {
            return [null, $note];
        }

        $at = CarbonImmutable::now();
        $lastAt = ShipmentStatusUpdate::query()->where('shipment_id', $shipment->shipment_id)->max('updated_at');
        if ($lastAt && CarbonImmutable::parse($lastAt)->gt($at)) {
            $at = CarbonImmutable::parse($lastAt);
        }
        $this->applyChange($shipment, $current, $target, $actor, $at, 'Set from customs details.', 'Set from customs details.', false);

        return [$target->stage_name, $note];
    }

    /**
     * The stage a delivery status asks for: In transit -> Trucking, Delivered -> Delivered.
     * A cancelled delivery never cancels the shipment (only an administrator cancels shipments).
     *
     * @return array{0: ?ShipmentStage, 1: ?ShipmentStage}
     */
    private function deliveryTarget(Shipment $shipment, string $deliveryStatus): array
    {
        $targetName = match ($deliveryStatus) { 'in_transit' => 'Trucking', 'delivered' => 'Delivered', default => null };
        if ($targetName === null || $shipment->archived_at !== null || $shipment->current_status === $targetName) {
            return [null, null];
        }

        return [ShipmentStage::query()->where('stage_name', $shipment->current_status)->first(), ShipmentStage::query()->where('stage_name', $targetName)->first()];
    }

    /**
     * Why a delivery status cannot move the shipment yet (the same rules as Update Status):
     * customs release, the actual arrival, and paid bills when release requires payment.
     * Null when the move is allowed, or when the delivery status moves nothing.
     */
    public function deliveryBlocker(Shipment $shipment, string $deliveryStatus): ?string
    {
        [$current, $target] = $this->deliveryTarget($shipment, $deliveryStatus);
        if (! $current || ! $target || (bool) $current->is_closed || (int) $target->sort_order <= (int) $current->sort_order && ! $this->isException($current)) {
            return null;
        }
        $consistency = app(ShipmentConsistencyService::class);
        $needs = $consistency->missingFor($target->stage_name, $consistency->customsFor($shipment), (string) $shipment->mode);
        if (! $shipment->actual_arrival_date && $consistency->arrivalRequiredFor($target)) {
            $needs[] = 'the Actual Arrival date';
        }
        if ($consistency->releaseBlocked($target->stage_name, $consistency->unpaidBalance($shipment))) {
            $needs[] = 'bills paid';
        }
        array_push($needs, ...$consistency->deliveryMissingFor($target->stage_name, $shipment));

        return $needs === [] ? null : 'The shipment stays at '.$shipment->current_status.': '.$target->stage_name.' needs '.implode(', ', $needs).'.';
    }

    public function updateStatus(ShipmentStage $stage, bool $correction): string
    {
        if ($correction) {
            return 'Correction';
        }

        return match ($this->groupKey((string) $stage->stage_group)) {
            'delivered' => 'Completed', 'hold' => 'On hold', 'cancelled' => 'Cancelled', default => 'In progress',
        };
    }

    /**
     * The bill a shipment's transaction should point to: its first bill, matching how
     * BillingController links the first bill created for a shipment. Null when it has none.
     */
    public function firstBillId(Shipment $shipment): ?int
    {
        $id = Billing::query()->where('shipment_id', $shipment->shipment_id)->where('client_id', $shipment->client_id)
            ->orderBy('billing_id')->value('billing_id');

        return $id === null ? null : (int) $id;
    }

    public function transactionStatus(?ShipmentStage $stage, string $current): string
    {
        if (! $stage) {
            return $current;
        }

        return match ($this->groupKey((string) $stage->stage_group)) {
            'pending' => 'pending', 'delivered' => 'completed',
            'hold', 'cancelled' => $current === 'completed' ? 'ongoing' : $current, default => 'ongoing',
        };
    }

    public function modalState(Shipment $shipment, string $role): array
    {
        $stages = ShipmentStage::query()->orderBy('sort_order')->orderBy('stage_id')->get();
        $current = $stages->firstWhere('stage_name', $shipment->current_status)
            ?? new ShipmentStage(['stage_name' => (string) $shipment->current_status, 'stage_group' => '', 'sort_order' => 0, 'is_closed' => 0]);
        $position = $this->position($shipment, $current, $stages);
        // Connection rules (ShipmentConsistencyService): customs stages need their Customs Details,
        // and release stages show (or are blocked by) an unpaid balance. Corrections ignore "needs".
        $consistency = app(ShipmentConsistencyService::class);
        $customs = $consistency->customsFor($shipment);
        $unpaid = $consistency->unpaidBalance($shipment);
        $map = function (ShipmentStage $stage) use ($consistency, $customs, $unpaid, $shipment): array {
            $customsNeeds = $consistency->missingFor($stage->stage_name, $customs, (string) $shipment->mode);
            $deliveryNeeds = $consistency->deliveryMissingFor($stage->stage_name, $shipment);

            return [
            'name' => $stage->stage_name, 'group' => $stage->stage_group,
            'exception' => $this->isException($stage),
            'confirm' => ! $this->isException($stage) && (bool) $stage->is_closed,
            'helper' => $stage->stage_name === 'Trucking' ? 'delivery' : ($this->groupKey((string) $stage->stage_group) === 'customs' ? 'customs' : ''),
            'needs' => [
                ...$customsNeeds,
                ...($consistency->releaseBlocked($stage->stage_name, $unpaid) ? ['bills paid (₱'.number_format($unpaid, 2).' unpaid)'] : []),
                ...array_map(fn (string $item): string => $item.' in Delivery Information', $deliveryNeeds),
            ],
            // where the "needs" link points: customs first, then delivery
            'needsLink' => $customsNeeds === [] && $deliveryNeeds !== [] ? 'delivery' : 'customs',
            'warning' => $consistency->releaseWarning($stage->stage_name, $unpaid),
            'arrival' => $consistency->arrivalRequiredFor($stage),
            ];
        };
        $allowed = fn (bool $correction): array => $stages->filter(fn (ShipmentStage $stage): bool => $this->isAllowed($stage, $current, $position, $role, $correction))->map($map)->values()->all();
        $label = $current->stage_name === 'Trucking' ? 'Trucking' : ($current->stage_group ?: $current->stage_name);

        return [
            'shipmentId' => (int) $shipment->shipment_id, 'reference' => (string) $shipment->reference_no,
            'current' => ['name' => $current->stage_name, 'group' => $current->stage_group, 'closed' => (bool) $current->is_closed, 'badgeLabel' => $label, 'badgeClass' => $this->groupClass((string) $current->stage_group)],
            'stages' => $allowed(false), 'canCorrect' => $role === 'admin',
            'correctionStages' => $role === 'admin' ? $allowed(true) : [],
            'now' => now()->format('Y-m-d H:i:s'), 'lastAt' => $shipment->statusUpdates()->max('updated_at'),
            'actualArrival' => $shipment->actual_arrival_date?->format('Y-m-d'),
            'links' => ['billing' => route('billing.index', ['q' => $shipment->reference_no]), 'customs' => route('shipments.show', $shipment->shipment_id).'#customs', 'delivery' => route('shipments.show', $shipment->shipment_id).'#delivery'],
        ];
    }

    /** @return array{items: list<array{kind: string, label: string, state: string, date: string, short: string, note: string, colour: string}>, lastUpdated: mixed} */
    public function progress(Shipment $shipment): array
    {
        $stages = ShipmentStage::query()->orderBy('sort_order')->get();
        $groups = $stages->filter(fn (ShipmentStage $stage): bool => ! in_array($stage->stage_group, ['On Hold', 'Cancelled'], true))
            ->groupBy('stage_group')->map(fn ($rows, string $label): array => ['label' => $label, 'stages' => $rows->pluck('stage_name')->all()])->values();
        $updates = $shipment->statusUpdates->sortBy(fn ($update): string => ($update->updated_at?->format('Y-m-d H:i:s') ?? '').'-'.$update->update_id)->values();
        $lastUpdated = $updates->last()?->updated_at ?? $shipment->created_at;
        $latestByGroup = [];
        $lastNormalGroup = null;
        foreach ($updates as $update) {
            $group = $update->stageDefinition?->stage_group;
            if ($group && ! in_array($group, ['On Hold', 'Cancelled'], true)) {
                $lastNormalGroup = $group;
            }
            if ($group) {
                $latestByGroup[$group] = $update;
            }
        }
        $currentGroup = $shipment->stage?->stage_group;
        $exceptionGroup = in_array($currentGroup, ['On Hold', 'Cancelled'], true) ? $currentGroup : null;
        if ($exceptionGroup !== null) {
            $currentGroup = $lastNormalGroup ?? $groups->first()['label'] ?? null;
        }
        $currentIndex = $groups->search(fn (array $group): bool => $group['label'] === $currentGroup);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $items = [[
            'kind' => 'created', 'label' => 'Shipment Record Created', 'state' => 'done',
            'date' => $shipment->created_at?->format('M d, Y · g:i A') ?? '—',
            'short' => $shipment->created_at?->format('M d, Y') ?? '—',
            'note' => 'Record created'.(($creator = $shipment->statusUpdates()->with('updatedBy')->orderBy('updated_at')->orderBy('update_id')->first()?->updatedBy?->full_name) ? ' by '.$creator : '').'.', 'colour' => 'blue',
        ]];
        foreach ($groups as $index => $group) {
            $state = $index < $currentIndex || ($index === $currentIndex && $group['label'] === 'Delivered') ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
            $update = $latestByGroup[$group['label']] ?? null;
            $items[] = [
                'kind' => 'stage', 'label' => $group['label'], 'state' => $state,
                'date' => $state === 'upcoming' ? 'Awaiting update' : ($update?->updated_at?->format('M d, Y · g:i A') ?? 'Date not recorded'),
                'short' => $state === 'upcoming' ? 'Awaiting update' : ($update?->updated_at?->format('M d, Y') ?? 'Date not recorded'),
                // a stage not reached (or undone by a correction) shows no old remarks
                'note' => $state === 'upcoming' ? '' : (string) ($update?->remarks ?? ''), 'colour' => 'blue',
            ];
        }
        if ($exceptionGroup) {
            $update = $latestByGroup[$exceptionGroup] ?? null;
            $items[] = [
                'kind' => 'exception', 'label' => $exceptionGroup, 'state' => $exceptionGroup === 'On Hold' ? 'hold' : 'cancelled',
                'date' => $update?->updated_at?->format('M d, Y · g:i A') ?? 'Date not recorded',
                'short' => $update?->updated_at?->format('M d, Y') ?? '', 'note' => (string) ($update?->remarks ?? ''),
                'colour' => $exceptionGroup === 'On Hold' ? 'amber' : 'red',
            ];
        }

        return ['items' => $items, 'lastUpdated' => $lastUpdated];
    }
}
