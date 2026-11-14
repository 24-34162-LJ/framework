<?php

namespace App\Services;

use App\Models\ShipmentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ShipmentDocumentService
{
    /** @return array<string, array{label: string, required: bool, aliases: list<string>}> */
    public function catalog(): array
    {
        return [
            'bl' => ['label' => 'B/L or AWB', 'required' => true, 'aliases' => ['b/l or awb', 'bl or awb', 'b/l', 'bill of lading', 'awb', 'bl']],
            'invoice' => ['label' => 'Commercial Invoice', 'required' => true, 'aliases' => ['commercial invoice', 'invoice']],
            'packing' => ['label' => 'Packing List', 'required' => true, 'aliases' => ['packing list', 'packing']],
            'entry' => ['label' => 'Import Entry', 'required' => true, 'aliases' => ['import entry', 'import declaration', 'entry']],
            'assessment' => ['label' => 'Assessment Notice', 'required' => true, 'aliases' => ['assessment notice', 'assessment']],
            'duties' => ['label' => 'Duties Receipt', 'required' => true, 'aliases' => ['duties receipt', 'duties and taxes receipt', 'duties', 'receipt']],
            'permit' => ['label' => 'Permit (if needed)', 'required' => false, 'aliases' => ['permit']],
        ];
    }

    /** Catalog key (bl, invoice, …) for a stored document type label, or null. */
    public function slotKey(string $type): ?string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));
        foreach ($this->catalog() as $key => $meta) {
            foreach ($meta['aliases'] as $alias) {
                if ($normalized === $alias || str_contains($normalized, $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public function canonicalType(string $type): ?string
    {
        $normalized = rtrim(mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $type))), " .\t\n\r\0\x0B");
        foreach ($this->catalog() as $meta) {
            if (in_array($normalized, $meta['aliases'], true)) {
                return $meta['label'];
            }
            foreach ($meta['aliases'] as $alias) {
                if ($alias !== '' && str_contains($normalized, $alias)) {
                    return $meta['label'];
                }
            }
        }

        return null;
    }

    /** SHA-256 of an uploaded file, used for duplicate detection. */
    public function hashOf(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getRealPath());
    }

    /** The current (not replaced) document on this shipment with the same file contents, if any. */
    public function duplicateOf(int $shipmentId, string $hash): ?ShipmentDocument
    {
        return ShipmentDocument::query()->where('shipment_id', $shipmentId)->where('file_hash', $hash)
            ->whereNull('superseded_at')->where(fn ($q) => $q->where('review_status', '<>', ShipmentDocument::REVIEW_REJECTED)->orWhereNull('review_status'))
            ->orderByDesc('document_id')->first();
    }

    /**
     * @param  array{source?: string, review_status?: string, replaces_document_id?: int|null, file_hash?: string}  $extra
     */
    public function store(UploadedFile $file, int $shipmentId, string $reference, string $type, ?int $uploadedBy, ?string $remarks, array $extra = []): ShipmentDocument
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'];
        abort_unless(in_array($extension, $allowed, true), 422, 'This file type is not supported.');
        $mime = (string) $file->getMimeType();
        $validMime = match ($extension) {
            'pdf' => ['application/pdf'], 'jpg', 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'xls' => ['application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-cdf', 'application/cdfv2', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        };
        abort_unless(in_array(strtolower($mime), $validMime, true), 422, 'The file contents do not match the selected file type.');

        $folder = 'uploads/'.(trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $reference) ?? '', '_') ?: 'shipment');
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $type) ?? '', '-')) ?: 'document';
        $path = $file->storeAs($folder, $slug.'_'.now()->format('Ymd-His').'_'.bin2hex(random_bytes(4)).'.'.$extension, 'local');
        if (! $path) {
            throw new \RuntimeException('The file could not be saved. Try again.');
        }

        try {
            $document = ShipmentDocument::query()->create([
                'shipment_id' => $shipmentId, 'doc_type' => $type, 'file_path' => $path,
                'remarks' => $remarks, 'uploaded_by' => $uploadedBy,
                'source' => $extra['source'] ?? 'staff',
                'review_status' => $extra['review_status'] ?? ShipmentDocument::REVIEW_ACCEPTED,
                'file_hash' => $extra['file_hash'] ?? $this->hashOf($file),
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'replaces_document_id' => $extra['replaces_document_id'] ?? null,
            ]);
            if (! empty($extra['replaces_document_id'])) {
                // Keep the older version as history; it no longer counts or shows as current.
                ShipmentDocument::query()->where('document_id', $extra['replaces_document_id'])->where('shipment_id', $shipmentId)
                    ->update(['superseded_at' => now()]);
            }

            return $document;
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function readablePath(ShipmentDocument $document): ?string
    {
        $relative = str_replace('\\', '/', (string) $document->file_path);
        if ($relative !== '' && ! str_contains($relative, '..') && Storage::disk('local')->exists($relative)) {
            return Storage::disk('local')->path($relative);
        }

        if (! str_starts_with($relative, 'uploads/')) {
            return null;
        }
        $root = realpath(storage_path('app/private/uploads'));
        $candidate = realpath(storage_path('app/private/'.$relative));

        return $root && $candidate && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) && is_file($candidate) ? $candidate : null;
    }

    /** @param iterable<array<string, mixed>|object> $documents
     * @return array{slots: list<array{key: string, label: string, required: bool, received: bool, count: int}>, requiredTotal: int, requiredReceived: int}
     */
    public function checklist(iterable $documents): array
    {
        $slots = [];
        foreach ($this->catalog() as $key => $meta) {
            $slots[$key] = ['key' => $key, 'label' => $meta['label'], 'required' => $meta['required'], 'received' => false, 'count' => 0, 'pending' => 0, 'rejected' => 0];
        }

        foreach ($documents as $document) {
            $type = is_array($document) ? ($document['doc_type'] ?? '') : ($document->doc_type ?? '');
            $status = (is_array($document) ? ($document['review_status'] ?? null) : ($document->review_status ?? null)) ?? ShipmentDocument::REVIEW_ACCEPTED;
            $superseded = is_array($document) ? ! empty($document['superseded_at']) : ! empty($document->superseded_at);
            if ($superseded) {
                continue; // older versions never count
            }
            $slotKey = $this->slotKey((string) $type);
            if ($slotKey === null) {
                continue;
            }
            if ($status === ShipmentDocument::REVIEW_ACCEPTED) {
                $slots[$slotKey]['received'] = true;
                $slots[$slotKey]['count']++;
            } elseif ($status === ShipmentDocument::REVIEW_PENDING) {
                $slots[$slotKey]['pending']++;
            } else {
                $slots[$slotKey]['rejected']++;
            }
        }

        $required = collect($slots)->where('required', true);

        return ['slots' => array_values($slots), 'requiredTotal' => $required->count(), 'requiredReceived' => $required->where('received', true)->count()];
    }
}
