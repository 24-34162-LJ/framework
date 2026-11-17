<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Client/ShipmentController.php
 * Feature: Client portal
 * Purpose: Client "My Shipments" list and the read-only shipment details page.
 */

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomsProcessing;
use App\Models\Delivery;
use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\ShipmentStatusUpdate;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    public function index(Request $request, ShipmentStageService $stageService): View
    {
        $clientId = (int) ($request->user()->client_id ?? 0);
        $groups = collect($stageService->groups())->map(fn (array $group, string $key): array => $group + ['key' => $key])->values();

        $statusKey = (string) $request->query('status', '');
        if (! $groups->contains(fn (array $group): bool => $group['key'] === $statusKey)) {
            $statusKey = '';
        }
        $typeOptions = [
            '' => 'All types', 'import' => 'Import', 'export' => 'Export',
            'sea' => 'Sea freight', 'air' => 'Air freight', 'land' => 'Land',
        ];
        $typeKey = (string) $request->query('type', '');
        if (! array_key_exists($typeKey, $typeOptions)) {
            $typeKey = '';
        }
        $search = trim((string) $request->query('q', ''));

        $base = Shipment::query()->where('client_id', $clientId);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $base->where(fn (Builder $query) => $query->where('reference_no', 'like', $like)->orWhere('bl_awb_no', 'like', $like));
        }
        if (in_array($typeKey, ['import', 'export'], true)) {
            $base->where('transaction_type', $typeKey);
        } elseif (in_array($typeKey, ['sea', 'air', 'land'], true)) {
            $base->where('mode', $typeKey);
        }

        $statusCounts = (clone $base)->selectRaw('LOWER(TRIM(current_status)) AS normalized_status, COUNT(*) AS total')
            ->groupByRaw('LOWER(TRIM(current_status))')->pluck('total', 'normalized_status');
        $groups = $groups->map(function (array $group) use ($statusCounts): array {
            $group['count'] = collect($group['stages'])->sum(fn (string $stage): int => (int) ($statusCounts[mb_strtolower(trim($stage))] ?? 0));

            return $group;
        });
        $allCount = (int) $statusCounts->sum();

        $query = clone $base;
        if ($statusKey !== '') {
            $selected = $groups->firstWhere('key', $statusKey);
            $normalized = array_map(fn (string $stage): string => mb_strtolower(trim($stage)), $selected['stages']);
            $query->whereIn(DB::raw('LOWER(TRIM(current_status))'), $normalized);
        }
        $shipments = $query->with('stage')->withMax('statusUpdates', 'updated_at')
            ->orderByDesc('created_at')->orderByDesc('shipment_id')
            ->paginate(8)->withQueryString();
        $shipments->getCollection()->transform(function (Shipment $shipment): Shipment {
            $shipment->dashboard_status_label = $shipment->current_status === 'Trucking'
                ? 'Trucking' : ($shipment->stage?->stage_group ?? $shipment->current_status);

            return $shipment;
        });

        return view('client.shipments.index', compact('groups', 'statusKey', 'typeOptions', 'typeKey', 'search', 'allCount', 'statusCounts', 'shipments'));
    }

    public function show(Request $request, int $shipmentId, ShipmentStageService $stageService, ShipmentDocumentService $documentService): View
    {
        $clientId = (int) ($request->user()->client_id ?? 0);
        $shipment = Shipment::query()->with([
            'stage', 'assignedStaff', 'containers', 'statusUpdates.stageDefinition',
        ])->where('client_id', $clientId)->findOrFail($shipmentId);

        $delivery = Delivery::query()->where('shipment_id', $shipmentId)->orderByDesc('delivery_id')->first();
        $customs = CustomsProcessing::query()->where('shipment_id', $shipmentId)
            ->orderByDesc('updated_at')->orderByDesc('customs_id')->first();
        // Current versions only; the client sees staff documents and its own uploads with their review status.
        $documents = $shipment->documents()->current()->orderByDesc('uploaded_at')->orderByDesc('document_id')->get()
            ->filter(fn ($document): bool => $documentService->readablePath($document) !== null)
            ->values();
        $documentChecklist = $documentService->checklist($documents);
        $acceptedCount = $documents->filter(fn ($document) => $document->isAccepted())->count();
        $documentRequests = \App\Models\DocumentRequest::query()->where('shipment_id', $shipmentId)->where('status', 'open')->orderBy('due_date')->get();
        $canUpload = $shipment->acceptsClientUploads();
        $documentCatalog = $documentService->catalog();
        $progress = $stageService->progress($shipment);
        $bills = DB::table('v_billing_balance')->where('shipment_id', $shipmentId)->where('client_id', $clientId)->orderBy('billing_id')->get();
        $totalDue = (float) $bills->sum('total_amount');
        $totalPaid = (float) $bills->sum('amount_paid');
        $paymentStatus = $totalPaid >= $totalDue && $totalDue > 0
            ? ['Paid', 'green'] : ($totalPaid > 0 ? ['Partially Paid', 'amber'] : ['Unpaid', 'red']);
        $statusClass = $stageService->groupClass($shipment->stage?->stage_group ?? '');
        $statusLabel = $shipment->current_status === 'Trucking' ? 'Trucking' : ($shipment->stage?->stage_group ?? $shipment->current_status);
        $lastUpdated = $shipment->statusUpdates->sortByDesc(fn (ShipmentStatusUpdate $update) => [$update->updated_at?->timestamp ?? 0, $update->update_id])->first()?->updated_at ?? $shipment->created_at;

        return view('client.shipments.show', compact(
            'shipment', 'delivery', 'customs', 'documents', 'documentChecklist', 'progress', 'bills', 'totalDue', 'totalPaid', 'paymentStatus',
            'statusClass', 'statusLabel', 'lastUpdated', 'acceptedCount', 'documentRequests', 'canUpload', 'documentCatalog'
        ));
    }

}
