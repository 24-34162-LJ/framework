<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $portalUrl = match ($user?->role) {
            'admin' => '/account-management/admin.php',
            'staff' => '/account-management/staff.php',
            'client' => '/client-management/client_dashbroad.php',
            default => '/login.php',
        };
        // The one main action: guests sign in to track; a signed-in user goes straight to their dashboard.
        $trackUrl = $portalUrl;
        $mainAction = $user ? 'Open your dashboard' : 'Track a shipment';

        return view('landing.index', compact('trackUrl', 'mainAction'));
    }
}
