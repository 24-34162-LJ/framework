<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\ActivityLogger;
use App\Services\DocumentChecker;
use App\Services\NotificationService;
use App\Services\ShipmentDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Client document uploads and the staff side of them: review (accept / reject), document requests,
 * and the Gemini document check.
 */
class DocumentWorkflowController extends Controller
{
    public function __construct(
        private ShipmentDocumentService $documents,
        private ActivityLogger $activity,
        private NotificationService $notifications,
    ) {}

    /* ---------------------------------------------------------------- client */

    /** POST /client/shipments/{id}/documents — JSON for the client upload dialog. */
    public function clientUpload(Request $request, int $shipmentId): JsonResponse
    {
        $user = $request->user();
        $shipment = Shipment::query()->with('stage')->where('client_id', (int) $user->client_id)->find($shipmentId);
        if (! $shipment) {
            return response()->json(['ok' => false, 'error' => 'Shipment not found.'], 404);
        }
        if (! $shipment->acceptsClientUploads()) {
            return response()->json(['ok' => false, 'error' => 'This shipment is closed, so documents can no longer be uploaded. Contact your AstraTrack staff if something is missing.'], 409);
        }

        $type = $this->documents->canonicalType((string) $request->input('doc_type', ''));
        if (! $type) {
            return response()->json(['ok' => false, 'error' => 'Choose a valid document type.'], 422);
        }
        $remarks = trim((string) $request->input('remarks', ''));
        if (mb_strlen($remarks) > 255) {
            return response()->json(['ok' => false, 'error' => 'Remarks must be 255 characters or fewer.'], 422);
        }
        $file = $request->file('file');
        if ($error = $this->uploadProblem($file)) {
            return response()->json(['ok' => false, 'error' => $error], 422);
        }

        // Replacing: only the client's own uploads on this shipment.
        $replaces = null;
        if ($request->filled('replaces_document_id')) {
            $replaces = ShipmentDocument::query()->where('shipment_id', $shipmentId)->where('source', 'client')->whereNull('superseded_at')
                ->find($request->integer('replaces_document_id'));
            if (! $replaces) {
                return response()->json(['ok' => false, 'error' => 'Only your own current uploads can be replaced.'], 422);
            }
        }

        $hash = $this->documents->hashOf($file);
        if ($duplicate = $this->documents->duplicateOf($shipmentId, $hash)) {
            return response()->json(['ok' => false, 'duplicate' => true, 'error' => 'This exact file is already on this shipment as "'.$duplicate->doc_type.'" (uploaded '.($duplicate->uploaded_at?->format('M d, Y') ?? 'earlier').'). It was not uploaded again.'], 409);
        }

        try {
            $document = DB::transaction(function () use ($file, $shipment, $type, $remarks, $user, $hash, $replaces): ShipmentDocument {
                $document = $this->documents->store($file, (int) $shipment->shipment_id, (string) $shipment->reference_no, $type, (int) $user->user_id,
                    $remarks !== '' ? $remarks : null,
                    ['source' => 'client', 'review_status' => ShipmentDocument::REVIEW_PENDING, 'file_hash' => $hash, 'replaces_document_id' => $replaces?->document_id]);

                // Link the upload to an open staff request for the same document type.
                $slot = $this->documents->slotKey($type);
                DocumentRequest::query()->where('shipment_id', $shipment->shipment_id)->where('status', 'open')->get()
                    ->filter(fn (DocumentRequest $open) => $this->documents->slotKey($open->doc_type) === $slot)
                    ->each(fn (DocumentRequest $open) => $open->update(['fulfilled_document_id' => $document->document_id]));

                $what = $replaces ? 'replaced' : 'uploaded';
                $this->activity->record($user, 'Shipment Document', $shipment->reference_no.' client '.$what.' '.$type.' ('.basename($file->getClientOriginalName()).') · pending review',
                    ['document_id' => $document->document_id, 'source' => 'client']);
                $this->notifications->forShipmentRoles($shipment, $user, ['admin', 'staff'], 'document',
                    'Client document to review', $shipment->reference_no.': client '.$what.' '.$type.'. Review it to update the checklist.');

                return $document;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'error' => 'The document could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => $type.' uploaded. Our team will review it.',
            'document_id' => $document->document_id,
            'check_url' => app(DocumentChecker::class)->supports($document) ? route('documents.ai-check', $document->document_id) : null,
        ]);
    }

    /** DELETE /client/documents/{id} — a client withdraws its own upload that is still waiting for review. */
    public function clientWithdraw(Request $request, int $documentId): RedirectResponse
    {
        $user = $request->user();
        $document = ShipmentDocument::query()->with('shipment')->where('source', 'client')
            ->where('review_status', ShipmentDocument::REVIEW_PENDING)->whereNull('superseded_at')->findOrFail($documentId);
        abort_unless((int) $document->shipment?->client_id === (int) $user->client_id, 404);
        $shipment = $document->shipment;
        $path = $this->documents->readablePath($document);

        DB::transaction(function () use ($document): void {
            DocumentRequest::query()->where('fulfilled_document_id', $document->document_id)->update(['fulfilled_document_id' => null]);
            if ($document->replaces_document_id) {
                // Bring the replaced version back as current.
                ShipmentDocument::query()->where('document_id', $document->replaces_document_id)->update(['superseded_at' => null]);
            }
            $document->delete();
        }, 3);
        if ($path && is_file($path)) {
            @unlink($path);
        }
        $this->activity->record($user, 'Shipment Document', $shipment->reference_no.' client withdrew '.$document->doc_type.' before review');

        return redirect()->route('client.shipments.show', $shipment->shipment_id)->with('success', 'Upload withdrawn.')->withFragment('documents');
    }

    /* ---------------------------------------------------------------- staff */

    /** POST /shipment-documents/{id}/review — accept or reject a client upload. */
    public function review(Request $request, int $documentId): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:accept,reject'],
            'review_note' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ], ['review_note.required_if' => 'Give the client a reason when rejecting a document.']);
        $user = $request->user();
        $document = ShipmentDocument::query()->with('shipment')->whereNull('superseded_at')->findOrFail($documentId);
        $shipment = $this->staffShipment($request, (int) $document->shipment_id);
        abort_if($document->review_status !== ShipmentDocument::REVIEW_PENDING, 409, 'This document has already been reviewed.');

        $accepted = $data['decision'] === 'accept';
        $note = trim((string) ($data['review_note'] ?? ''));
        DB::transaction(function () use ($document, $accepted, $note, $user): void {
            $document->update([
                'review_status' => $accepted ? ShipmentDocument::REVIEW_ACCEPTED : ShipmentDocument::REVIEW_REJECTED,
                'reviewed_by' => $user->user_id, 'reviewed_at' => now(), 'review_note' => $note !== '' ? $note : null,
            ]);
            $linked = DocumentRequest::query()->where('fulfilled_document_id', $document->document_id)->where('status', 'open');
            $accepted ? $linked->update(['status' => 'fulfilled', 'fulfilled_at' => now()]) : $linked->update(['fulfilled_document_id' => null]);
            if (! $accepted && $document->replaces_document_id) {
                // A rejected replacement must not hide the version it tried to replace.
                ShipmentDocument::query()->where('document_id', $document->replaces_document_id)->update(['superseded_at' => null]);
            }
        }, 3);

        $this->activity->record($user, 'Shipment Document', $shipment->reference_no.' client '.$document->doc_type.' '.($accepted ? 'accepted' : 'rejected').($note !== '' ? ': '.$note : ''),
            ['document_id' => $document->document_id]);
        $this->notifications->forShipmentRoles($shipment, $user, ['client'], 'document',
            $accepted ? 'Document accepted' : 'Document needs to be re-uploaded',
            $shipment->reference_no.': your '.$document->doc_type.' was '.($accepted ? 'accepted.' : 'rejected. Reason: '.$note),
            key: $accepted ? 'document_accepted' : 'document_rejected');

        return back()->with('success', $document->doc_type.' '.($accepted ? 'accepted' : 'rejected').'. The client was notified.')->withFragment('documents');
    }

    /** POST /shipments/{id}/document-requests — ask the client to upload a document type. */
    public function requestDocument(Request $request, int $shipmentId): RedirectResponse
    {
        $data = $request->validate([
            'doc_type' => ['required', 'string', 'max:40'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $type = $this->documents->canonicalType($data['doc_type']);
        abort_unless($type, 422, 'Choose a valid document type.');
        $shipment = $this->staffShipment($request, $shipmentId);
        abort_if($shipment->archived_at !== null, 409, 'Archived shipments are read-only.');
        $slot = $this->documents->slotKey($type);
        $alreadyOpen = DocumentRequest::query()->where('shipment_id', $shipmentId)->where('status', 'open')->get()
            ->contains(fn (DocumentRequest $open) => $this->documents->slotKey($open->doc_type) === $slot);
        if ($alreadyOpen) {
            return back()->withErrors(['doc_type' => 'There is already an open request for '.$type.'.'])->withFragment('documents');
        }

        DocumentRequest::query()->create([
            'shipment_id' => $shipmentId, 'doc_type' => $type, 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'due_date' => $data['due_date'] ?? null, 'status' => 'open', 'requested_by' => $request->user()->user_id, 'requested_at' => now(),
        ]);
        $due = filled($data['due_date'] ?? null) ? ' by '.\Illuminate\Support\Carbon::parse($data['due_date'])->format('M d, Y') : '';
        $this->activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' requested '.$type.' from client'.$due);
        $this->notifications->forShipmentRoles($shipment, $request->user(), ['client'], 'document',
            'Document requested', $shipment->reference_no.': please upload the '.$type.$due.'.', key: 'document_requested');

        return back()->with('success', 'Request sent to the client for '.$type.'.')->withFragment('documents');
    }

    /** POST /document-requests/{id}/cancel */
    public function cancelRequest(Request $request, int $requestId): RedirectResponse
    {
        $documentRequest = DocumentRequest::query()->where('status', 'open')->findOrFail($requestId);
        $shipment = $this->staffShipment($request, (int) $documentRequest->shipment_id);
        $documentRequest->update(['status' => 'cancelled']);
        $this->activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' cancelled the request for '.$documentRequest->doc_type);
        $this->notifications->forShipmentRoles($shipment, $request->user(), ['client'], 'document',
            'Document request cancelled', $shipment->reference_no.': the request for '.$documentRequest->doc_type.' was cancelled.');

        return back()->with('success', 'Request for '.$documentRequest->doc_type.' cancelled.')->withFragment('documents');
    }

    /* ---------------------------------------------------------------- both */

    /** POST /shipment-documents/{id}/ai-check — run (or re-run) the Gemini document check. */
    public function aiCheck(Request $request, int $documentId, DocumentChecker $checker): JsonResponse
    {
        set_time_limit(180);
        $document = ShipmentDocument::query()->with('shipment')->find($documentId);
        if (! $document || ! $this->canSee($request, $document->shipment)) {
            return response()->json(['ok' => false, 'error' => 'Document not found.'], 404);
        }
        $result = $checker->check($document);
        if ($result['status'] !== 'error') {
            $document->update(['ai_check' => $result]);
        }

        return response()->json(['ok' => $result['status'] !== 'error', 'check' => $result, 'error' => $result['error'] ?? null], $result['status'] === 'error' ? 502 : 200);
    }

    /* ---------------------------------------------------------------- helpers */

    private function uploadProblem(?UploadedFile $file): ?string
    {
        if (! $file || ! $file->isValid()) {
            return match ($file?->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server allows. Use a file under 10 MB.',
                UPLOAD_ERR_PARTIAL => 'The upload did not finish. Try again.',
                null, UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                default => 'The file could not be uploaded. Try again.',
            };
        }
        if ($file->getSize() < 1 || $file->getSize() > 10 * 1024 * 1024) {
            return 'File is larger than 10 MB or empty.';
        }
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $allowed = [
            'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'xls' => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-cdf', 'application/cdfv2', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        ];
        if (! isset($allowed[$extension])) {
            return 'This file type is not supported. Use PDF, JPG, PNG, XLS or XLSX.';
        }
        if (! in_array(mb_strtolower((string) $file->getMimeType()), $allowed[$extension], true)) {
            return 'The file contents do not match the selected file type.';
        }

        return null;
    }

    private function staffShipment(Request $request, int $shipmentId): Shipment
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'staff'], true), 403);
        abort_if($user->role === 'staff' && $shipment->assigned_staff_id !== null && (int) $shipment->assigned_staff_id !== (int) $user->user_id, 404);

        return $shipment;
    }

    private function canSee(Request $request, ?Shipment $shipment): bool
    {
        $user = $request->user();

        return $shipment !== null && match ($user->role) {
            'admin' => true,
            'staff' => $shipment->assigned_staff_id === null || (int) $shipment->assigned_staff_id === (int) $user->user_id,
            'client' => (int) $shipment->client_id === (int) $user->client_id,
            default => false,
        };
    }
}
