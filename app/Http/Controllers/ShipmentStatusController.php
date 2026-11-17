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
use App\Models\Transaction;
use App\Models\TransactionHistory;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
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
        $activityLogger = app(ActivityLogger::class);
        $notificationService = app(NotificationService::class);

        try {
            $result = DB::transaction(function () use ($actor, $shipmentId, $data, $correction, $remarks, $at, $arrival, $stageRequested, $stageService, $activityLogger, $notificationService): array {
                $shipment = Shipment::query()->lockForUpdate()->find($shipmentId);
                if (! $shipment || ($actor->role === 'staff' && $shipment->assigned_staff_id !== null && (int) $shipment->assigned_staff_id !== (int) $actor->user_id)) {
                    return ['status' => 404, 'body' => ['error' => 'Shipment not found.']];
                }
                if ($shipment->archived_at !== null) {
                    return ['status' => 409, 'body' => ['error' => 'This shipment is archived. Reopen it before changing its status.']];
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
                if ($target->stage_name === $current->stage_name) {
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
                if ($target->stage_name === 'On Hold' && ! in_array($data['reason'] ?? '', ['Missing documents', 'Client request', 'Payment issue', 'Customs issue', 'Other'], true)) {
                    $errors['reason'] = 'Select a reason for putting the shipment on hold.';
                }
                if ($remarks === '' && $correction) {
                    $errors['remarks'] = 'Remarks are required for every correction.';
                } elseif ($remarks === '' && $stageService->groupKey((string) $target->stage_group) === 'cancelled') {
                    $errors['remarks'] = 'Remarks are required when setting status to '.$target->stage_name.'.';
                }
                if ($errors) {
                    return ['status' => 422, 'body' => ['error' => 'Please fix the highlighted fields.', 'errors' => $errors]];
                }

                $timelineRemarks = $target->stage_name === 'On Hold' ? 'Reason: '.$data['reason'].($remarks !== '' ? ' — '.$remarks : '') : $remarks;
                if (mb_strlen($timelineRemarks) > 255) {
                    return ['status' => 422, 'body' => ['error' => 'Please fix the highlighted fields.', 'errors' => ['remarks' => 'Reason and remarks together can be up to 255 characters.']]];
                }

                $update = new ShipmentStatusUpdate;
                $update->shipment_id = $shipmentId;
                $update->stage = $target->stage_name;
                $update->status = $stageService->updateStatus($target, $correction);
                $update->remarks = $timelineRemarks !== '' ? $timelineRemarks : null;
                $update->updated_by = $actor->user_id;
                $update->updated_at = $at->format('Y-m-d H:i:s');
                $update->save();
                $shipment->current_status = $target->stage_name;
                $shipment->actual_arrival_date = $arrival;
                $shipment->save();

                $transaction = Transaction::query()->where('shipment_id', $shipmentId)->orderByDesc('transaction_id')->lockForUpdate()->first();
                if (! $transaction) {
                    $transaction = Transaction::query()->create(['client_id' => $shipment->client_id, 'shipment_id' => $shipmentId, 'status' => 'pending']);
                }
                $transaction->status = $stageService->transactionStatus($target, (string) $transaction->status);
                $transaction->completed_at = $transaction->status === 'completed' ? $at->format('Y-m-d H:i:s') : null;
                $transaction->save();

                $verb = $correction ? 'Stage corrected from ' : 'Moved from ';
                TransactionHistory::query()->create([
                    'transaction_id' => $transaction->transaction_id, 'event_type' => 'Status update',
                    'description' => mb_substr($verb.$current->stage_name.' to '.$target->stage_name.($timelineRemarks !== '' ? ' — '.$timelineRemarks : ''), 0, 255),
                    'performed_by' => $actor->user_id,
                ]);
                $activityLogger->record($actor, 'Shipment Update', 'Status of '.$shipment->reference_no.($correction ? ' corrected from '.$current->stage_name.' to ' : ' updated to ').$target->stage_name.($target->stage_name === 'On Hold' ? ' ('.$data['reason'].')' : ''));
                $notificationService->forShipment($shipment, $actor, 'status', 'Shipment status updated', $shipment->reference_no.' is now '.$target->stage_name.($remarks !== '' ? ': '.$remarks : ''));

                return ['status' => 200, 'body' => ['ok' => true, 'message' => $correction ? 'Status corrected' : 'Status updated', 'detail' => $shipment->reference_no.' is now '.$target->stage_name]];
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'The status could not be updated. Please try again.'], 500);
        }

        return response()->json($result['body'], $result['status']);
    }

}
