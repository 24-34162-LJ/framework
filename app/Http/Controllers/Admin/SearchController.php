<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $shipments = collect();
        $clients = collect();
        $users = collect();

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $shipments = Shipment::query()
                ->with('client')
                ->where(function ($query) use ($like): void {
                    $query->where('reference_no', 'like', $like)
                        ->orWhere('bl_awb_no', 'like', $like)
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('client_name', 'like', $like));
                })
                ->orderByDesc('shipment_id')
                ->limit(20)
                ->get();

            $clients = Client::query()
                ->where('client_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orderBy('client_name')
                ->limit(20)
                ->get(['client_id', 'client_name', 'email', 'status']);

            $users = User::query()
                ->where('full_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orderBy('full_name')
                ->limit(20)
                ->get(['user_id', 'full_name', 'email', 'role', 'status']);
        }

        return view('admin.search.index', compact('q', 'shipments', 'clients', 'users'));
    }
}
