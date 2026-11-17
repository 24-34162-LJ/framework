<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Admin/ActivityController.php
 * Feature: Admin - Activity log
 * Purpose: Admin pages "Recent Activity" and "Shipment Updates": lists what users did in the system.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ShipmentStatusUpdate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $search = trim((string) $request->query('q', ''));
        $query = ActivityLog::query();
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(fn ($filter) => $filter->whereRaw("COALESCE(performed_by_name, 'System') LIKE ?", [$term])
                ->orWhereRaw("COALESCE(performed_by_role, '') LIKE ?", [$term])
                ->orWhere('activity_type', 'like', $term)
                ->orWhere('description', 'like', $term));
        }
        $items = $query->orderByDesc('created_at')->orderByDesc('activity_id')->paginate(20)->withQueryString();

        return view('admin.activity.index', compact('items', 'search'));
    }

    public function shipmentUpdates(Request $request): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $search = trim((string) $request->query('q', ''));
        $query = ShipmentStatusUpdate::query()->with(['shipment', 'updatedBy']);
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($filter) use ($term): void {
                $filter->where('stage', 'like', $term)
                    ->orWhere('status', 'like', $term)
                    ->orWhere('remarks', 'like', $term)
                    ->orWhereHas('shipment', fn ($shipment) => $shipment->where('reference_no', 'like', $term))
                    ->orWhereHas('updatedBy', fn ($actor) => $actor->where('full_name', 'like', $term));
            });
        }
        $items = $query->orderByDesc('updated_at')->orderByDesc('update_id')->paginate(20)->withQueryString();

        return view('admin.activity.shipment-updates', compact('items', 'search'));
    }
}
