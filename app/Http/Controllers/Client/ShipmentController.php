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

        // "Action needed from you": only what the client can act on (never internal office problems).
        $consistency = app(\App\Services\ShipmentConsistencyService::class);
        $clientActions = [];
        $docsNeeded = collect($consistency->missingDocuments($shipment))
            ->merge($documentRequests->pluck('doc_type'))->map(fn ($label) => trim((string) $label))->filter()->unique()->values();
        if ($docsNeeded->isNotEmpty() && $canUpload) {
            $clientActions[] = ['kind' => 'documents', 'title' => 'Upload '.($docsNeeded->count() === 1 ? 'a document' : $docsNeeded->count().' documents'),
                'text' => 'We need: '.$docsNeeded->implode(', ').'.', 'action' => 'Upload documents', 'url' => '#documents'];
        }
        $unpaidBills = $bills->filter(fn ($bill) => (float) $bill->balance > 0);
        if ($unpaidBills->isNotEmpty()) {
            $nextDue = DB::table('billings')->whereIn('billing_id', $unpaidBills->pluck('billing_id'))->min('due_date');
            $clientActions[] = ['kind' => 'billing', 'title' => 'Pay your '.($unpaidBills->count() === 1 ? 'bill' : 'bills'),
                'text' => '₱'.number_format((float) $unpaidBills->sum('balance'), 2).' is unpaid'.($nextDue ? ', next due '.\Carbon\Carbon::parse($nextDue)->format('M d, Y') : '').'.',
                'action' => $unpaidBills->count() === 1 ? 'Pay or submit proof' : 'View bills',
                'url' => $unpaidBills->count() === 1 ? route('billing.index', ['view' => 'submit', 'id' => $unpaidBills->first()->billing_id]) : route('billing.index')];
        }
        if ($storage = $consistency->storageAlert($shipment)) {
            $clientActions[] = ['kind' => 'storage', 'title' => $storage['started'] ? 'Storage charges are running' : 'Free storage ends soon',
                'text' => $storage['started']
                    ? 'Port storage charges have applied since '.$storage['starts']->format('M d, Y').'. Uploading missing documents and paying bills helps us release your cargo sooner.'
                    : 'Free storage at the port ends '.$storage['starts']->format('M d, Y').'. Storage charges start after that if the cargo is not released.',
                'action' => null, 'url' => null];
        }

        return view('client.shipments.show', compact(
            'shipment', 'delivery', 'customs', 'documents', 'documentChecklist', 'progress', 'bills', 'totalDue', 'totalPaid', 'paymentStatus',
            'statusClass', 'statusLabel', 'lastUpdated', 'acceptedCount', 'documentRequests', 'canUpload', 'documentCatalog', 'clientActions'
        ));
    }

}
