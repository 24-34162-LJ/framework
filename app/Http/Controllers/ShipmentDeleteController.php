<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentDeleteController.php
 * Feature: Shipments
 * Purpose: Admin-only permanent delete of a shipment entered by mistake (duplicate, test, wrong client).
 *          Refused when the shipment has any bill, so billing and payment history is never lost;
 *          those shipments should be archived instead.
 */

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\ActivityLogger;
use App\Services\ShipmentDocumentService;
use App\Support\ShipmentListUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShipmentDeleteController extends Controller
{
    public function __invoke(Request $request, int $shipmentId, ShipmentDocumentService $documents, ActivityLogger $activity): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $listUrl = ShipmentListUrl::for($request->user());
        $typed = trim((string) $request->input('confirm_reference', ''));

        $result = DB::transaction(function () use ($shipmentId, $typed, $documents): array {
            $shipment = Shipment::query()->lockForUpdate()->find($shipmentId);
            if (! $shipment) {
                return ['error' => 'Shipment not found. It may already have been deleted.'];
            }
            if ($typed !== (string) $shipment->reference_no) {
                return ['error' => 'Type the shipment reference exactly to confirm the delete.'];
            }
            if (DB::table('billings')->where('shipment_id', $shipmentId)->exists()) {
                return ['error' => $shipment->reference_no.' has billing records, so it cannot be deleted. Archive it instead.'];
            }

            // Collect stored files first; they are removed only after the rows are gone.
            $files = ShipmentDocument::query()->where('shipment_id', $shipmentId)->get()
                ->map(fn (ShipmentDocument $document): array => ['path' => (string) $document->file_path, 'absolute' => $documents->readablePath($document)])
                ->all();

            $transactionIds = DB::table('transactions')->where('shipment_id', $shipmentId)->pluck('transaction_id');
            DB::table('transaction_history')->whereIn('transaction_id', $transactionIds)->delete();
            DB::table('transactions')->where('shipment_id', $shipmentId)->delete();
            DB::table('document_requests')->where('shipment_id', $shipmentId)->delete();
            DB::table('shipment_requests')->where('shipment_id', $shipmentId)->update(['shipment_id' => null]);
            foreach (['notification_deliveries', 'notifications', 'shipment_documents', 'deliveries', 'customs_processing', 'containers', 'shipment_status_updates'] as $table) {
                DB::table($table)->where('shipment_id', $shipmentId)->delete();
            }
            $shipment->delete();

            return ['reference' => (string) $shipment->reference_no, 'files' => $files];
        }, 3);

        if (isset($result['error'])) {
            return redirect()->to($listUrl)->with('error', $result['error']);
        }

        foreach ($result['files'] as $file) {
            if ($file['absolute'] && is_file($file['absolute'])) {
                @unlink($file['absolute']);
            } elseif ($file['path'] !== '' && ! str_starts_with(str_replace('\\', '/', $file['path']), 'uploads/')) {
                Storage::disk('local')->delete($file['path']);
            }
        }
        $activity->record($request->user(), 'Shipment Update', 'Shipment '.$result['reference'].' permanently deleted');

        return redirect()->to($listUrl)->with('success', 'Shipment '.$result['reference'].' was deleted.');
    }
}
