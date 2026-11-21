<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Admin/ActivityController.php
 * Feature: Admin - Activity log
 * Purpose: Admin "Activity" page with two tabs: "All activity" (everything users did) and
 *          "Shipment updates" (the shipment status timeline). The old Shipment Updates URLs redirect here.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ShipmentStatusUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public const TAB_ALL = 'all';

    public const TAB_SHIPMENTS = 'shipments';

    public function index(Request $request): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $tab = $request->query('tab') === self::TAB_SHIPMENTS ? self::TAB_SHIPMENTS : self::TAB_ALL;
        $search = trim((string) $request->query('q', ''));
        $term = '%'.$search.'%';

        if ($tab === self::TAB_SHIPMENTS) {
            $query = ShipmentStatusUpdate::query()->with(['shipment', 'updatedBy']);
            if ($search !== '') {
                $query->where(function ($filter) use ($term): void {
                    $filter->where('stage', 'like', $term)
                        ->orWhere('status', 'like', $term)
                        ->orWhere('remarks', 'like', $term)
                        ->orWhereHas('shipment', fn ($shipment) => $shipment->where('reference_no', 'like', $term))
                        ->orWhereHas('updatedBy', fn ($actor) => $actor->where('full_name', 'like', $term));
                });
            }
            $items = $query->orderByDesc('updated_at')->orderByDesc('update_id')->paginate(20)->withQueryString();
        } else {
            $query = ActivityLog::query();
            if ($search !== '') {
                $query->where(fn ($filter) => $filter->whereRaw("COALESCE(performed_by_name, 'System') LIKE ?", [$term])
                    ->orWhereRaw("COALESCE(performed_by_role, '') LIKE ?", [$term])
                    ->orWhere('activity_type', 'like', $term)
                    ->orWhere('description', 'like', $term));
            }
            $items = $query->orderByDesc('created_at')->orderByDesc('activity_id')->paginate(20)->withQueryString();
        }

        // Totals on the tabs (unfiltered), so the admin sees how much each list holds.
        $counts = [
            self::TAB_ALL => ActivityLog::query()->count(),
            self::TAB_SHIPMENTS => ShipmentStatusUpdate::query()->count(),
        ];

        return view('admin.activity.index', compact('items', 'search', 'tab', 'counts'));
    }

    /** Old "Shipment Updates" page: now the second tab of Activity. */
    public function shipmentUpdates(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        return redirect()->route('admin.activity', array_filter([
            'tab' => self::TAB_SHIPMENTS,
            'q' => trim((string) $request->query('q', '')),
            'page' => $request->query('page'),
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
