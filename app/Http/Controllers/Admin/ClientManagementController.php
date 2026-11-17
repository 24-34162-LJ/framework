<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Admin/ClientManagementController.php
 * Feature: Admin - Client management
 * Purpose: Admin page to list, add and edit client companies.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveClientRequest;
use App\Models\Client;
use App\Models\ShipmentStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClientManagementController extends Controller
{
    public function index(Request $request): View
    {
        $client = null;
        $tab = in_array($request->query('tab'), ['overview', 'accounts', 'shipments'], true)
            ? $request->query('tab')
            : 'overview';
        $search = trim((string) $request->query('q', ''));
        $statusFilter = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : '';

        $stats = [
            'total' => Client::query()->count(),
            'active' => Client::query()->where('status', 'active')->count(),
            'inactive' => Client::query()->where('status', 'inactive')->count(),
        ];

        $clientQuery = Client::query()
            ->withCount(['shipments as active_shipments' => fn (Builder $query) => $query
                ->whereNull('archived_at')
                ->whereHas('stage', fn (Builder $stage) => $stage->where('is_closed', false))]);

        if ($search !== '') {
            $clientQuery->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('client_name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }
        if ($statusFilter !== '') {
            $clientQuery->where('status', $statusFilter);
        }

        $clientList = $clientQuery->orderByDesc('client_id')->paginate(8)->withQueryString();
        $shipmentSearch = trim((string) $request->query('sq', ''));
        $shipmentStatus = trim((string) $request->query('ss', ''));
        $shipmentRows = null;
        $accounts = collect();
        $accountCount = $shipmentCount = $activeShipments = $completedShipments = 0;

        if ($request->filled('id')) {
            $client = Client::query()->findOrFail($request->integer('id'));
            $accountCount = $client->users()->where('role', 'client')->count();
            $shipmentCount = $client->shipments()->whereNull('archived_at')->count();
            $activeShipments = $client->shipments()->whereNull('archived_at')
                ->whereHas('stage', fn (Builder $stage) => $stage->where('is_closed', false))->count();
            $completedShipments = $client->shipments()->whereNull('archived_at')
                ->whereHas('stage', fn (Builder $stage) => $stage->where('stage_group', 'Delivered'))->count();

            if ($tab === 'accounts') {
                $accounts = $client->users()->where('role', 'client')
                    ->orderByDesc('created_at')->orderByDesc('user_id')->get();
            }

            if ($tab === 'shipments') {
                $shipmentQuery = $client->shipments()->withMax('statusUpdates', 'updated_at');
                if ($shipmentSearch !== '') {
                    $shipmentQuery->where('reference_no', 'like', '%'.$shipmentSearch.'%');
                }
                if ($shipmentStatus !== '') {
                    $shipmentQuery->where('current_status', $shipmentStatus);
                }
                $shipmentRows = $shipmentQuery->orderByDesc('created_at')->orderByDesc('shipment_id')
                    ->paginate(5, ['*'], 'sp')->withQueryString();
            }
        }

        $showForm = auth()->user()->role === 'admin'
            && ($request->boolean('add') || ($client && $request->boolean('edit')) || old('action') === 'save_client');
        $formValues = old('action') === 'save_client'
            ? old()
            : ($client?->only(['client_name', 'contact_person', 'email', 'phone', 'address', 'tin', 'status'])
                ?? ['client_name' => '', 'contact_person' => '', 'email' => '', 'phone' => '', 'address' => '', 'tin' => '', 'status' => 'active']);

        return view('admin.clients.index', compact(
            'client', 'tab', 'search', 'statusFilter', 'stats', 'clientList', 'shipmentSearch',
            'shipmentStatus', 'shipmentRows', 'accounts', 'accountCount', 'shipmentCount',
            'activeShipments', 'completedShipments', 'showForm', 'formValues'
        ) + ['stages' => ShipmentStage::query()->orderBy('sort_order')->get(['stage_name'])]);
    }

    public function save(SaveClientRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $clientId = (int) ($data['client_id'] ?? 0);
        $tab = $data['return_tab'] ?? 'overview';

        if ($data['action'] === 'set_status') {
            $client = Client::query()->findOrFail($clientId);
            $oldStatus = $client->status;
            if ($oldStatus !== $data['status']) {
                DB::transaction(function () use ($client, $data, $request, $oldStatus): void {
                    $client->status = $data['status'];
                    $client->save();
                    $verb = $data['status'] === 'active' ? 'reactivated' : 'deactivated';
                    $this->recordActivity($request->user(), "Client company {$verb}: {$client->client_name} ({$this->clientCode($client)})", [
                        'status' => ['old' => $oldStatus, 'new' => $data['status']],
                    ]);
                });
            }

            return $this->backToClient($clientId, $tab)->with('success', $data['status'] === 'active'
                ? 'Client reactivated. Existing accounts and records were retained.'
                : 'Client deactivated. Existing accounts and historical records were retained.');
        }

        $client = $clientId > 0 ? Client::query()->findOrFail($clientId) : new Client;
        $before = $clientId > 0 ? $client->only(['client_name', 'contact_person', 'email', 'phone', 'address', 'tin', 'status']) : null;
        $client->fill([
            'client_name' => trim($data['client_name']),
            'contact_person' => $this->nullableTrim($data['contact_person'] ?? null),
            'email' => $this->nullableTrim($data['email'] ?? null),
            'phone' => $this->nullableTrim($data['phone'] ?? null),
            'address' => $this->nullableTrim($data['address'] ?? null),
            'tin' => $this->nullableTrim($data['tin'] ?? null),
            'status' => $data['status'],
        ]);
        $client->save();

        if ($before === null) {
            $this->recordActivity($request->user(), "Client company created: {$client->client_name} ({$this->clientCode($client)})", [
                'status' => ['old' => '—', 'new' => $client->status],
            ]);

            return redirect()->route('admin.clients', ['id' => $client->client_id])
                ->with('success', 'Client company added. Link client user accounts in User Management.');
        }

        $labels = ['client_name' => 'Company Name', 'contact_person' => 'Contact Person', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'tin' => 'TIN', 'status' => 'Status'];
        $changes = [];
        foreach ($labels as $key => $label) {
            $oldValue = (string) ($before[$key] ?? '');
            $newValue = (string) ($client->{$key} ?? '');
            if ($oldValue !== $newValue) {
                $changes[$label] = ['old' => $oldValue !== '' ? $oldValue : '—', 'new' => $newValue !== '' ? $newValue : '—'];
            }
        }
        if ($changes !== []) {
            $this->recordActivity($request->user(), "Client company updated: {$client->client_name} ({$this->clientCode($client)})", $changes);
        }

        return $this->backToClient($clientId, $tab)->with('success', 'Client company details saved. Linked records remain intact.');
    }

    private function backToClient(int $clientId, string $tab): RedirectResponse
    {
        return redirect()->route('admin.clients', $clientId > 0 ? ['id' => $clientId, 'tab' => $tab] : []);
    }

    private function recordActivity(User $actor, string $description, array $details): void
    {
        app(\App\Services\ActivityLogger::class)->record($actor, 'Client Management', $description, $details);
    }

    private function clientCode(Client $client): string
    {
        return 'CL-'.str_pad((string) $client->client_id, 4, '0', STR_PAD_LEFT);
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
