<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Staff/DashboardController.php
 * Feature: Staff portal
 * Purpose: Staff dashboard: assigned shipments and the tracking search.
 */

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentStatusUpdate;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $staffId = (int) $request->user()->user_id;
        $assignedOrUnassigned = fn (Builder $query) => $query->where(function (Builder $query) use ($staffId): void {
            $query->where('assigned_staff_id', $staffId)->orWhereNull('assigned_staff_id');
        });

        $totalShipments = Shipment::query()->whereNull('archived_at')->where($assignedOrUnassigned)->count();
        $completedShipments = Shipment::query()->whereNull('archived_at')->where($assignedOrUnassigned)
            ->whereRaw("LOWER(TRIM(current_status)) = 'delivered'")->count();
        $ongoingShipments = $totalShipments - $completedShipments;

        $outstandingAmount = (float) DB::table('v_billing_balance as balance_view')
            ->join('shipments', 'shipments.shipment_id', '=', 'balance_view.shipment_id')
            ->where(function ($query) use ($staffId): void {
                $query->where('shipments.assigned_staff_id', $staffId)->orWhereNull('shipments.assigned_staff_id');
            })
            ->where('balance_view.balance', '>', 0)
            ->sum('balance_view.balance');

        $today = Carbon::today();
        $weekEnd = $today->copy()->addDays(7);
        $needsAttention = Shipment::query()->where('assigned_staff_id', $staffId)->whereNull('archived_at')
            ->whereRaw("LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')")
            ->where(function (Builder $query) use ($today, $weekEnd): void {
                $query->whereBetween('eta_date', [$today->toDateString(), $weekEnd->toDateString()])
                    ->orWhere('delivery_due_date', '<', $today->toDateString())
                    // free storage ending soon (or over) while the cargo is still at the port
                    ->orWhere(fn ($storage) => $storage->whereIn('current_status', \App\Services\ShipmentConsistencyService::AT_PORT_STAGES)
                        ->where('first_free_day', '<=', $today->copy()->addDays((int) config('shipments.storage_warning_days', 2) - (int) config('shipments.free_days', 5))->toDateString()));
            })
            ->orderByRaw('CASE WHEN delivery_due_date < ? THEN 1 ELSE 0 END DESC', [$today->toDateString()])
            ->orderByRaw('COALESCE(delivery_due_date, eta_date) ASC')
            ->limit(8)->get(['shipment_id', 'reference_no', 'current_status', 'eta_date', 'delivery_due_date', 'first_free_day', 'archived_at'])
            ->map(fn (Shipment $shipment): array => [
                'shipment_id' => $shipment->shipment_id,
                'reference_no' => $shipment->reference_no,
                'current_status' => $shipment->current_status,
                'eta_label' => $shipment->eta_date?->format('M d, Y'),
                'is_overdue' => $shipment->delivery_due_date?->lt($today) ?? false,
                'storage' => app(\App\Services\ShipmentConsistencyService::class)->storageAlert($shipment),
            ]);

        $search = trim((string) $request->query('q', ''));
        $shipmentsQuery = Shipment::query()->with(['client', 'stage'])->withMax('statusUpdates', 'updated_at')
            ->whereNull('archived_at')->where($assignedOrUnassigned);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $shipmentsQuery->where(function (Builder $query) use ($like): void {
                $query->where('reference_no', 'like', $like)
                    ->orWhere('bl_awb_no', 'like', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->where('client_name', 'like', $like));
            });
        }
        $shipments = $shipmentsQuery
            ->orderByRaw('COALESCE(status_updates_max_updated_at, created_at) DESC')
            ->orderByDesc('shipment_id')->paginate(8, ['*'], 'sp')->withQueryString();
        $shipments->getCollection()->transform(function (Shipment $shipment): Shipment {
            $info = app(\App\Services\ShipmentStageService::class)->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
            $shipment->dashboard_status_label = $info['label'];
            $shipment->dashboard_status_class = $info['class'];

            return $shipment;
        });

        $transactions = Transaction::query()->with('shipment')
            ->whereHas('shipment', fn (Builder $query) => $query->where(function (Builder $query) use ($staffId): void {
                $query->where('assigned_staff_id', $staffId)->orWhereNull('assigned_staff_id');
            }))
            ->orderByDesc('created_at')->orderByDesc('transaction_id')->limit(5)->get();

        $updates = ShipmentStatusUpdate::query()->with(['shipment', 'stageDefinition'])
            ->whereHas('shipment', fn (Builder $query) => $query->where(function (Builder $query) use ($staffId): void {
                $query->where('assigned_staff_id', $staffId)->orWhereNull('assigned_staff_id');
            }))
            ->orderByDesc('updated_at')->orderByDesc('update_id')->limit(4)->get();
        $updates->transform(function (ShipmentStatusUpdate $update): ShipmentStatusUpdate {
            $info = app(\App\Services\ShipmentStageService::class)->statusInfo($update->stage, $update->stageDefinition?->stage_group);
            $update->dashboard_status_label = $info['label'];
            $update->dashboard_status_class = $info['class'];

            return $update;
        });

        $billingQuery = DB::table('v_billing_balance as balance_view')
            ->join('shipments', 'shipments.shipment_id', '=', 'balance_view.shipment_id')
            ->where(function ($query) use ($staffId): void {
                $query->where('shipments.assigned_staff_id', $staffId)->orWhereNull('shipments.assigned_staff_id');
            });
        $billingCount = (clone $billingQuery)->count();
        $bills = $billingQuery->select('balance_view.billing_no', 'balance_view.total_amount', 'balance_view.amount_paid', 'balance_view.payment_status', 'shipments.reference_no')
            ->orderByDesc('balance_view.billing_id')->paginate(5, ['*'], 'bp')->withQueryString();

        return view('staff.dashboard', compact(
            'totalShipments', 'ongoingShipments', 'completedShipments', 'outstandingAmount', 'needsAttention',
            'search', 'shipments', 'transactions', 'updates', 'billingCount', 'bills'
        ));
    }

    public function track(Request $request): JsonResponse
    {
        $reference = trim((string) $request->query('ref', ''));
        if ($reference === '') {
            return response()->json(['error' => 'Enter a shipment reference number.'], 400);
        }

        $staffId = (int) $request->user()->user_id;
        $shipment = Shipment::query()->with(['client', 'statusUpdates.stageDefinition'])
            ->where(function (Builder $query) use ($reference): void {
                $query->where('reference_no', $reference)->orWhere('bl_awb_no', $reference);
            })
            ->where(fn (Builder $query) => $query->where('assigned_staff_id', $staffId)->orWhereNull('assigned_staff_id'))
            ->first();

        if (! $shipment) {
            return response()->json(['error' => 'No shipment matches "'.$reference.'". Check the number and try again.'], 404);
        }

        $status = app(\App\Services\ShipmentStageService::class)->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
        $fields = [
            'Client' => $shipment->client?->client_name,
            'Type' => ucfirst((string) $shipment->transaction_type),
            'Mode' => ucfirst((string) $shipment->mode),
            'Bill of Lading/AWB' => $shipment->bl_awb_no,
            'Shipper' => $shipment->shipper_name,
            'Consignee' => $shipment->consignee_name,
            'Vessel / Flight' => $shipment->vessel_or_flight,
            'Port of loading' => $shipment->port_of_loading,
            'Port of discharge' => $shipment->port_of_discharge,
            'Incoterm' => $shipment->incoterm,
        ];
        $details = [];
        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $details[] = [$label, $value];
            }
        }

        $timeline = $shipment->statusUpdates->sortByDesc(fn ($update) => [$update->updated_at?->timestamp ?? 0, $update->update_id])
            ->map(function ($update): array {
                $info = app(\App\Services\ShipmentStageService::class)->statusInfo($update->stage, $update->stageDefinition?->stage_group);

                return [
                    'title' => $info['label'],
                    'note' => (string) ($update->remarks ?? ''),
                    'date' => $update->updated_at?->format('M d, Y') ?? '—',
                    'class' => $info['class'],
                ];
            })->values();

        return response()->json([
            'reference' => $shipment->reference_no,
            'status' => $status,
            'details' => $details,
            'timeline' => $timeline,
        ]);
    }

}
