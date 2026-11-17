<?php

/*
 * FILE PURPOSE
 * File   : app/Support/ShipmentListUrl.php
 * Feature: Shipments
 * Purpose: Remembers the shipment list filters/page so "Back to Shipments" returns to the same list.
 */

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Remembers the shipment list the user last looked at (tab, filters, search, page),
 * so "Back to Shipments" / "Cancel" on the details and edit pages return to that exact list.
 * Kept per role, and only ever a same-site path.
 */
class ShipmentListUrl
{
    /** Call from the admin/staff shipment list pages. */
    public static function remember(Request $request): void
    {
        if ($request->boolean('export') || ! $request->hasSession() || ! $request->user()) {
            return;
        }

        $request->session()->put(self::key($request->user()), $request->getRequestUri());
    }

    public static function for(User $user): string
    {
        $saved = session(self::key($user));

        if (is_string($saved) && str_starts_with($saved, '/') && ! str_starts_with($saved, '//') && ! str_contains($saved, '\\')) {
            return url($saved);
        }

        return $user->role === 'admin' ? route('admin.shipments') : route('staff.shipments');
    }

    private static function key(User $user): string
    {
        return 'shipments.list_url.'.$user->role;
    }
}
