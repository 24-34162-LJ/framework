<?php

/*
 * FILE PURPOSE
 * File   : app/Services/DocumentChecker.php
 * Feature: Documents
 * Purpose: Asks Gemini if an uploaded file is really the document type chosen and if its numbers match
 *          the shipment (advice for staff only).
 */

namespace App\Services;

use App\Models\ShipmentDocument;

/**
 * Asks Gemini whether an uploaded file is the document type the uploader chose and whether its
 * reference numbers belong to this shipment. The result is advice for staff review, never a decision.
 */
class DocumentChecker
{
    public function __construct(private GeminiClient $gemini, private ShipmentDocumentService $documents) {}

    public function supports(ShipmentDocument $document): bool
    {
        return in_array($this->mimeFor((string) $document->file_path), ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /** @return array{status: string, checked_at: string, detected_type?: string|null, type_matches?: bool|null, shipment_match?: string, matched_numbers?: list<string>, found_numbers?: list<string>, summary?: string, issues?: list<string>, error?: string} */
    public function check(ShipmentDocument $document): array
    {
        $document->loadMissing('shipment.containers');
        $shipment = $document->shipment;
        $base = ['checked_at' => now()->toIso8601String()];
        if (! $this->supports($document)) {
            return $base + ['status' => 'skipped', 'summary' => 'Automatic check is available for PDF, JPG and PNG files only.'];
        }
        $path = $this->documents->readablePath($document);
        if (! $path) {
            return $base + ['status' => 'error', 'error' => 'The file is not available on the server.'];
        }

        $known = array_values(array_filter([
            $shipment->bl_awb_no, $shipment->invoice_no, $shipment->reference_no,
            ...$shipment->containers->pluck('container_no')->all(),
        ], fn ($value) => filled($value)));
        $types = implode(', ', array_column($this->documents->catalog(), 'label'));
        $prompt = <<<PROMPT
You check shipping documents uploaded to a customs brokerage in the Philippines.
The uploader labelled this file as: "{$document->doc_type}".
Known document types: {$types}, Other.
Respond with ONLY valid JSON:
{"detected_type": "<one of the known types or Other>", "type_matches_label": true|false,
 "reference_numbers": ["every B/L, AWB, waybill, invoice, entry and container number printed in the file"],
 "summary": "<one short sentence describing the document>", "issues": ["<problems a reviewer should know, e.g. unreadable pages, missing signatures>"]}
Copy numbers exactly as printed. Do not guess.
PROMPT;

        $result = $this->gemini->generateJson($this->mimeFor((string) $document->file_path), (string) file_get_contents($path), $prompt);
        if (isset($result['error'])) {
            return $base + ['status' => 'error', 'error' => $result['error']];
        }
        $data = $result['data'];
        $found = collect($data['reference_numbers'] ?? [])->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value))->unique()->values()->all();
        $normalize = fn (string $value): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
        $foundNormalized = array_map($normalize, $found);
        $matched = array_values(array_filter($known, function ($value) use ($normalize, $foundNormalized): bool {
            $needle = $normalize((string) $value);

            return $needle !== '' && collect($foundNormalized)->contains(fn ($candidate) => $candidate !== '' && (str_contains($candidate, $needle) || str_contains($needle, $candidate)));
        }));
        $shipmentMatch = $matched ? 'match' : ($found ? 'mismatch' : 'unclear');
        $detected = is_string($data['detected_type'] ?? null) ? mb_substr($data['detected_type'], 0, 60) : null;
        $typeMatches = is_bool($data['type_matches_label'] ?? null) ? $data['type_matches_label'] : null;
        $issues = collect($data['issues'] ?? [])->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')->map(fn ($value) => mb_substr((string) $value, 0, 200))->values()->all();
        if ($typeMatches === false) {
            array_unshift($issues, 'This looks like '.($detected ?: 'a different document').', not '.$document->doc_type.'.');
        }
        if ($shipmentMatch === 'mismatch') {
            array_unshift($issues, 'None of the numbers in this file match this shipment ('.implode(', ', array_slice($known, 0, 3)).').');
        }

        return $base + [
            'status' => ($typeMatches === false || $shipmentMatch === 'mismatch') ? 'warning' : 'ok',
            'detected_type' => $detected,
            'type_matches' => $typeMatches,
            'shipment_match' => $shipmentMatch,
            'matched_numbers' => $matched,
            'found_numbers' => array_slice($found, 0, 20),
            'summary' => mb_substr((string) ($data['summary'] ?? ''), 0, 255),
            'issues' => array_slice($issues, 0, 6),
        ];
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/octet-stream',
        };
    }
}
