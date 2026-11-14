<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\ShipmentStatusUpdate;
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

    public function updateStatus(ShipmentStage $stage, bool $correction): string
    {
        if ($correction) {
            return 'Correction';
        }

        return match ($this->groupKey((string) $stage->stage_group)) {
            'delivered' => 'Completed', 'hold' => 'On hold', 'cancelled' => 'Cancelled', default => 'In progress',
        };
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
        $map = fn (ShipmentStage $stage): array => [
            'name' => $stage->stage_name, 'group' => $stage->stage_group,
            'exception' => $this->isException($stage),
            'confirm' => ! $this->isException($stage) && (bool) $stage->is_closed,
            'helper' => $stage->stage_name === 'Trucking' ? 'delivery' : ($this->groupKey((string) $stage->stage_group) === 'customs' ? 'customs' : ''),
        ];
        $allowed = fn (bool $correction): array => $stages->filter(fn (ShipmentStage $stage): bool => $this->isAllowed($stage, $current, $position, $role, $correction))->map($map)->values()->all();
        $label = $current->stage_name === 'Trucking' ? 'Trucking' : ($current->stage_group ?: $current->stage_name);

        return [
            'shipmentId' => (int) $shipment->shipment_id, 'reference' => (string) $shipment->reference_no,
            'current' => ['name' => $current->stage_name, 'group' => $current->stage_group, 'closed' => (bool) $current->is_closed, 'badgeLabel' => $label, 'badgeClass' => $this->groupClass((string) $current->stage_group)],
            'stages' => $allowed(false), 'canCorrect' => $role === 'admin',
            'correctionStages' => $role === 'admin' ? $allowed(true) : [],
            'now' => now()->format('Y-m-d H:i:s'), 'lastAt' => $shipment->statusUpdates()->max('updated_at'),
            'actualArrival' => $shipment->actual_arrival_date?->format('Y-m-d'),
            'links' => ['customs' => route('shipments.show', $shipment->shipment_id).'#customs', 'delivery' => route('shipments.show', $shipment->shipment_id).'#delivery'],
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
            'note' => 'Record created'.($shipment->assignedStaff?->full_name ? ' by '.$shipment->assignedStaff->full_name : '').'.', 'colour' => 'blue',
        ]];
        foreach ($groups as $index => $group) {
            $state = $index < $currentIndex || ($index === $currentIndex && $group['label'] === 'Delivered') ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
            $update = $latestByGroup[$group['label']] ?? null;
            $items[] = [
                'kind' => 'stage', 'label' => $group['label'], 'state' => $state,
                'date' => $state === 'upcoming' ? 'Awaiting update' : ($update?->updated_at?->format('M d, Y · g:i A') ?? 'Date not recorded'),
                'short' => $state === 'upcoming' ? 'Awaiting update' : ($update?->updated_at?->format('M d, Y') ?? 'Date not recorded'),
                'note' => (string) ($update?->remarks ?? ''), 'colour' => 'blue',
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
