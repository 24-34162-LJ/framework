<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentStatusController.php
 * Feature: Shipments
 * Purpose: "Update Status" modal: moves a shipment to its next stage and notifies the client.
 */

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\ShipmentStatusUpdate;
use App\Services\ShipmentStageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShipmentStatusController extends Controller
{
    public function modalState(Shipment $shipment, string $role): array
    {
        return app(ShipmentStageService::class)->modalState($shipment, $role);
    }

    public function update(Request $request, int $shipmentId): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'stage' => ['required', 'string', 'max:100'], 'remarks' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date_format:Y-m-d\\TH:i'], 'actual_arrival_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:50'], 'correction' => ['nullable', 'in:0,1'],
            'expected_current' => ['nullable', 'string', 'max:100'], 'notify' => ['nullable', 'in:0,1'],
        ]);
        $correction = ($data['correction'] ?? '0') === '1';
        if ($correction && $actor->role !== 'admin') {
            return response()->json(['error' => 'Only an administrator can correct a previous stage.'], 403);
        }

        $rawRemarks = str_replace(["\r", "\n"], ' ', (string) ($data['remarks'] ?? ''));
        $rawRemarks = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawRemarks);
        $remarks = trim((string) preg_replace('/\s+/u', ' ', $rawRemarks));
        $at = CarbonImmutable::parse(str_replace('T', ' ', $data['occurred_at']));
        $arrival = $data['actual_arrival_date'] ?? null;
        $stageRequested = trim($data['stage']);
        $stageService = app(ShipmentStageService::class);

        try {
            $result = DB::transaction(function () use ($actor, $shipmentId, $data, $correction, $remarks, $at, $arrival, $stageRequested, $stageService): array {
                $shipment = Shipment::query()->lockForUpdate()->find($shipmentId);
                if (! $shipment || ($actor->role === 'staff' && $shipment->assigned_staff_id !== null && (int) $shipment->assigned_staff_id !== (int) $actor->user_id)) {
                    return ['status' => 404, 'body' => ['error' => 'Shipment not found.']];
                }
                if ($shipment->archived_at !== null) {
                    return ['status' => 409, 'body' => ['error' => 'This shipment is archived. Reopen it before changing its status.']];
                }
                if ($shipment->needsClaimBy($actor)) {
                    return ['status' => 409, 'body' => ['error' => 'Assign this shipment to yourself (Assign to me) before making changes.']];
                }

                $stages = ShipmentStage::query()->orderBy('sort_order')->orderBy('stage_id')->get();
                $current = $stages->firstWhere('stage_name', $shipment->current_status);
                $target = $stages->firstWhere('stage_name', $stageRequested);
                if (! $current) {
                    return ['status' => 422, 'body' => ['error' => 'This shipment has a status that is not in the stage list. Ask an administrator to check it.']];
                }
                if (! $target) {
                    return ['status' => 422, 'body' => ['error' => 'Please fix the highlighted fields.', 'errors' => ['stage' => 'Choose a valid stage.']]];
                }
                if (filled($data['expected_current'] ?? null) && $data['expected_current'] !== $shipment->current_status) {
                    return ['status' => 409, 'body' => ['error' => 'This shipment was just changed to '.$shipment->current_status.'. Reload the page and check the new status.']];
                }

                $now = CarbonImmutable::now();
                $errors = [];
                if ($arrival && $arrival > $now->toDateString()) {
                    $errors['actual_arrival_date'] = 'Actual arrival cannot be in the future.';
                }
                if ($at->gt($now->addMinutes(5))) {
                    $errors['occurred_at'] = 'The date and time cannot be in the future.';
                }
                $lastAt = ShipmentStatusUpdate::query()->where('shipment_id', $shipmentId)->max('updated_at');
                if ($lastAt && $at->format('Y-m-d H:i') < CarbonImmutable::parse($lastAt)->format('Y-m-d H:i')) {
                    $errors['occurred_at'] = 'The date and time cannot be earlier than the last update ('.CarbonImmutable::parse($lastAt)->format('M d, Y · g:i A').').';
                }
                $position = $stageService->position($shipment, $current, $stages);
                if ($target->stage_name === 'Cancelled' && $actor->role !== 'admin') {
                    $errors['stage'] = 'Only an administrator can cancel a shipment.';
                } elseif ($target->stage_name === $current->stage_name) {
                    $errors['stage'] = 'The shipment is already at '.$target->stage_name.'.';
                } elseif (! $stageService->isAllowed($target, $current, $position, $actor->role, $correction)) {
                    if (! $correction && (bool) $current->is_closed) {
                        $errors['stage'] = $actor->role === 'admin' ? 'This shipment is closed. Turn on “Correct a previous stage” to change it.' : 'This shipment is closed. Only an administrator can correct it.';
                    } elseif (! $stageService->isException($target) && (int) $target->sort_order <= $position) {
                        $errors['stage'] = $actor->role === 'admin' ? 'Moving backwards needs “Correct a previous stage” and remarks.' : 'Staff can only move a shipment forward. Ask an administrator to correct it.';
                    } else {
                        $errors['stage'] = 'That stage is not available from '.$current->stage_name.'.';
                    }
                }
                // Connection rules: a customs stage needs its Customs Details; release may need paid bills.
                // An admin correction (with remarks) can still override, to fix old records.
                $consistency = app(\App\Services\ShipmentConsistencyService::class);
                $unpaid = $consistency->unpaidBalance($shipment);
                if (! $correction && ! isset($errors['stage'])) {
                    if (! $arrival && ! $shipment->actual_arrival_date && $consistency->arrivalRequiredFor($target)) {
                        $errors['actual_arrival_date'] = 'Enter the Actual Arrival date: the cargo must have arrived to be at '.$target->stage_name.'.';
                    }
                    $missing = $consistency->missingFor($target->stage_name, $consistency->customsFor($shipment), (string) $shipment->mode);
                    $deliveryMissing = $consistency->deliveryMissingFor($target->stage_name, $shipment);
                    if ($missing !== []) {
                        $errors['stage'] = 'Before '.$target->stage_name.', add in Customs Details: '.implode(', ', $missing).'.';
                    } elseif ($deliveryMissing !== []) {
                        $errors['stage'] = 'Before '.$target->stage_name.', set up Delivery Information: '.implode(', ', $deliveryMissing).'.';
                    } elseif ($consistency->releaseBlocked($target->stage_name, $unpaid)) {
                        $errors['stage'] = 'Collect payment first: this shipment has ₱'.number_format($unpaid, 2).' unpaid on its bills.';
                    }
                }
                if ($target->stage_name === 'On Hold' && ! in_array($data['reason'] ?? '', ['Missing documents', 'Client request', 'Payment issue', 'Customs issue', 'Other'], true)) {
                    $errors['reason'] = 'Select a reason for putting the shipment on hold.';
                }
                if ($remarks === '' && $correction) {
                    $errors['remarks'] = 'Remarks are required for every correction.';
                } elseif ($remarks === '' && $stageService->groupKey((string) $target->stage_group) === 'cancelled') {
                    $errors['remarks'] = 'Remarks are required when setting status to '.$target->stage_name.'.';
                } elseif (($correction || $stageService->groupKey((string) $target->stage_group) === 'cancelled') && mb_strlen($remarks) < 10) {
                    // a correction or cancellation must explain itself in the audit trail
                    $errors['remarks'] = 'Explain the reason in at least 10 characters (it is kept in the audit trail).';
                }
                if ($errors) {
                    return ['status' => 422, 'body' => ['error' => 'Please fix the highlighted fields.', 'errors' => $errors]];
                }

                $timelineRemarks = $target->stage_name === 'On Hold' ? 'Reason: '.$data['reason'].($remarks !== '' ? ' — '.$remarks : '') : $remarks;
                if (mb_strlen($timelineRemarks) > 255) {
                    return ['status' => 422, 'body' => ['error' => 'Please fix the highlighted fields.', 'errors' => ['remarks' => 'Reason and remarks together can be up to 255 characters.']]];
                }

                $shipment->actual_arrival_date = $arrival;
                // Free storage is counted from arrival: fill an empty First Free Day (it stays editable).
                if ($arrival && ! $shipment->first_free_day) {
                    $shipment->first_free_day = $arrival;
                }
                $stageService->applyChange($shipment, $current, $target, $actor, $at, $timelineRemarks, $remarks, $correction, $data['reason'] ?? null);

                return ['status' => 200, 'body' => ['ok' => true, 'message' => $correction ? 'Status corrected' : 'Status updated', 'detail' => $shipment->reference_no.' is now '.$target->stage_name
                    .(($releaseNote = $consistency->releaseWarning($target->stage_name, $unpaid)) ? '. '.$releaseNote : '')]];
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'The status could not be updated. Please try again.'], 500);
        }

        return response()->json($result['body'], $result['status']);
    }

}
