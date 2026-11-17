<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Client/DashboardController.php
 * Feature: Client portal
 * Purpose: Client dashboard: the client's shipments, tracking search and pending document tasks.
 */

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentStatusUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $clientId = (int) ($user->client_id ?? 0);
        $clientName = $user->client?->client_name ?: $user->full_name;
        $pageQuery = $request->except(['q', 'ajax', 'sp', 'bp']);

        $totalShipments = Shipment::query()->where('client_id', $clientId)->count();
        $deliveredShipments = Shipment::query()->where('client_id', $clientId)
            ->whereRaw("LOWER(TRIM(current_status)) = 'delivered'")->count();
        $inProgressShipments = $totalShipments - $deliveredShipments;

        $shipments = Shipment::query()
            ->with(['stage'])
            ->withMax('statusUpdates', 'updated_at')
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')->orderByDesc('shipment_id')
            ->paginate(5, ['*'], 'sp')->appends($pageQuery);
        $shipments->getCollection()->transform(function (Shipment $shipment): Shipment {
            $info = app(\App\Services\ShipmentStageService::class)->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
            $shipment->dashboard_status_label = $info['label'];
            $shipment->dashboard_status_class = $info['class'];
            $shipment->dashboard_last_updated = $shipment->status_updates_max_updated_at ?: $shipment->created_at;

            return $shipment;
        });

        $updates = ShipmentStatusUpdate::query()
            ->with(['shipment', 'stageDefinition'])
            ->whereHas('shipment', fn (Builder $query) => $query->where('client_id', $clientId))
            ->orderByDesc('updated_at')->orderByDesc('update_id')->limit(4)->get();
        $updates->transform(function (ShipmentStatusUpdate $update): ShipmentStatusUpdate {
            $info = app(\App\Services\ShipmentStageService::class)->statusInfo($update->stage, $update->stageDefinition?->stage_group);
            $update->dashboard_status_label = $info['label'];
            $update->dashboard_status_class = $info['class'];
            $update->dashboard_note = trim((string) $update->remarks) !== '' ? $update->remarks : $update->status;

            return $update;
        });

        $billingCount = DB::table('billings')->where('client_id', $clientId)->count();
        $bills = DB::table('v_billing_balance as balance_view')
            ->join('shipments', 'shipments.shipment_id', '=', 'balance_view.shipment_id')
            ->where('balance_view.client_id', $clientId)
            ->select('balance_view.billing_id', 'balance_view.billing_no', 'balance_view.total_amount', 'balance_view.amount_paid', 'balance_view.payment_status', 'shipments.reference_no')
            ->orderByDesc('balance_view.billing_id')
            ->paginate(5, ['*'], 'bp')->appends($pageQuery);

        $documentTasks = rescue(fn () => $this->documentTasks($clientId), collect(), false);

        return view('client.dashboard', compact(
            'clientId', 'clientName', 'totalShipments', 'deliveredShipments', 'inProgressShipments',
            'shipments', 'updates', 'billingCount', 'bills', 'documentTasks'
        ));
    }

    public function track(Request $request): JsonResponse
    {
        $reference = trim((string) $request->query('q', ''));
        if ($reference === '') {
            return response()->json(['error' => 'Enter a Bill of Lading or shipment reference number.'], 400);
        }

        $shipment = Shipment::query()->with(['stage', 'statusUpdates.stageDefinition'])
            ->where('client_id', (int) ($request->user()->client_id ?? 0))
            ->where(fn (Builder $query) => $query->where('reference_no', $reference)->orWhere('bl_awb_no', $reference))
            ->first();

        if (! $shipment) {
            return response()->json(['error' => 'No shipment on your account matches "'.$reference.'". Check the number and try again.'], 404);
        }

        $status = app(\App\Services\ShipmentStageService::class)->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
        $modeLabel = match ($shipment->mode) {
            'land' => 'Truck Plate',
            'air' => 'Airline / Flight',
            default => 'Vessel / Voyage',
        };
        $fields = [
            'Type' => ucfirst((string) $shipment->transaction_type),
            'Mode' => ucfirst((string) $shipment->mode),
            'Bill of Lading/AWB' => $shipment->bl_awb_no,
            'Shipper' => $shipment->shipper_name,
            'Consignee' => $shipment->consignee_name,
            $modeLabel => $shipment->vessel_or_flight,
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

        $timeline = $shipment->statusUpdates
            ->sortByDesc(fn (ShipmentStatusUpdate $update) => [$update->updated_at?->timestamp ?? 0, $update->update_id])
            ->map(function (ShipmentStatusUpdate $update): array {
                $info = app(\App\Services\ShipmentStageService::class)->statusInfo($update->stage, $update->stageDefinition?->stage_group);

                return [
                    'title' => $info['label'],
                    'note' => (string) $update->remarks,
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


    /**
     * Documents the client should act on: open staff requests, rejected uploads, and missing
     * client-supplied documents (B/L, invoice, packing list) on open shipments.
     *
     * @return \Illuminate\Support\Collection<int, array{shipment_id: int, reference: string, label: string, note: string, tone: string}>
     */
    private function documentTasks(int $clientId): \Illuminate\Support\Collection
    {
        $service = app(\App\Services\ShipmentDocumentService::class);
        $open = Shipment::query()->with(['stage', 'documents' => fn ($q) => $q->whereNull('superseded_at')])
            ->where('client_id', $clientId)->whereNull('archived_at')->get()
            ->filter(fn (Shipment $shipment) => $shipment->acceptsClientUploads());
        $requests = \App\Models\DocumentRequest::query()->whereIn('shipment_id', $open->pluck('shipment_id'))->where('status', 'open')->whereNull('fulfilled_document_id')->get();
        $tasks = collect();
        foreach ($requests as $request) {
            $shipment = $open->firstWhere('shipment_id', $request->shipment_id);
            $tasks->push(['shipment_id' => (int) $request->shipment_id, 'reference' => $shipment->reference_no, 'label' => 'Upload '.$request->doc_type,
                'note' => $request->isOverdue() ? 'Overdue' : ($request->due_date ? 'Due '.$request->due_date->format('M d') : 'Requested'), 'tone' => $request->isOverdue() ? 'overdue' : 'request']);
        }
        foreach ($open as $shipment) {
            foreach ($shipment->documents->where('source', 'client')->where('review_status', 'rejected') as $document) {
                $tasks->push(['shipment_id' => (int) $shipment->shipment_id, 'reference' => $shipment->reference_no, 'label' => 'Re-upload '.$document->doc_type, 'note' => 'Rejected', 'tone' => 'rejected']);
            }
            $checklist = $service->checklist($shipment->documents);
            foreach ($checklist['slots'] as $slot) {
                $alreadyListed = $tasks->contains(fn ($task) => $task['shipment_id'] === (int) $shipment->shipment_id && str_contains($task['label'], $slot['label']));
                if (in_array($slot['key'], ['bl', 'invoice', 'packing'], true) && ! $slot['received'] && ! $slot['pending'] && ! $alreadyListed) {
                    $tasks->push(['shipment_id' => (int) $shipment->shipment_id, 'reference' => $shipment->reference_no, 'label' => $slot['label'].' missing', 'note' => 'Upload', 'tone' => 'missing']);
                }
            }
        }

        return $tasks->sortBy(fn ($task) => ['overdue' => 0, 'rejected' => 1, 'request' => 2, 'missing' => 3][$task['tone']])->values();
    }
}
