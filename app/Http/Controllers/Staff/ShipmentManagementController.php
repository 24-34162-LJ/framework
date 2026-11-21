<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Staff/ShipmentManagementController.php
 * Feature: Staff portal
 * Purpose: Staff shipment list with filters (only shipments the staff member may see).
 */

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ShipmentManagementController extends Controller
{
    public function index(Request $request): View
    {
        $stageService = app(\App\Services\ShipmentStageService::class);
        $groups = $stageService->groups();
        $statusKey = (string) $request->query('status', '');
        if (! array_key_exists($statusKey, $groups)) {
            $statusKey = '';
        }

        $dateOptions = [
            '' => 'Any date',
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'month' => 'This month',
            'year' => 'This year',
        ];
        $dateKey = array_key_exists((string) $request->query('date', ''), $dateOptions) ? (string) $request->query('date', '') : '';
        $arrivalOptions = [
            '' => 'Any arrival date',
            'arriving7d' => 'Arriving in 7 days',
            'overdue' => 'Overdue delivery',
        ];
        $arrivalKey = array_key_exists((string) $request->query('arrival', ''), $arrivalOptions) ? (string) $request->query('arrival', '') : '';
        $search = trim((string) $request->query('q', ''));
        \App\Support\ShipmentListUrl::remember($request);

        $baseQuery = Shipment::query()->with(['client', 'stage'])->withMax('statusUpdates', 'updated_at')
            ->whereNull('archived_at');
        $this->applyFilters($baseQuery, $search, $dateKey, $arrivalKey);

        $statusRows = (clone $baseQuery)->select('current_status')
            ->selectRaw('COUNT(*) AS total')->groupBy('current_status')->get();
        $statusCounts = $statusRows->mapWithKeys(fn ($row): array => [mb_strtolower(trim((string) $row->current_status)) => (int) $row->total]);
        $tabCounts = ['' => (int) $statusCounts->sum()];
        foreach ($groups as $key => $group) {
            $tabCounts[$key] = collect($group['stages'])->sum(fn (string $stage): int => (int) ($statusCounts[$stage] ?? 0));
        }

        $listQuery = clone $baseQuery;
        if ($statusKey !== '') {
            $listQuery->whereRaw('LOWER(TRIM(current_status)) IN ('.implode(', ', array_fill(0, count($groups[$statusKey]['stages']), '?')).')', $groups[$statusKey]['stages']);
        }

        $total = (clone $listQuery)->count();
        $perPage = 8;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $request->integer('page', 1)), $lastPage);
        $shipments = $listQuery->orderByDesc('created_at')->orderByDesc('shipment_id')
            ->paginate($perPage, ['*'], 'page', $page)->withQueryString();

        $today = Carbon::today();
        $staffId = (int) $request->user()->user_id;
        $shipments->getCollection()->transform(function (Shipment $shipment) use ($today, $staffId, $stageService): Shipment {
            $shipment->dashboard_status = $stageService->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
            $shipment->dashboard_edit_allowed = ! in_array(mb_strtolower(trim($shipment->current_status)), ['delivered', 'cancelled'], true)
                && $shipment->assigned_staff_id !== null && (int) $shipment->assigned_staff_id === $staffId;
            $shipment->dashboard_overdue = $shipment->delivery_due_date?->lt($today)
                && ! in_array(mb_strtolower(trim($shipment->current_status)), ['delivered', 'cancelled'], true);

            return $shipment;
        });

        return view('staff.shipments.index', compact(
            'groups', 'statusKey', 'dateOptions', 'dateKey', 'arrivalOptions', 'arrivalKey', 'search',
            'tabCounts', 'shipments', 'total', 'perPage'
        ));
    }

    private function applyFilters(Builder $query, string $search, string $dateKey, string $arrivalKey): void
    {
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($like): void {
                $query->where('reference_no', 'like', $like)
                    ->orWhere('bl_awb_no', 'like', $like)
                    ->orWhere('invoice_no', 'like', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->where('client_name', 'like', $like))
                    ->orWhereHas('containers', fn (Builder $container) => $container->where('container_no', 'like', $like));
            });
        }

        $now = Carbon::now();
        if ($dateKey === 'today') {
            $query->whereDate('created_at', Carbon::today()->toDateString());
        } elseif ($dateKey === '7d') {
            $query->where('created_at', '>=', $now->copy()->subDays(7));
        } elseif ($dateKey === '30d') {
            $query->where('created_at', '>=', $now->copy()->subDays(30));
        } elseif ($dateKey === 'month') {
            $query->where('created_at', '>=', Carbon::today()->startOfMonth());
        } elseif ($dateKey === 'year') {
            $query->where('created_at', '>=', Carbon::today()->startOfYear());
        }

        $notFinished = "LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')";
        if ($arrivalKey === 'arriving7d') {
            $query->whereBetween('eta_date', [Carbon::today()->toDateString(), Carbon::today()->addDays(7)->toDateString()])->whereRaw($notFinished);
        } elseif ($arrivalKey === 'overdue') {
            $query->where('delivery_due_date', '<', Carbon::today()->toDateString())->whereRaw($notFinished);
        }
    }

}

