<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentRequestController.php
 * Feature: Shipment requests
 * Purpose: Clients request a new shipment by uploading documents; staff review, decline or convert the
 *          request.
 */

namespace App\Http\Controllers;

use App\Models\ShipmentDocument;
use App\Models\ShipmentRequest;
use App\Services\ActivityLogger;
use App\Services\GeminiExtractor;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Clients start a shipment by uploading their document packet; staff review and convert it. */
class ShipmentRequestController extends Controller
{
    public function __construct(private ActivityLogger $activity, private NotificationService $notifications) {}

    /* ---------------------------------------------------------------- client */

    public function clientIndex(Request $request): View
    {
        $requests = ShipmentRequest::query()->with('shipment')->where('client_id', (int) $request->user()->client_id)
            ->orderByDesc('request_id')->limit(50)->get();

        return view('client.shipment-requests.index', compact('requests'));
    }

    /** POST /client/shipment-requests — JSON; extraction runs in a second call so the upload returns quickly. */
    public function clientStore(Request $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            $tooBig = $file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            return response()->json(['ok' => false, 'error' => $tooBig ? 'The PDF is larger than the server allows ('.ini_get('upload_max_filesize').').' : 'Choose a PDF to upload.'], 422);
        }
        if ($file->getSize() > 25 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'The file is larger than 25 MB.'], 422);
        }
        if ($file->getMimeType() !== 'application/pdf' || (string) file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            return response()->json(['ok' => false, 'error' => 'Only PDF files are accepted.'], 422);
        }
        $notes = trim((string) $request->input('client_notes', ''));
        if (mb_strlen($notes) > 1000) {
            return response()->json(['ok' => false, 'error' => 'Notes must be 1,000 characters or fewer.'], 422);
        }

        $hash = (string) hash_file('sha256', $file->getRealPath());
        $pending = ShipmentRequest::query()->where('client_id', (int) $user->client_id)->where('file_hash', $hash)->whereIn('status', ['pending', 'converted'])->first();
        if ($pending) {
            return response()->json(['ok' => false, 'duplicate' => true, 'error' => 'You already sent this exact file as '.$pending->referenceLabel().' ('.$pending->status.').'], 409);
        }
        $onShipment = ShipmentDocument::query()->where('file_hash', $hash)->whereHas('shipment', fn ($q) => $q->where('client_id', (int) $user->client_id))->with('shipment')->first();
        if ($onShipment) {
            return response()->json(['ok' => false, 'duplicate' => true, 'error' => 'This exact file is already attached to shipment '.$onShipment->shipment->reference_no.'.'], 409);
        }

        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '._-') ?: 'documents';
        $path = $file->storeAs('shipment-requests/client-'.(int) $user->client_id, now()->format('Ymd-His').'_'.substr($name, 0, 60).'.pdf', 'local');
        if (! $path) {
            return response()->json(['ok' => false, 'error' => 'The file could not be saved. Try again.'], 500);
        }
        $shipmentRequest = ShipmentRequest::query()->create([
            'client_id' => (int) $user->client_id, 'submitted_by' => $user->user_id, 'status' => 'pending',
            'file_path' => $path, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'file_hash' => $hash,
            'extraction_status' => 'waiting', 'client_notes' => $notes !== '' ? $notes : null,
        ]);
        $shipmentRequest->refresh();

        $client = $user->client?->client_name ?? 'A client';
        $this->activity->record($user, 'Shipment Request', $shipmentRequest->referenceLabel().' submitted by '.$client.' ('.$shipmentRequest->original_name.')');
        $this->notifications->forUsers($this->notifications->officeUserIds(), $user, 'shipment_request',
            'New shipment request', $client.' sent '.$shipmentRequest->referenceLabel().' with shipping documents. Review it in Shipment Requests.');

        return response()->json([
            'ok' => true, 'request_id' => $shipmentRequest->request_id, 'reference' => $shipmentRequest->referenceLabel(),
            'extract_url' => route('shipment-requests.extract', $shipmentRequest->request_id),
        ]);
    }

    /** POST /client/shipment-requests/{id}/cancel */
    public function clientCancel(Request $request, int $requestId): RedirectResponse
    {
        $shipmentRequest = ShipmentRequest::query()->where('client_id', (int) $request->user()->client_id)->where('status', 'pending')->findOrFail($requestId);
        $shipmentRequest->update(['status' => 'cancelled']);
        $this->activity->record($request->user(), 'Shipment Request', $shipmentRequest->referenceLabel().' cancelled by the client');

        return back()->with('success', $shipmentRequest->referenceLabel().' cancelled.');
    }

    /* ---------------------------------------------------------------- staff */

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'converted', 'declined', 'cancelled', 'all'], true), 404);
        $requests = ShipmentRequest::query()->with(['client', 'submittedBy', 'reviewedBy', 'shipment'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('request_id')->paginate(15)->withQueryString();
        $counts = ShipmentRequest::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('shipment-requests.index', compact('requests', 'status', 'counts'));
    }

    /** POST /shipment-requests/{id}/decline */
    public function decline(Request $request, int $requestId): RedirectResponse
    {
        $data = $request->validate(['staff_note' => ['required', 'string', 'max:255']], ['staff_note.required' => 'Tell the client why the request was declined.']);
        $shipmentRequest = ShipmentRequest::query()->where('status', 'pending')->findOrFail($requestId);
        $shipmentRequest->update(['status' => 'declined', 'staff_note' => trim($data['staff_note']), 'reviewed_by' => $request->user()->user_id, 'reviewed_at' => now()]);
        $this->activity->record($request->user(), 'Shipment Request', $shipmentRequest->referenceLabel().' declined: '.$shipmentRequest->staff_note);
        $this->notifications->forUsers($this->notifications->clientUserIds((int) $shipmentRequest->client_id), $request->user(), 'shipment_request',
            'Shipment request declined', $shipmentRequest->referenceLabel().' was declined. Reason: '.$shipmentRequest->staff_note,
            key: 'request_declined', clientId: (int) $shipmentRequest->client_id);

        return back()->with('success', $shipmentRequest->referenceLabel().' declined. The client was notified.');
    }

    /* ---------------------------------------------------------------- both */

    /** POST /shipment-requests/{id}/extract — read the packet with Gemini and store the draft. */
    public function extract(Request $request, int $requestId, GeminiExtractor $extractor): JsonResponse
    {
        set_time_limit(180);
        $shipmentRequest = $this->visibleRequest($request, $requestId);
        if ($shipmentRequest->status !== 'pending') {
            return response()->json(['ok' => false, 'error' => 'This request is no longer pending.'], 409);
        }
        $path = Storage::disk('local')->path($shipmentRequest->file_path);
        if (! is_file($path)) {
            return response()->json(['ok' => false, 'error' => 'The uploaded file is missing.'], 404);
        }
        $result = $extractor->extractFromBytes((string) file_get_contents($path));
        if (isset($result['error'])) {
            $shipmentRequest->update(['extraction_status' => 'failed', 'extraction_error' => mb_substr($result['error'], 0, 255)]);

            return response()->json(['ok' => false, 'error' => $result['error']], (int) ($result['status'] ?? 502));
        }
        // The client is known: always the requesting client, whatever the document suggests.
        $result['form']['client_id'] = (int) $shipmentRequest->client_id;
        $shipmentRequest->update(['extraction_status' => 'done', 'extracted_json' => $result, 'extraction_error' => null]);
        $form = $result['form'];

        return response()->json(['ok' => true, 'summary' => [
            'bl_awb_no' => $form['bl_awb_no'] ?? null, 'route' => trim(($form['port_of_loading'] ?? '—').' → '.($form['port_of_discharge'] ?? '—')),
            'containers' => count($form['containers'] ?? []), 'invoice_no' => $form['invoice_no'] ?? null,
        ]]);
    }

    public function file(Request $request, int $requestId): BinaryFileResponse
    {
        $shipmentRequest = $this->visibleRequest($request, $requestId);
        $path = Storage::disk('local')->path($shipmentRequest->file_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $shipmentRequest->original_name).'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function visibleRequest(Request $request, int $requestId): ShipmentRequest
    {
        $user = $request->user();
        $shipmentRequest = ShipmentRequest::query()->findOrFail($requestId);
        if ($user->role === 'client') {
            abort_unless((int) $shipmentRequest->client_id === (int) $user->client_id, 404);
        } else {
            abort_unless(in_array($user->role, ['admin', 'staff'], true), 403);
        }

        return $shipmentRequest;
    }
}
