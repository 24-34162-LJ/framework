<?php

/*
 * FILE PURPOSE
 * File   : app/Services/ShipmentConsistencyService.php
 * Feature: Shipments - consistency rules
 * Purpose: One place for the rules that connect shipment status, customs details, bills and
 *          payments: what each customs stage needs, unpaid balance before release, bill charges
 *          that must match customs, and the checklist shown on the shipment page and in the report.
 */

namespace App\Services;

use App\Models\CustomsProcessing;
use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShipmentConsistencyService
{
    /**
     * Customs stages in order, each with the Customs Details facts it needs. A stage also needs
     * everything the stages before it need (you cannot be at "Duties paid" without a declaration).
     *
     * @var array<string, array<string, string>> stage => [field => label shown to the user]
     */
    public const CUSTOMS_REQUIREMENTS = [
        'Declaration filed' => ['entry_no' => 'Entry No.', 'lodgement_date' => 'Lodgement date'],
        'Assessment' => ['assessment_status' => 'Assessment status (Assessed or Paid)'],
        'Duties paid' => ['assessment_paid' => 'Assessment status Paid', 'duties_paid_date' => 'Duties paid date'],
        'Examination' => ['examination_lane' => 'Examination lane'],
        'Released/cleared' => ['clearance_date' => 'Clearance date'],
    ];

    /** Stages where cargo leaves customs control: payment is checked before these. */
    public const RELEASE_STAGES = ['Released/cleared', 'Trucking'];

    /** Stages where the cargo is still at the port, so free storage time matters. */
    public const AT_PORT_STAGES = ['Documents received', 'Declaration filed', 'Assessment', 'Duties paid', 'Examination', 'On Hold'];

    /** From this stage on the cargo must have physically arrived (it is examined or released). */
    public const ARRIVAL_REQUIRED_FROM = 'Examination';

    // ---------- Key dates ----------

    /** Whether moving to $stage needs an Actual Arrival date (Examination and every later normal stage). */
    public function arrivalRequiredFor(ShipmentStage $stage): bool
    {
        $from = (int) ShipmentStage::query()->where('stage_name', self::ARRIVAL_REQUIRED_FROM)->value('sort_order');

        return $from > 0 && (int) $stage->sort_order >= $from && ! in_array($stage->stage_name, ['On Hold', 'Cancelled'], true);
    }

    /** The day storage charges start: First Free Day + the free days (null without a First Free Day). */
    public function storageStartsOn(Shipment $shipment): ?CarbonImmutable
    {
        return $shipment->first_free_day ? CarbonImmutable::parse($shipment->first_free_day)->startOfDay()->addDays((int) config('shipments.free_days', 5)) : null;
    }

    /**
     * Storage warning while the cargo is still at the port: free storage ends soon, or charges
     * already started. Null when there is nothing to warn about.
     *
     * @return array{starts: CarbonImmutable, days: int, started: bool, text: string}|null
     */
    public function storageAlert(Shipment $shipment, ?CarbonImmutable $today = null): ?array
    {
        $starts = $this->storageStartsOn($shipment);
        if (! $starts || ! in_array($shipment->current_status, self::AT_PORT_STAGES, true) || $shipment->archived_at) {
            return null;
        }
        $today ??= CarbonImmutable::today();
        $days = (int) $today->diffInDays($starts, false);
        if ($days > (int) config('shipments.storage_warning_days', 2)) {
            return null;
        }
        $started = $days <= 0;
        $text = $started
            ? 'Storage charges have applied since '.$starts->format('M d, Y').' ('.max(1, -$days + 1).' '.(-$days + 1 === 1 ? 'day' : 'days').'). The cargo is not released yet.'
            : 'Free storage ends in '.$days.' '.($days === 1 ? 'day' : 'days').'. Storage charges start '.$starts->format('M d, Y').' and the cargo is not released yet.';

        return ['starts' => $starts, 'days' => $days, 'started' => $started, 'text' => $text];
    }

    /**
     * Key Dates that disagree: arrival missing or far from the ETA, dates before the arrival,
     * a pickup after the due date, or a due date passed before delivery.
     *
     * @return list<array{key: string, issue: string, detail: string}>
     */
    public function keyDateIssues(Shipment $shipment, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $issues = [];
        $stage = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
        $arrival = $shipment->actual_arrival_date ? CarbonImmutable::parse($shipment->actual_arrival_date)->startOfDay() : null;
        $eta = $shipment->eta_date ? CarbonImmutable::parse($shipment->eta_date)->startOfDay() : null;
        $closed = in_array($shipment->current_status, ['Delivered', 'Cancelled'], true);

        if (! $arrival && $stage && $this->arrivalRequiredFor($stage)) {
            $issues[] = ['key' => 'arrival_missing', 'issue' => 'Actual arrival not recorded',
                'detail' => 'The shipment is at '.$shipment->current_status.', so the cargo has arrived, but no Actual Arrival date is saved.'];
        }
        $tolerance = (int) config('shipments.arrival_eta_tolerance_days', 3);
        if ($arrival && $eta && abs((int) $eta->diffInDays($arrival, false)) >= $tolerance) {
            $gap = (int) $eta->diffInDays($arrival, false);
            $issues[] = ['key' => 'arrival_eta', 'issue' => 'Actual arrival is far from the ETA',
                'detail' => 'Arrived '.$arrival->format('M d, Y').', '.abs($gap).' days '.($gap > 0 ? 'after' : 'before').' the ETA ('.$eta->format('M d, Y').'). Check the ETA or the arrival date.'];
        }
        if ($arrival && $shipment->first_free_day && CarbonImmutable::parse($shipment->first_free_day)->lt($arrival)) {
            $issues[] = ['key' => 'free_day_order', 'issue' => 'First Free Day is before the arrival',
                'detail' => 'First Free Day '.CarbonImmutable::parse($shipment->first_free_day)->format('M d, Y').' is before the Actual Arrival '.$arrival->format('M d, Y').'.'];
        }
        $due = $shipment->delivery_due_date ? CarbonImmutable::parse($shipment->delivery_due_date)->startOfDay() : null;
        if ($arrival && $due && $due->lt($arrival)) {
            $issues[] = ['key' => 'due_order', 'issue' => 'Delivery Due Date is before the arrival',
                'detail' => 'Delivery Due Date '.$due->format('M d, Y').' is before the Actual Arrival '.$arrival->format('M d, Y').'.'];
        }
        if ($storage = $this->storageAlert($shipment, $today)) {
            $issues[] = ['key' => 'storage', 'issue' => $storage['started'] ? 'Storage charges are running' : 'Free storage ending soon', 'detail' => $storage['text']];
        }
        if ($due && ! $closed) {
            $scheduled = DB::table('deliveries')->where('shipment_id', $shipment->shipment_id)->orderByDesc('delivery_id')->value('scheduled_date');
            if ($scheduled && CarbonImmutable::parse($scheduled)->gt($due)) {
                $issues[] = ['key' => 'delivery_schedule', 'issue' => 'Pickup is scheduled after the Delivery Due Date',
                    'detail' => 'Pickup is scheduled '.CarbonImmutable::parse($scheduled)->format('M d, Y').', after the Delivery Due Date '.$due->format('M d, Y').'.'];
            }
            if ($due->lt($today)) {
                $issues[] = ['key' => 'delivery_overdue', 'issue' => 'Delivery is overdue',
                    'detail' => 'The Delivery Due Date was '.$due->format('M d, Y').' and the shipment is not delivered yet.'];
            }
        }

        return $issues;
    }

    public function customsFor(Shipment $shipment): ?CustomsProcessing
    {
        return CustomsProcessing::query()->where('shipment_id', $shipment->shipment_id)
            ->orderByDesc('updated_at')->orderByDesc('customs_id')->first();
    }

    /** Stages after customs: cargo can only leave the port once customs released it. */
    public const AFTER_CUSTOMS_STAGES = ['Trucking', 'Delivered'];

    /**
     * What is missing in Customs Details before a shipment may be at $stageName.
     * Trucking and Delivered need the full release record, except for land shipments ($mode
     * "land"), which may not pass through customs at all. Without a $mode only customs stages
     * are checked.
     *
     * @return list<string> labels of the missing facts, empty when the stage is allowed
     */
    public function missingFor(string $stageName, ?CustomsProcessing $customs, ?string $mode = null): array
    {
        if (in_array($stageName, self::AFTER_CUSTOMS_STAGES, true) && $mode !== null && $mode !== 'land') {
            $stageName = 'Released/cleared';
        }
        if (! array_key_exists($stageName, self::CUSTOMS_REQUIREMENTS)) {
            return [];
        }
        $missing = [];
        foreach (self::CUSTOMS_REQUIREMENTS as $stage => $fields) {
            foreach ($fields as $field => $label) {
                if (! $this->has($customs, $field)) {
                    $missing[] = $label;
                }
            }
            if ($stage === $stageName) {
                break;
            }
        }

        return $missing;
    }

    /** The furthest customs stage the saved Customs Details support (null when none). */
    public function furthestSupportedStage(?CustomsProcessing $customs): ?string
    {
        $reached = null;
        foreach (array_keys(self::CUSTOMS_REQUIREMENTS) as $stage) {
            if ($this->missingFor($stage, $customs) !== []) {
                break;
            }
            $reached = $stage;
        }

        return $reached;
    }

    private function has(?CustomsProcessing $customs, string $field): bool
    {
        if (! $customs) {
            return false;
        }
        $value = $customs->getAttribute($field);
        if ($field === 'assessment_status') {
            return in_array($value, ['assessed', 'paid'], true);
        }
        if ($field === 'assessment_paid') {
            return $customs->getAttribute('assessment_status') === 'paid';
        }

        return $value !== null && trim((string) ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)) !== '';
    }

    /** Unpaid balance across all bills of the shipment, from verified payments only. */
    public function unpaidBalance(Shipment $shipment): float
    {
        $balance = DB::table('v_billing_balance')->where('shipment_id', $shipment->shipment_id)
            ->where('client_id', $shipment->client_id)->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS due')->value('due');

        return round((float) $balance, 2);
    }

    /** Whether moving to $stageName must be blocked (not just warned) for an unpaid balance. */
    public function releaseBlocked(string $stageName, float $unpaid): bool
    {
        return in_array($stageName, self::RELEASE_STAGES, true) && $unpaid > 0 && (bool) config('billing.release_requires_payment', false);
    }

    public function releaseWarning(string $stageName, float $unpaid): ?string
    {
        if (! in_array($stageName, self::RELEASE_STAGES, true) || $unpaid <= 0) {
            return null;
        }

        return 'This shipment still has ₱'.number_format($unpaid, 2).' unpaid on its bills.';
    }

    /**
     * Bill charges that disagree with Customs Details: a lane charge for a different (or unset)
     * lane, or assessed duties and taxes that are not on any bill.
     *
     * @return list<string>
     */
    public function billWarnings(Shipment $shipment, ?CustomsProcessing $customs = null): array
    {
        $customs ??= $this->customsFor($shipment);
        $charges = DB::table('billing_items as i')->join('billings as b', 'b.billing_id', '=', 'i.billing_id')
            ->where('b.shipment_id', $shipment->shipment_id)->where('b.client_id', $shipment->client_id)
            ->pluck('i.charge_type')->map(fn ($c) => trim((string) $c));
        $warnings = [];
        $lane = $customs?->examination_lane ? mb_strtolower((string) $customs->examination_lane) : null;
        foreach ($charges->unique() as $charge) {
            if (! preg_match('/\b(green|yellow|orange|red)\s+lane\b/i', $charge, $m)) {
                continue;
            }
            $charged = mb_strtolower($m[1]);
            if ($lane === null) {
                $warnings[] = 'A bill charges "'.$charge.'", but no examination lane is set in Customs Details.';
            } elseif ($charged !== $lane) {
                $warnings[] = 'A bill charges "'.$charge.'", but Customs Details say the lane is '.ucfirst($lane).'.';
            }
        }
        $duties = (float) ($customs?->duties_taxes_amount ?? 0);
        $billedDuties = $this->billedDuties($shipment);
        if ($duties > 0 && $billedDuties === null) {
            $warnings[] = 'Duties and taxes of ₱'.number_format($duties, 2).' are in Customs Details, but no bill has a duties or taxes charge.';
        } elseif ($duties > 0 && (int) round($billedDuties * 100) !== (int) round($duties * 100)) {
            $warnings[] = 'Duties and taxes billed (₱'.number_format($billedDuties, 2).') differ from Customs Details (₱'.number_format($duties, 2).'). Bill the difference or check the assessment.';
        }

        return $warnings;
    }

    /**
     * Contradictions inside one Customs Details record (e.g. saved before the form rules existed).
     *
     * @return list<string>
     */
    public function customsContradictions(?CustomsProcessing $customs): array
    {
        if (! $customs) {
            return [];
        }
        $problems = [];
        $status = (string) $customs->assessment_status;
        if ($customs->duties_paid_date && $status !== 'paid') {
            $problems[] = 'A duties paid date is saved but the Assessment Status is '.ucfirst($status).', not Paid.';
        }
        if ($customs->clearance_date && $status !== 'paid') {
            $problems[] = 'A clearance date is saved before the duties are marked Paid.';
        }
        if ($customs->clearance_date && ! $customs->examination_lane) {
            $problems[] = 'A clearance date is saved but no examination lane is set.';
        }

        return $problems;
    }

    /**
     * What the Delivery Information still needs before the shipment can be at Trucking or
     * Delivered: a delivery that is not cancelled, with a pickup schedule, a drop-off address
     * and a trucker or driver. Empty for other stages, or when everything is set.
     *
     * @return list<string>
     */
    public function deliveryMissingFor(string $stageName, Shipment $shipment): array
    {
        if (! in_array($stageName, self::AFTER_CUSTOMS_STAGES, true)) {
            return [];
        }
        $delivery = DB::table('deliveries')->where('shipment_id', $shipment->shipment_id)
            ->where('status', '!=', 'cancelled')->orderByDesc('delivery_id')->first();
        if (! $delivery) {
            return ['a scheduled delivery (pickup date, delivery address, trucker or driver)'];
        }

        return array_values(array_filter([
            blank($delivery->scheduled_date) ? 'pickup schedule date' : null,
            blank($delivery->delivery_address) ? 'delivery address' : null,
            blank($delivery->trucker) && blank($delivery->driver_name) ? 'trucker or driver' : null,
        ]));
    }

    /**
     * Delivery details that disagree with the shipment: trucking recorded before the shipment
     * reached Trucking, a delivered shipment whose delivery is not delivered, impossible dates.
     *
     * @return list<array{key: string, issue: string, detail: string}>
     */
    public function deliveryIssues(Shipment $shipment, ?CarbonImmutable $today = null): array
    {
        $delivery = DB::table('deliveries')->where('shipment_id', $shipment->shipment_id)->orderByDesc('delivery_id')->first();
        $issues = [];
        // Trucking and Delivered need the Delivery Information that sends the cargo out
        if (in_array($shipment->current_status, self::AFTER_CUSTOMS_STAGES, true)
            && ($missingDelivery = $this->deliveryMissingFor($shipment->current_status, $shipment)) !== []) {
            $issues[] = ['key' => 'delivery_setup', 'issue' => 'Delivery Information is not set up',
                'detail' => $shipment->current_status.' needs in Delivery Information: '.implode(', ', $missingDelivery).'.'];
        }
        if (! $delivery) {
            return $issues;
        }
        $today ??= CarbonImmutable::today();
        $status = (string) $delivery->status;
        $beforeTrucking = in_array($shipment->current_status, array_diff(self::AT_PORT_STAGES, ['On Hold']), true) || $shipment->current_status === 'Released/cleared';
        if ($beforeTrucking && in_array($status, ['in_transit', 'delivered'], true)) {
            $issues[] = ['key' => 'delivery_stage', 'issue' => 'Delivery is ahead of the shipment stage',
                'detail' => 'Delivery details say '.str_replace('_', ' ', $status).', but the shipment is still at '.$shipment->current_status.'.'];
        }
        if ($shipment->current_status === 'Delivered' && $status !== 'delivered') {
            $issues[] = ['key' => 'delivery_stage', 'issue' => 'Delivery is behind the shipment stage',
                'detail' => 'The shipment is Delivered, but its delivery details say '.str_replace('_', ' ', $status).'.'];
        }
        if ($delivery->delivered_date && $status !== 'delivered') {
            $issues[] = ['key' => 'delivery_dates', 'issue' => 'Delivered date on an undelivered delivery',
                'detail' => 'A delivered date ('.CarbonImmutable::parse($delivery->delivered_date)->format('M d, Y').') is saved while the delivery is '.str_replace('_', ' ', $status).'.'];
        }
        if ($delivery->delivered_date && CarbonImmutable::parse($delivery->delivered_date)->gt($today)) {
            $issues[] = ['key' => 'delivery_dates', 'issue' => 'Delivered date is in the future',
                'detail' => 'Delivered date '.CarbonImmutable::parse($delivery->delivered_date)->format('M d, Y').' has not happened yet.'];
        }
        $created = $shipment->created_at ? CarbonImmutable::parse($shipment->created_at)->startOfDay() : null;
        if ($delivery->scheduled_date && $created && CarbonImmutable::parse($delivery->scheduled_date)->lt($created)) {
            $issues[] = ['key' => 'delivery_dates', 'issue' => 'Pickup is scheduled before the shipment existed',
                'detail' => 'Pickup is scheduled '.CarbonImmutable::parse($delivery->scheduled_date)->format('M d, Y').', before the shipment was created ('.$created->format('M d, Y').').'];
        }
        if ($delivery->scheduled_date && $delivery->delivered_date && $delivery->delivered_date < $delivery->scheduled_date) {
            $issues[] = ['key' => 'delivery_dates', 'issue' => 'Delivered before the pickup',
                'detail' => 'Delivered date is before the pickup schedule.'];
        }

        return $issues;
    }

    /** Documents each stage needs (cumulative, like the customs facts). Catalog keys from ShipmentDocumentService. */
    public const DOCUMENT_REQUIREMENTS = [
        'Declaration filed' => ['bl', 'invoice', 'packing'],
        'Assessment' => ['entry'],
        'Duties paid' => ['assessment'],
        'Released/cleared' => ['duties'],
    ];

    /**
     * Required documents not yet received for the stage the shipment is at (land shipments past
     * customs need none).
     *
     * @return list<string> document labels
     */
    public function missingDocuments(Shipment $shipment): array
    {
        $order = ['Declaration filed', 'Assessment', 'Duties paid', 'Examination', 'Released/cleared', 'Trucking', 'Delivered'];
        $position = array_search($shipment->current_status, $order, true);
        if ($position === false || ($shipment->mode === 'land' && $position >= array_search('Trucking', $order, true))) {
            return [];
        }
        $documents = app(ShipmentDocumentService::class);
        $received = collect($documents->checklist(\App\Models\ShipmentDocument::query()->where('shipment_id', $shipment->shipment_id)->whereNull('superseded_at')->get())['slots'] ?? [])
            ->filter(fn (array $slot) => $slot['received'])->pluck('key')->all();
        $catalog = $documents->catalog();
        $missing = [];
        foreach (self::DOCUMENT_REQUIREMENTS as $stage => $keys) {
            if (array_search($stage, $order, true) > $position) {
                break;
            }
            foreach ($keys as $key) {
                if (! in_array($key, $received, true)) {
                    $missing[] = $catalog[$key]['label'] ?? $key;
                }
            }
        }

        return $missing;
    }

    /** Total of duties / taxes charges on the shipment's bills; null when none is billed. */
    public function billedDuties(Shipment $shipment): ?float
    {
        $rows = DB::table('billing_items as i')->join('billings as b', 'b.billing_id', '=', 'i.billing_id')
            ->where('b.shipment_id', $shipment->shipment_id)->where('b.client_id', $shipment->client_id)->get(['i.charge_type', 'i.amount'])
            ->filter(fn ($row) => preg_match('/dut(y|ies)|tax/i', (string) $row->charge_type));

        return $rows->isEmpty() ? null : round((float) $rows->sum('amount'), 2);
    }

    /**
     * Duties assessed in Customs Details that are not (fully) billed yet: the amount to bill and the
     * link that opens Create Bill pre-filled with it. Null when there is nothing to bill.
     *
     * @return array{amount: float, url: string}|null
     */
    public function unbilledDuties(Shipment $shipment, ?CustomsProcessing $customs = null): ?array
    {
        $customs ??= $this->customsFor($shipment);
        $duties = round((float) ($customs?->duties_taxes_amount ?? 0), 2);
        $remaining = round($duties - (float) ($this->billedDuties($shipment) ?? 0), 2);
        if ($duties <= 0 || $remaining <= 0) {
            return null;
        }

        return ['amount' => $remaining, 'url' => route('billing.index', [
            'view' => 'create', 'client_id' => $shipment->client_id, 'shipment_id' => $shipment->shipment_id, 'duties' => 1,
        ])];
    }

    /**
     * The checklist for one shipment (shipment page panel and the all-shipments report).
     *
     * @return list<array{key: string, label: string, issue: string, ok: bool, detail: string, link: ?string}>
     */
    public function checks(Shipment $shipment): array
    {
        $customs = $this->customsFor($shipment);
        $stage = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
        $sort = (int) ($stage?->sort_order ?? 0);
        $exception = in_array($shipment->current_status, ['On Hold', 'Cancelled'], true);
        $releasedSort = (int) ShipmentStage::query()->where('stage_name', 'Released/cleared')->value('sort_order');
        $pastCustoms = ! $exception && $releasedSort > 0 && $sort > $releasedSort && $shipment->current_status !== 'Cancelled';
        $customsLink = route('shipments.show', $shipment->shipment_id).'#customs';

        // 1. Stage vs customs details (stages after customs need the full release record)
        $requiredStage = array_key_exists($shipment->current_status, self::CUSTOMS_REQUIREMENTS) ? $shipment->current_status
            : ($pastCustoms && $shipment->mode !== 'land' ? 'Released/cleared' : null);
        $missing = $requiredStage ? $this->missingFor($requiredStage, $customs) : [];
        $checks[] = ['key' => 'stage_customs', 'label' => 'Shipment stage matches Customs Details', 'issue' => 'Stage is ahead of the Customs Details', 'ok' => $missing === [],
            'detail' => $requiredStage === null ? 'Not yet at a customs stage.'
                : ($missing === [] ? 'Customs Details support '.$shipment->current_status.'.' : $shipment->current_status.' needs: '.implode(', ', $missing).'.'),
            'link' => $missing === [] ? null : $customsLink];

        // 2 & 3. Bills vs customs
        $billWarnings = $this->billWarnings($shipment, $customs);
        $laneWarnings = array_values(array_filter($billWarnings, fn ($w) => str_contains($w, 'lane')));
        $dutyWarnings = array_values(array_filter($billWarnings, fn ($w) => str_contains($w, 'Duties and taxes')));
        $checks[] = ['key' => 'bill_lane', 'label' => 'Lane charges match the examination lane', 'issue' => "Lane charges don't match the examination lane", 'ok' => $laneWarnings === [],
            'detail' => $laneWarnings === [] ? 'No lane charge disagrees with Customs Details.' : implode(' ', $laneWarnings), 'link' => $laneWarnings === [] ? null : $customsLink];
        $checks[] = ['key' => 'bill_duties', 'label' => 'Assessed duties and taxes are billed', 'issue' => 'Duties and taxes are not billed correctly', 'ok' => $dutyWarnings === [],
            'detail' => $dutyWarnings === [] ? ($customs?->duties_taxes_amount ? 'A duties or taxes charge is on a bill.' : 'No duties assessed yet.') : $dutyWarnings[0],
            'link' => $dutyWarnings === [] ? null : ($this->unbilledDuties($shipment, $customs)['url'] ?? route('billing.index', ['q' => $shipment->reference_no]))];

        // 4. Paid before release
        $unpaid = $this->unpaidBalance($shipment);
        $released = ! $exception && $releasedSort > 0 && $sort >= $releasedSort;
        $checks[] = ['key' => 'paid_release', 'label' => 'Bills paid before release', 'issue' => 'Released with unpaid bills', 'ok' => ! ($released && $unpaid > 0),
            'detail' => ! $released ? 'Not released yet. Unpaid now: ₱'.number_format($unpaid, 2).'.'
                : ($unpaid > 0 ? 'Released with ₱'.number_format($unpaid, 2).' still unpaid.' : 'All bills are paid.'),
            'link' => $released && $unpaid > 0 ? route('billing.index', ['q' => $shipment->reference_no]) : null];

        // 5. Transaction follows the stage
        $transaction = Transaction::query()->where('shipment_id', $shipment->shipment_id)->orderByDesc('transaction_id')->first();
        $expected = $transaction ? app(ShipmentStageService::class)->transactionStatus($stage, (string) $transaction->status) : null;
        $checks[] = ['key' => 'transaction', 'label' => 'Transaction status follows the stage', 'issue' => "Transaction status doesn't match the stage", 'ok' => ! $transaction || $expected === $transaction->status,
            'detail' => ! $transaction ? 'No transaction yet.' : ($expected === $transaction->status ? 'Transaction is '.ucfirst((string) $transaction->status).'.'
                : 'Transaction is '.ucfirst((string) $transaction->status).' but the stage implies '.ucfirst((string) $expected).'.'),
            'link' => $transaction && $expected !== $transaction->status ? route('transactions.index', ['view' => 'detail', 'id' => $transaction->transaction_id]) : null];

        // 6. Customs Details that contradict themselves
        $customsProblems = $this->customsContradictions($customs);
        if ($customsProblems !== []) {
            $checks[] = ['key' => 'customs_self', 'label' => 'Customs Details', 'issue' => 'Customs Details contradict themselves', 'ok' => false,
                'detail' => implode(' ', $customsProblems), 'link' => $customsLink];
        }

        // 7. Delivery details vs the shipment stage and dates
        foreach ($this->deliveryIssues($shipment) as $deliveryIssue) {
            $checks[] = ['key' => $deliveryIssue['key'], 'label' => 'Delivery details', 'issue' => $deliveryIssue['issue'], 'ok' => false,
                'detail' => $deliveryIssue['detail'], 'link' => route('shipments.show', $shipment->shipment_id).'#delivery'];
        }

        // 8. Required documents for the stage reached
        $missingDocs = $this->missingDocuments($shipment);
        if ($missingDocs !== []) {
            $checks[] = ['key' => 'documents', 'label' => 'Documents', 'issue' => 'Documents missing for this stage', 'ok' => false,
                'detail' => $shipment->current_status.' needs: '.implode(', ', $missingDocs).'.', 'link' => route('shipments.show', $shipment->shipment_id).'#documents'];
        }

        // 9. Key dates (one entry per problem found)
        $editLink = route('shipments.edit', $shipment->shipment_id);
        foreach ($this->keyDateIssues($shipment) as $dateIssue) {
            $link = in_array($dateIssue['key'], ['delivery_schedule', 'delivery_overdue'], true) ? route('shipments.show', $shipment->shipment_id).'#delivery'
                : ($dateIssue['key'] === 'storage' ? route('shipments.show', $shipment->shipment_id).'#customs' : $editLink);
            $checks[] = ['key' => $dateIssue['key'], 'label' => 'Key dates', 'issue' => $dateIssue['issue'], 'ok' => false, 'detail' => $dateIssue['detail'], 'link' => $link];
        }

        return $checks;
    }
}
