<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentCreationController.php
 * Feature: Shipments
 * Purpose: Add Shipment page: manual entry or reading a shipping PDF with Gemini to pre-fill the form,
 *          then saving the shipment.
 */

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentRequest;
use App\Models\ShipmentStatusUpdate;
use App\Models\Transaction;
use App\Models\TransactionHistory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class ShipmentCreationController extends Controller
{
    public function create(): View
    {
        $clients = Client::query()->where('status', 'active')->orderBy('client_name')->get(['client_id', 'client_name']);
        // Converting a client's shipment request: open the review step pre-filled from its document packet.
        $shipmentRequest = null;
        if (request()->integer('request') > 0) {
            $shipmentRequest = ShipmentRequest::query()->with('client')->where('status', 'pending')->find(request()->integer('request'));
        }
        $prefill = $shipmentRequest ? [
            'id' => $shipmentRequest->request_id,
            'reference' => $shipmentRequest->referenceLabel(),
            'client_name' => $shipmentRequest->client?->client_name,
            'file_name' => $shipmentRequest->original_name,
            'file_size' => is_file(Storage::disk('local')->path($shipmentRequest->file_path)) ? filesize(Storage::disk('local')->path($shipmentRequest->file_path)) : 0,
            'notes' => $shipmentRequest->client_notes,
            'extraction' => $shipmentRequest->extraction_status === 'done' && is_array($shipmentRequest->extracted_json)
                ? $shipmentRequest->extracted_json
                : ['form' => ['client_id' => $shipmentRequest->client_id, 'mode' => 'sea'], 'checks' => [], 'warnings' => ['Auto-read did not finish for this request. Enter the details from the attached PDF.']],
        ] : null;

        // Admins must hand every new shipment to a staff member, so no shipment is left without an owner.
        $staffList = request()->user()->role === 'admin'
            ? \App\Models\User::query()->where('role', 'staff')->where('status', 'active')->orderBy('full_name')->get(['user_id', 'full_name'])
            : collect();

        return view('shipments.create', ['clients' => $clients, 'role' => request()->user()->role, 'prefill' => $prefill, 'staffList' => $staffList]);
    }

    public function checkBl(Request $request): JsonResponse
    {
        $data = $request->validate(['bl_awb_no' => ['nullable', 'string', 'max:50']]);
        $bl = trim((string) ($data['bl_awb_no'] ?? ''));
        $shipment = $bl === '' ? null : Shipment::query()->where('bl_awb_no', $bl)->first(['reference_no']);

        return response()->json(['duplicate' => $shipment !== null, 'reference_no' => $shipment?->reference_no]);
    }

    public function extract(Request $request, \App\Services\GeminiExtractor $extractor): JsonResponse
    {
        set_time_limit(180);
        // Same checks and messages as the prototype's uploadedPdf().
        $file = $request->file('file');
        $code = $file instanceof \Illuminate\Http\UploadedFile ? $file->getError() : UPLOAD_ERR_NO_FILE;
        if ($code !== UPLOAD_ERR_OK || ! $file->isValid()) {
            $message = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Upload failed: this PDF is larger than the server allows ('.ini_get('upload_max_filesize').'). Raise upload_max_filesize and post_max_size in php.ini.'
                : "Upload failed (code $code). If the file is large, check upload_max_filesize and post_max_size in php.ini.";

            return response()->json(['error' => $message], 400);
        }
        if ($file->getSize() > 25 * 1024 * 1024) {
            return response()->json(['error' => 'The file is larger than 25 MB.'], 400);
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($file->getMimeType() !== 'application/pdf' || $head !== '%PDF-') {
            return response()->json(['error' => 'Only PDF files are accepted.'], 400);
        }

        $result = $extractor->extract($file);
        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], (int) ($result['status'] ?? 502));
        }

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->input('data', ''), true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'No shipment data was received.'], 400);
        }
        $validator = Validator::make($payload, [
            'client_id' => ['required', 'integer', 'exists:clients,client_id'],
            'transaction_type' => ['required', 'in:import,export'], 'mode' => ['required', 'in:sea,air,land'],
            'bl_awb_no' => ['nullable', 'string', 'max:50'], 'shipper_name' => ['nullable', 'string', 'max:150'],
            'consignee_name' => ['nullable', 'string', 'max:150'], 'vessel_or_flight' => ['nullable', 'string', 'max:100'],
            'port_of_loading' => ['nullable', 'string', 'max:100'], 'port_of_discharge' => ['nullable', 'string', 'max:100'],
            'hs_code' => ['nullable', 'string', 'max:20'], 'total_packages' => ['nullable', 'numeric', 'min:0', 'lt:2000000000'],
            'total_gross_weight' => ['nullable', 'numeric', 'min:0', 'lt:1000000000'], 'measurement_cbm' => ['nullable', 'numeric', 'min:0', 'lt:1000000000'],
            'incoterm' => ['nullable', 'string', 'max:10'], 'invoice_no' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/'], 'invoice_value' => ['nullable', 'numeric', 'min:0', 'lt:10000000000000'],
            'freight_amount' => ['nullable', 'numeric', 'min:0', 'lt:10000000000000'], 'fob_value' => ['nullable', 'numeric', 'min:0', 'lt:10000000000000'],
            'remarks' => ['nullable', 'string', 'max:2000'], 'containers' => ['nullable', 'array', 'max:200'],
            'containers.*.container_no' => ['nullable', 'string', 'max:20'], 'containers.*.seal_no' => ['nullable', 'string', 'max:30'],
            'containers.*.size_type' => ['nullable', 'string', 'max:20'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'errors' => $validator->errors()->all()], 422);
        }
        $data = $validator->validated();
        $client = Client::query()->where('client_id', $data['client_id'])->where('status', 'active')->first();
        if (! $client) {
            return response()->json(['error' => 'Choose an active client.'], 422);
        }
        $data = $this->cleanShipmentData($data);
        if (($data['total_packages'] ?? 0) >= 2000000000) {
            return response()->json(['error' => 'Total packages must be less than 2,000,000,000.'], 422);
        }
        foreach ($data['containers'] as $container) {
            $sameNumberCount = $container['container_no'] === '' ? 0 : collect($data['containers'])->where('container_no', $container['container_no'])->count();
            if ($container['container_no'] === '' && ($container['seal_no'] !== '' || $container['size_type'] !== '')) {
                return response()->json(['error' => 'A container row with details needs a container number.'], 422);
            }
            if ($sameNumberCount > 1) {
                return response()->json(['error' => 'Container '.$container['container_no'].' is listed more than once.'], 422);
            }
        }
        if ($data['mode'] !== 'land' && ! $data['bl_awb_no']) {
            return response()->json(['error' => 'The B/L or AWB number is required.'], 422);
        }
        if ($data['mode'] === 'land' && ! $data['vessel_or_flight']) {
            return response()->json(['error' => 'The truck plate is required for land shipments.'], 422);
        }
        if ($data['bl_awb_no'] && Shipment::query()->where('bl_awb_no', $data['bl_awb_no'])->exists()) {
            return response()->json(['error' => 'This B/L or AWB number is already used by an existing shipment.'], 422);
        }

        $shipmentRequest = null;
        if ($request->filled('shipment_request_id')) {
            $shipmentRequest = ShipmentRequest::query()->where('status', 'pending')->find($request->integer('shipment_request_id'));
            if (! $shipmentRequest) {
                return response()->json(['error' => 'This shipment request was already handled.'], 409);
            }
            if ((int) $shipmentRequest->client_id !== (int) $client->client_id) {
                return response()->json(['error' => 'The client must stay the same as the client who sent the request.'], 422);
            }
        }

        $file = $request->file('file');
        if ($file && ! $file->isValid()) {
            return response()->json(['error' => 'The PDF upload did not complete. Check the PHP upload limits and try again.'], 400);
        }
        if ($file) {
            $request->validate(['file' => ['file', 'mimes:pdf', 'max:25600']]);
            if (! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                return response()->json(['error' => 'Only valid PDF files are accepted.'], 422);
            }
        }

        $actor = $request->user();
        $staffId = $actor->role === 'staff' ? $actor->user_id : null;
        if ($actor->role === 'admin') {
            $staffId = \App\Models\User::query()->where('role', 'staff')->where('status', 'active')->whereKey($request->integer('assigned_staff_id'))->value('user_id');
            if (! $staffId) {
                return response()->json(['error' => 'Choose the staff member responsible for this shipment.'], 422);
            }
        }
        $storedPath = null;
        try {
            $shipment = DB::transaction(function () use ($data, $actor, $client, $staffId, $file, $shipmentRequest, &$storedPath): Shipment {
                $reference = $this->nextReference();
                $shipment = Shipment::query()->create([
                    ...$data,
                    'reference_no' => $reference,
                    'client_id' => $client->client_id,
                    'assigned_staff_id' => $staffId,
                    'currency' => $data['currency'] ?: 'USD',
                    'current_status' => 'Documents received',
                ]);
                foreach ($data['containers'] as $container) {
                    if ($container['container_no'] !== '') {
                        $shipment->containers()->create(['container_no' => $container['container_no'], 'seal_no' => $container['seal_no'] ?: null, 'size_type' => $container['size_type'] ?: null]);
                    }
                }
                ShipmentStatusUpdate::query()->create([
                    'shipment_id' => $shipment->shipment_id, 'stage' => 'Documents received', 'status' => 'Completed',
                    'remarks' => $shipmentRequest ? 'Shipment created from client request '.$shipmentRequest->referenceLabel().'.' : ($file ? 'Shipment created from the uploaded shipping documents.' : 'Shipment created from manual entry.'),
                    'updated_by' => $actor->user_id,
                ]);
                if ($file) {
                    $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                    $fileName = trim(substr((string) $fileName, 0, 80), '._-') ?: 'shipping_documents';
                    $storedPath = $file->storeAs('shipment-documents/'.$reference, $fileName.'.pdf', 'local');
                    if (! $storedPath) {
                        throw new \RuntimeException('Could not store the uploaded PDF.');
                    }
                    ShipmentDocument::query()->create(['shipment_id' => $shipment->shipment_id, 'doc_type' => 'B/L or AWB', 'file_path' => $storedPath, 'uploaded_by' => $actor->user_id,
                        'file_hash' => hash_file('sha256', $file->getRealPath()), 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255)]);
                }
                if ($shipmentRequest) {
                    // Attach the client's packet to the new shipment and close the request.
                    $source = Storage::disk('local')->path($shipmentRequest->file_path);
                    if (is_file($source)) {
                        $fileName = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string) $shipmentRequest->original_name, PATHINFO_FILENAME)), '._-') ?: 'client_documents';
                        $copyPath = 'shipment-documents/'.$reference.'/'.substr($fileName, 0, 80).'.pdf';
                        Storage::disk('local')->copy($shipmentRequest->file_path, $copyPath);
                        $storedPath = $storedPath ?? $copyPath;
                        ShipmentDocument::query()->create(['shipment_id' => $shipment->shipment_id, 'doc_type' => 'B/L or AWB', 'file_path' => $copyPath,
                            'uploaded_by' => $shipmentRequest->submitted_by, 'source' => 'client', 'review_status' => ShipmentDocument::REVIEW_ACCEPTED,
                            'reviewed_by' => $actor->user_id, 'reviewed_at' => now(), 'file_hash' => $shipmentRequest->file_hash,
                            'original_name' => $shipmentRequest->original_name, 'remarks' => 'From client request '.$shipmentRequest->referenceLabel()]);
                    }
                    $shipmentRequest->update(['status' => 'converted', 'shipment_id' => $shipment->shipment_id, 'reviewed_by' => $actor->user_id, 'reviewed_at' => now()]);
                    app(\App\Services\NotificationService::class)->forShipmentRoles($shipment, $actor, ['client'], 'created', 'Shipment request accepted',
                        $shipmentRequest->referenceLabel().' is now shipment '.$reference.'. Track it under My Shipments.', key: 'request_accepted');
                }
                $transaction = Transaction::query()->create(['client_id' => $client->client_id, 'shipment_id' => $shipment->shipment_id, 'status' => 'pending']);
                $history = 'Shipment '.$reference.' created by '.($shipmentRequest ? 'client request '.$shipmentRequest->referenceLabel() : ($file ? 'document review' : 'manual entry'));
                if ($data['bl_awb_no']) {
                    $history .= ' (B/L or AWB '.$data['bl_awb_no'].')';
                }
                TransactionHistory::query()->create(['transaction_id' => $transaction->transaction_id, 'event_type' => 'Shipment created', 'description' => mb_substr($history.'.', 0, 255), 'performed_by' => $actor->user_id]);
                app(\App\Services\ActivityLogger::class)->record($actor, 'Shipment Update', 'Shipment '.$reference.' created from '.($shipmentRequest ? 'client request '.$shipmentRequest->referenceLabel() : ($file ? 'uploaded documents' : 'manual entry')).' ('.$client->client_name.($data['bl_awb_no'] ? ', B/L or AWB '.$data['bl_awb_no'] : '').')');
                app(\App\Services\NotificationService::class)->forShipment($shipment, $actor, 'created', 'Shipment created', $reference.' was created and is awaiting documents.',
                    key: $shipmentRequest ? 'shipment_created_from_request' : null);

                return $shipment;
            }, 3);
        } catch (QueryException $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            if ((string) $exception->getCode() === '23000' && $data['bl_awb_no']) {
                return response()->json(['error' => 'This B/L or AWB number is already used by another shipment.'], 422);
            }
            report($exception);

            return response()->json(['error' => 'The shipment could not be saved. Please try again.'], 500);
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            report($exception);

            return response()->json(['error' => 'The shipment could not be saved. Please try again.'], 500);
        }

        return response()->json(['ok' => true, 'reference_no' => $shipment->reference_no, 'shipment_id' => $shipment->shipment_id]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanShipmentData(array $data): array
    {
        foreach (['bl_awb_no', 'shipper_name', 'consignee_name', 'vessel_or_flight', 'port_of_loading', 'port_of_discharge', 'hs_code', 'incoterm', 'invoice_no', 'remarks'] as $field) {
            $data[$field] = isset($data[$field]) && trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;
        }
        $data['incoterm'] = $data['incoterm'] ? strtoupper($data['incoterm']) : null;
        $data['currency'] = isset($data['currency']) && trim((string) $data['currency']) !== '' ? strtoupper((string) $data['currency']) : 'USD';
        // Blank numeric fields must be NULL (MySQL rejects '' for DECIMAL/INT columns).
        foreach (['total_packages', 'total_gross_weight', 'measurement_cbm', 'invoice_value', 'freight_amount', 'fob_value'] as $field) {
            if (array_key_exists($field, $data) && (is_null($data[$field]) || trim((string) $data[$field]) === '')) {
                $data[$field] = null;
            }
        }
        if (isset($data['total_packages'])) {
            $data['total_packages'] = (int) round((float) $data['total_packages']);
        }
        $data['containers'] = collect($data['containers'] ?? [])->map(fn (array $row): array => [
            'container_no' => strtoupper(str_replace(' ', '', trim((string) ($row['container_no'] ?? '')))),
            'seal_no' => trim((string) ($row['seal_no'] ?? '')), 'size_type' => trim((string) ($row['size_type'] ?? '')),
        ])->values()->all();
        if ($data['mode'] !== 'sea') {
            $data['containers'] = [];
        }

        return $data;
    }

    private function nextReference(): string
    {
        $prefix = 'AST-'.now()->format('Y').'-';
        $references = DB::table('shipments')->where('reference_no', 'like', $prefix.'%')->lockForUpdate()->pluck('reference_no');
        $lastNumber = $references->map(fn (string $reference): int => preg_match('/^AST-\d{4}-(\d+)$/', $reference, $matches) ? (int) $matches[1] : 0)->max();

        return $prefix.str_pad((string) ((int) $lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

}
