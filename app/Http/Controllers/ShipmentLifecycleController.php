<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentLifecycleController.php
 * Feature: Shipments
 * Purpose: Archive, cancel and reopen actions on a shipment (its lifecycle).
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShipmentLifecycleController extends Controller
{
    public function update(Request $request, ShipmentStageService $stages, ActivityLogger $activity, NotificationService $notifications): JsonResponse
    {
        $id = $request->integer('shipment_id');
        $action = (string) $request->input('action', '');
        $reason = trim((string) $request->input('reason', ''));
        $remarks = trim((string) $request->input('remarks', ''));
        $role = (string) $request->user()->role;

        if ($id < 1 || ! in_array($action, ['hold', 'cancel', 'archive', 'reopen'], true)) {
            return response()->json(['error' => 'Choose a valid shipment action.'], 422);
        }
        if (mb_strlen($remarks) > 255 || mb_strlen($reason) > 255) {
            return response()->json(['error' => 'Reason and remarks must be 255 characters or fewer.'], 422);
        }
        if (in_array($action, ['cancel', 'hold'], true) && $reason === '') {
            return response()->json(['error' => 'Enter a reason for this action.'], 422);
        }
        if ($role !== 'admin' && in_array($action, ['cancel', 'archive', 'reopen'], true)) {
            return response()->json(['error' => 'Only an administrator can perform this action.'], 403);
        }

        try {
            $result = DB::transaction(function () use ($request, $id, $action, $reason, $remarks, $role, $stages, $activity, $notifications): array {
                $shipment = Shipment::query()->lockForUpdate()->find($id);
                if (! $shipment) {
                    return ['status' => 404, 'body' => ['error' => 'Shipment not found.']];
                }
                if ($role === 'staff' && $shipment->assigned_staff_id && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
                    return ['status' => 403, 'body' => ['error' => 'Only the assigned staff member or an administrator can update this shipment.']];
                }

                $current = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
                if (! $current) {
                    return ['status' => 422, 'body' => ['error' => 'The current shipment status is not recognized.']];
                }

                if ($action === 'archive') {
                    if ($shipment->archived_at) {
                        return ['status' => 409, 'body' => ['error' => 'This shipment is already archived.']];
                    }
                    if (! $current->is_closed) {
                        return ['status' => 422, 'body' => ['error' => 'Only a delivered or cancelled shipment can be archived.']];
                    }
                    $shipment->archived_at = now();
                    $shipment->archived_by = $request->user()->user_id;
                    $shipment->archive_reason = $remarks !== '' ? $remarks : null;
                    $shipment->save();
                    $activity->record($request->user(), 'Shipment Update', $shipment->reference_no.' archived'.($remarks !== '' ? ': '.$remarks : ''));
                    $notifications->forShipment($shipment, $request->user(), 'archive', 'Shipment archived', $shipment->reference_no.' was archived.');

                    return ['status' => 200, 'body' => ['ok' => true, 'message' => 'Shipment archived.']];
                }

                if ($action === 'reopen') {
                    $wasArchived = (bool) $shipment->archived_at;
                    if (! $wasArchived && ! in_array($shipment->current_status, ['On Hold', 'Cancelled', 'Delivered'], true)) {
                        return ['status' => 409, 'body' => ['error' => 'This shipment is already active.']];
                    }
                    $previousStage = ShipmentStatusUpdate::query()->where('shipment_id', $id)
                        ->whereNotIn('stage', ['On Hold', 'Cancelled', 'Delivered'])
                        ->orderByDesc('updated_at')->orderByDesc('update_id')->value('stage') ?: 'Documents received';
                    $resume = ShipmentStage::query()->where('stage_name', $previousStage)->first();
                    if (! $resume || $resume->is_closed) {
                        $resume = ShipmentStage::query()->where('stage_name', 'Documents received')->firstOrFail();
                    }
                    $oldStatus = (string) $shipment->current_status;
                    $shipment->archived_at = null;
                    $shipment->archived_by = null;
                    $shipment->archive_reason = null;
                    $shipment->current_status = $resume->stage_name;
                    $shipment->save();

                    if ($oldStatus !== $resume->stage_name) {
                        ShipmentStatusUpdate::query()->create([
                            'shipment_id' => $id,
                            'stage' => $resume->stage_name,
                            'status' => 'ongoing',
                            'remarks' => $remarks !== '' ? 'Reopened: '.$remarks : 'Reopened by administrator.',
                            'updated_by' => $request->user()->user_id,
                        ]);
                        $transaction = Transaction::query()->where('shipment_id', $id)->orderByDesc('transaction_id')->lockForUpdate()->first();
                        if ($transaction) {
                            $transaction->status = $stages->transactionStatus($resume, (string) $transaction->status);
                            $transaction->completed_at = null;
                            $transaction->save();
                            TransactionHistory::query()->create([
                                'transaction_id' => $transaction->transaction_id,
                                'event_type' => 'Shipment reopened',
                                'description' => mb_substr($shipment->reference_no.' reopened at '.$resume->stage_name, 0, 255),
                                'performed_by' => $request->user()->user_id,
                            ]);
                        }
                    }
                    $activity->record($request->user(), 'Shipment Update', $shipment->reference_no.' reopened at '.$resume->stage_name.($wasArchived ? ' and restored from archive' : ''));
                    $notifications->forShipment($shipment, $request->user(), 'status', 'Shipment reopened', $shipment->reference_no.' was reopened at '.$resume->stage_name.'.');

                    return ['status' => 200, 'body' => ['ok' => true, 'message' => 'Shipment reopened at '.$resume->stage_name.'.']];
                }

                if ($shipment->archived_at || $current->is_closed) {
                    return ['status' => 409, 'body' => ['error' => 'Reopen this shipment before changing its status.']];
                }
                if ($action === 'hold' && $shipment->current_status === 'On Hold') {
                    return ['status' => 409, 'body' => ['error' => 'This shipment is already on hold.']];
                }

                $targetName = $action === 'hold' ? 'On Hold' : 'Cancelled';
                $target = ShipmentStage::query()->where('stage_name', $targetName)->firstOrFail();
                $timelineRemark = ($action === 'hold' ? 'Reason: ' : 'Cancellation reason: ').$reason.($remarks !== '' ? ' — '.$remarks : '');
                ShipmentStatusUpdate::query()->create([
                    'shipment_id' => $id,
                    'stage' => $targetName,
                    'status' => $stages->updateStatus($target, false),
                    'remarks' => mb_substr($timelineRemark, 0, 255),
                    'updated_by' => $request->user()->user_id,
                ]);
                $shipment->current_status = $targetName;
                $shipment->save();
                $transaction = Transaction::query()->where('shipment_id', $id)->orderByDesc('transaction_id')->lockForUpdate()->first();
                $transactionStatus = $targetName === 'Cancelled' ? 'completed' : 'ongoing';
                if ($transaction) {
                    $transactionStatus = $stages->transactionStatus($target, (string) $transaction->status);
                    $transaction->status = $transactionStatus;
                    $transaction->completed_at = $transactionStatus === 'completed' ? now() : null;
                    $transaction->save();
                } else {
                    $transaction = Transaction::query()->create([
                        'client_id' => $shipment->client_id,
                        'shipment_id' => $id,
                        'status' => $transactionStatus,
                        'completed_at' => $transactionStatus === 'completed' ? now() : null,
                    ]);
                }
                TransactionHistory::query()->create([
                    'transaction_id' => $transaction->transaction_id,
                    'event_type' => 'Status update',
                    'description' => mb_substr($shipment->reference_no.' changed to '.$targetName.': '.$timelineRemark, 0, 255),
                    'performed_by' => $request->user()->user_id,
                ]);
                $activity->record($request->user(), 'Shipment Update', $shipment->reference_no.' changed to '.$targetName.' ('.$reason.')');
                $notifications->forShipment($shipment, $request->user(), 'status', 'Shipment '.mb_strtolower($targetName), $shipment->reference_no.' is now '.$targetName.': '.$reason);

                return ['status' => 200, 'body' => ['ok' => true, 'message' => $shipment->reference_no.' is now '.$targetName.'.']];
            }, 3);

            return response()->json($result['body'], $result['status'])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'The shipment action could not be saved. Please try again.'], 500);
        }
    }
}
