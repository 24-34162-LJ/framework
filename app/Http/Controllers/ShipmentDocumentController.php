<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentDocumentController.php
 * Feature: Documents
 * Purpose: Upload, download (one or all as ZIP) and delete documents attached to a shipment.
 */

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\ShipmentDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use ZipArchive;

class ShipmentDocumentController extends Controller
{
    public function legacyStore(Request $request, int $shipmentId, ShipmentDocumentService $documents, ActivityLogger $activity, NotificationService $notifications): JsonResponse
    {
        $file = $request->file('file');
        $documentType = $documents->canonicalType((string) $request->input('doc_type', ''));
        $remarks = trim((string) $request->input('remarks', ''));
        if (! $documentType) {
            return response()->json(['ok' => false, 'error' => 'Choose a valid document type.'], 422);
        }
        if (mb_strlen($remarks) > 255) {
            return response()->json(['ok' => false, 'error' => 'Remarks must be 255 characters or fewer.'], 422);
        }
        if (! $file || ! $file->isValid()) {
            $message = $file && $file->getError() !== UPLOAD_ERR_OK
                ? $this->uploadError($file->getError())
                : 'Choose a file to upload.';

            return response()->json(['ok' => false, 'error' => $message], 422);
        }
        if ($file->getSize() < 1 || $file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'File is larger than 10 MB or empty.'], 422);
        }
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'], true)) {
            return response()->json(['ok' => false, 'error' => 'This file type is not supported.'], 422);
        }
        $mime = mb_strtolower((string) $file->getMimeType());
        $allowedMime = match ($extension) {
            'pdf' => ['application/pdf'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'xls' => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-cdf', 'application/cdfv2', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        };
        if (! in_array($mime, $allowedMime, true)) {
            return response()->json(['ok' => false, 'error' => 'The file contents do not match the selected file type.'], 422);
        }

        $shipment = $this->accessibleShipment($request, $shipmentId, true);
        if ($shipment->archived_at) {
            return response()->json(['ok' => false, 'error' => 'Archived shipments are read-only.'], 409);
        }
        $hash = $documents->hashOf($file);
        if ($duplicate = $documents->duplicateOf((int) $shipment->shipment_id, $hash)) {
            return response()->json(['ok' => false, 'error' => 'Already uploaded as "'.$duplicate->doc_type.'" on '.($duplicate->uploaded_at?->format('M d, Y') ?? 'an earlier date').'. Duplicate skipped.'], 409);
        }

        try {
            DB::transaction(function () use ($request, $file, $shipment, $documentType, $remarks, $documents, $activity, $notifications, $hash): void {
                $storedDocument = $documents->store(
                    $file,
                    (int) $shipment->shipment_id,
                    (string) $shipment->reference_no,
                    $documentType,
                    (int) $request->user()->user_id,
                    $remarks !== '' ? $remarks : null,
                    ['file_hash' => $hash],
                );
                $activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' document uploaded: '.$documentType.' ('.basename($file->getClientOriginalName()).')');
                $notifications->forShipment($shipment, $request->user(), 'document', 'Document uploaded', $shipment->reference_no.': '.$documentType.' uploaded.');
            }, 3);

            return response()->json(['ok' => true, 'message' => 'Document uploaded.'])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'error' => 'The document could not be saved. Please try again.'], 500);
        }
    }

    public function store(Request $request, int $shipmentId, ShipmentDocumentService $documents, ActivityLogger $activity, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'doc_type' => ['required', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx'],
        ]);
        $type = $documents->canonicalType($data['doc_type']);
        abort_unless($type, 422, 'Choose a valid document type.');
        $shipment = $this->accessibleShipment($request, $shipmentId, true);
        abort_if($shipment->archived_at !== null, 409, 'Archived shipments are read-only.');
        if ($duplicate = $documents->duplicateOf((int) $shipment->shipment_id, $documents->hashOf($request->file('file')))) {
            return redirect()->route('shipments.show', $shipmentId)->withErrors(['file' => 'Already uploaded as "'.$duplicate->doc_type.'". Duplicate skipped.']);
        }

        $document = DB::transaction(fn () => $documents->store(
            $request->file('file'), (int) $shipment->shipment_id, (string) $shipment->reference_no,
            $type, (int) $request->user()->user_id, filled($data['remarks'] ?? null) ? trim($data['remarks']) : null
        ), 3);
        $activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' document uploaded: '.$type.' ('.basename($request->file('file')->getClientOriginalName()).')');
        $notifications->forShipment($shipment, $request->user(), 'document', 'Document uploaded', $shipment->reference_no.': '.$type.' uploaded.');

        return redirect()->route('shipments.show', $shipmentId)->with('success', 'Document uploaded.');
    }

    public function download(Request $request, int $documentId, ShipmentDocumentService $documents): BinaryFileResponse
    {
        $document = ShipmentDocument::query()->with('shipment')->findOrFail($documentId);
        $this->assertShipmentAccess($request, $document->shipment);
        abort_if($request->user()->role === 'client' && $document->superseded_at !== null, 404);
        $path = $documents->readablePath($document);
        abort_unless($path, 404, 'Document not found.');
        $name = preg_replace('/[\r\n"\\\\]/', '_', basename($path)) ?: 'shipment-document';
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($request->boolean('view') && in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="'.$name.'"']);
        }

        return response()->download($path, $name, $headers);
    }

    public function downloadAll(Request $request, int $shipmentId, ShipmentDocumentService $documents): Response|BinaryFileResponse
    {
        $shipment = $this->accessibleShipment($request, $shipmentId);
        $files = $shipment->documents()->accepted()->orderBy('uploaded_at')->orderBy('document_id')->get()
            ->map(fn (ShipmentDocument $document): ?array => ($path = $documents->readablePath($document))
                ? ['path' => $path, 'name' => $document->document_id.'_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $document->doc_type).'_'.basename($path)] : null)
            ->filter()->values();
        abort_if($files->isEmpty(), 404, 'No documents are available for this shipment.');
        if ($files->count() === 1 || ! class_exists(ZipArchive::class)) {
            $file = $files->first();

            return response()->download($file['path'], $file['name'], ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'astratrack_docs_');
        abort_unless($temporary !== false, 500, 'The documents could not be packaged for download.');
        $zip = new ZipArchive;
        if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporary);
            abort(500, 'The documents could not be packaged for download.');
        }
        foreach ($files as $file) {
            $zip->addFile($file['path'], $file['name']);
        }
        $zip->close();
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $shipment->reference_no) ?: 'shipment';

        return response()->download($temporary, $reference.'_documents.zip', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend(true);
    }

    public function destroy(Request $request, int $documentId, ShipmentDocumentService $documents, ActivityLogger $activity, NotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $document = ShipmentDocument::query()->with('shipment')->findOrFail($documentId);
        $shipment = $document->shipment;
        $type = (string) $document->doc_type;
        $path = (string) $document->file_path;
        $absolutePath = $documents->readablePath($document);
        DB::transaction(fn () => $document->delete(), 3);
        if ($absolutePath && is_file($absolutePath)) {
            @unlink($absolutePath);
        } elseif (! str_starts_with(str_replace('\\', '/', $path), 'uploads/')) {
            Storage::disk('local')->delete($path);
        }
        $activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' document deleted: '.$type);
        $notifications->forShipment($shipment, $request->user(), 'document', 'Document removed', $shipment->reference_no.': '.$type.' was removed.');

        return redirect()->route('shipments.show', $shipment->shipment_id)->with('success', 'Document deleted.');
    }

    public function legacyDestroy(Request $request, int $documentId, ShipmentDocumentService $documents, ActivityLogger $activity, NotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $document = ShipmentDocument::query()->with('shipment')->find($documentId);
        if (! $document) {
            return response()->json(['ok' => false, 'error' => 'Document not found.'], 404);
        }
        $shipment = $document->shipment;
        $type = (string) $document->doc_type;
        $absolutePath = $documents->readablePath($document);
        $storedPath = (string) $document->file_path;

        try {
            DB::transaction(fn () => $document->delete(), 3);
            if ($absolutePath && is_file($absolutePath)) {
                @unlink($absolutePath);
            } elseif (! str_starts_with(str_replace('\\', '/', $storedPath), 'uploads/')) {
                Storage::disk('local')->delete($storedPath);
            }
            $activity->record($request->user(), 'Shipment Document', $shipment->reference_no.' document deleted: '.$type);
            $notifications->forShipment($shipment, $request->user(), 'document', 'Document removed', $shipment->reference_no.': '.$type.' was removed.');

            return response()->json(['ok' => true, 'message' => 'Document deleted.'])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'error' => 'The document could not be deleted. Please try again.'], 500);
        }
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than 10 MB. Compress or replace this file.',
            UPLOAD_ERR_PARTIAL => 'The upload did not finish. Replace this file and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
            default => 'The file could not be uploaded. Replace or remove it.',
        };
    }

    private function accessibleShipment(Request $request, int $shipmentId, bool $write = false): Shipment
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        $this->assertShipmentAccess($request, $shipment);
        abort_if($write && $request->user()->role === 'client', 403);

        return $shipment;
    }

    private function assertShipmentAccess(Request $request, ?Shipment $shipment): void
    {
        abort_unless($shipment, 404);
        $user = $request->user();
        if ($user->role === 'client') {
            abort_unless((int) $shipment->client_id === (int) $user->client_id, 404);
        } elseif ($user->role === 'staff') {
            abort_unless($shipment->assigned_staff_id === null || (int) $shipment->assigned_staff_id === (int) $user->user_id, 404);
        } elseif ($user->role !== 'admin') {
            abort(403);
        }
    }
}
