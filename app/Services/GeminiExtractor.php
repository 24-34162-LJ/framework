<?php

/*
 * FILE PURPOSE
 * File   : app/Services/GeminiExtractor.php
 * Feature: Shipments
 * Purpose: Reads a shipping-document PDF with Gemini and returns a draft to pre-fill the Add Shipment
 *          form.
 */

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\UploadedFile;

/**
 * Reads a shipping-document PDF with Gemini and returns a draft for the Add Shipment form.
 * Port of the prototype's shipment_add.php (callGemini, normalizeExtraction, runChecks, matchClient).
 */
class GeminiExtractor
{
    private const PROMPT = <<<'PROMPT'
You are a data-entry assistant for a customs brokerage in the Philippines.
The attached PDF may contain several shipping documents in one file (Bill of Lading / Sea Waybill,
commercial invoice, packing list, marine insurance certificate). Read ALL pages and extract the data below.

Rules:
- Respond with ONLY valid JSON. No markdown, no explanation.
- Copy values exactly as printed. Do not guess. Use null when a value is not in the document.
- Numbers must be JSON numbers (no commas, no currency symbols).
- "shipper" and "consignee": the company NAME only, without the street address.
- "incoterms": the 3-letter Incoterm code only (for example CIP), without the place.
- "currency": the 3-letter code (for example USD).
- "insurance" (in commercial_invoice) is only used for an automatic check; still copy it exactly.
- Ignore the insurance certificate: do not extract its fields.
- If the same field appears in different documents with different values, use the value from the
  document the field belongs to, and add a short note to "warnings".

JSON shape:
{
  "bill_of_lading": {
    "bl_number": null, "shipper": null, "consignee": null, "vessel_voyage": null,
    "port_of_loading": null, "port_of_discharge": null, "hs_code": null,
    "goods_description": null, "total_units": null, "gross_weight_kg": null, "measurement_cbm": null
  },
  "commercial_invoice": {
    "invoice_no": null, "incoterms": null, "quantity": null, "unit_cost": null,
    "total_amount": null, "insurance": null, "freight": null, "fob_value": null, "currency": null
  },
  "containers": [
    { "container_no": null, "seal_no": null, "size_type": null, "units": null }
  ],
  "warnings": []
}
PROMPT;

    public function __construct(private GeminiClient $gemini) {}

    /** @return array{form?: array<string, mixed>, checks?: array<int, array{name: string, ok: bool}>, warnings?: array<int, string>, error?: string, status?: int} */
    public function extract(UploadedFile $file): array
    {
        return $this->extractFromBytes((string) file_get_contents($file->getRealPath()));
    }

    /** Same as extract(), for a PDF already stored on disk (client shipment requests). */
    public function extractFromBytes(string $pdfBytes): array
    {
        if (! $this->gemini->configured()) {
            return ['error' => 'The Gemini API key is missing. Add GEMINI_API_KEY to the Laravel .env file. You can still enter the shipment manually.', 'status' => 500];
        }
        $result = $this->gemini->generateJson('application/pdf', $pdfBytes, self::PROMPT);
        if (isset($result['error'])) {
            return $result;
        }
        $raw = $result['data'];

        $form = $this->normalize($raw);
        $clients = Client::query()->where('status', 'active')->get(['client_id', 'client_name']);
        $inPh = $this->isPhPlace($form['port_of_discharge']);
        $outPh = $this->isPhPlace($form['port_of_loading']);
        $type = $inPh && ! $outPh ? 'import' : ($outPh && ! $inPh ? 'export' : '');

        // Import: the client is the consignee. Export: the client is the shipper.
        if ($type === 'export') {
            $clientId = $this->matchClient($clients, $form['shipper_name']);
        } else {
            $clientId = $this->matchClient($clients, $form['consignee_name']);
            if ($clientId === null && $type === '') {
                $clientId = $this->matchClient($clients, $form['shipper_name']);
            }
        }
        $form['client_id'] = $clientId;
        $form['transaction_type'] = $type;
        $form['mode'] = 'sea';

        $warnings = [];
        foreach (is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [] as $warning) {
            if (is_scalar($warning) && trim((string) $warning) !== '') {
                $warnings[] = (string) $warning;
            }
        }

        return ['form' => $form, 'checks' => $this->runChecks($raw), 'warnings' => $warnings];
    }

    /** Raw Gemini JSON -> flat fields named like the shipments columns. */
    private function normalize(array $data): array
    {
        $bill = is_array($data['bill_of_lading'] ?? null) ? $data['bill_of_lading'] : [];
        $invoice = is_array($data['commercial_invoice'] ?? null) ? $data['commercial_invoice'] : [];

        $incoterm = null; // column is VARCHAR(10): keep only the 3-letter code
        if (! empty($invoice['incoterms']) && preg_match('/\b(EXW|FCA|FAS|FOB|CFR|CIF|CPT|CIP|DAP|DPU|DDP|DAT)\b/i', (string) $invoice['incoterms'], $match)) {
            $incoterm = strtoupper($match[1]);
        }
        $currency = $this->cleanStr($invoice['currency'] ?? null, 3);
        if ($currency !== null) {
            $currency = strtoupper($currency);
        }

        $containers = [];
        foreach (is_array($data['containers'] ?? null) ? $data['containers'] : [] as $container) {
            if (! is_array($container)) {
                continue;
            }
            $number = $this->cleanStr($container['container_no'] ?? null, 20);
            if ($number === null) {
                continue;
            }
            $containers[] = [
                'container_no' => strtoupper(str_replace(' ', '', $number)),
                'seal_no' => $this->cleanStr($container['seal_no'] ?? null, 30),
                'size_type' => $this->cleanStr($container['size_type'] ?? null, 20),
            ];
        }
        $packages = $this->cleanNum($bill['total_units'] ?? null);

        return [
            'bl_awb_no' => $this->cleanStr($bill['bl_number'] ?? null, 50),
            'shipper_name' => $this->cleanStr($bill['shipper'] ?? null, 150),
            'consignee_name' => $this->cleanStr($bill['consignee'] ?? null, 150),
            'vessel_or_flight' => $this->cleanStr($bill['vessel_voyage'] ?? null, 100),
            'port_of_loading' => $this->cleanStr($bill['port_of_loading'] ?? null, 100),
            'port_of_discharge' => $this->cleanStr($bill['port_of_discharge'] ?? null, 100),
            'hs_code' => $this->cleanStr($bill['hs_code'] ?? null, 20),
            'total_packages' => $packages === null ? null : (int) round($packages),
            'total_gross_weight' => $this->cleanNum($bill['gross_weight_kg'] ?? null),
            'measurement_cbm' => $this->cleanNum($bill['measurement_cbm'] ?? null),
            'incoterm' => $incoterm,
            'invoice_no' => $this->cleanStr($invoice['invoice_no'] ?? null, 50),
            'currency' => $currency,
            'invoice_value' => $this->cleanNum($invoice['total_amount'] ?? null),
            'freight_amount' => $this->cleanNum($invoice['freight'] ?? null),
            'fob_value' => $this->cleanNum($invoice['fob_value'] ?? null),
            'remarks' => $this->cleanStr($bill['goods_description'] ?? null, 1000),
            'containers' => $containers,
        ];
    }

    /** Arithmetic cross-checks on the raw extraction, so a misread number is caught before saving. */
    private function runChecks(array $data): array
    {
        $checks = [];
        $invoice = is_array($data['commercial_invoice'] ?? null) ? $data['commercial_invoice'] : [];
        $bill = is_array($data['bill_of_lading'] ?? null) ? $data['bill_of_lading'] : [];
        $num = static fn ($value): bool => is_int($value) || is_float($value);

        if ($num($invoice['total_amount'] ?? null) && $num($invoice['insurance'] ?? null) && $num($invoice['freight'] ?? null) && $num($invoice['fob_value'] ?? null)) {
            $diff = abs($invoice['total_amount'] - $invoice['insurance'] - $invoice['freight'] - $invoice['fob_value']);
            $checks[] = ['name' => 'Invoice total - insurance - freight = FOB value', 'ok' => $diff < 0.02];
        }
        if ($num($invoice['quantity'] ?? null) && $num($invoice['unit_cost'] ?? null) && $num($invoice['total_amount'] ?? null)) {
            $diff = abs($invoice['quantity'] * $invoice['unit_cost'] - $invoice['total_amount']);
            $checks[] = ['name' => 'Quantity x unit cost = invoice total', 'ok' => $diff < 0.02];
        }
        $containerUnits = 0;
        foreach (is_array($data['containers'] ?? null) ? $data['containers'] : [] as $container) {
            if (is_array($container) && $num($container['units'] ?? null)) {
                $containerUnits += $container['units'];
            }
        }
        if ($num($bill['total_units'] ?? null) && $containerUnits > 0) {
            $checks[] = ['name' => 'Container units add up to B/L total units', 'ok' => $containerUnits == $bill['total_units']];
        }
        if ($num($bill['total_units'] ?? null) && $num($invoice['quantity'] ?? null)) {
            $checks[] = ['name' => 'B/L units match invoice quantity', 'ok' => $bill['total_units'] == $invoice['quantity']];
        }

        return $checks;
    }

    /** Text: trims, collapses spaces, cuts to the column length, empty -> null. */
    private function cleanStr($value, int $max): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    /** Number: accepts 1,234.50 or 1234.5; returns null when empty or not a number. */
    private function cleanNum($value): ?float
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $clean = str_replace([',', ' '], '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function isPhPlace(?string $text): bool
    {
        return $text !== null && (bool) preg_match('/philippines|manila|cebu|davao|subic|batangas|iloilo|cagayan|clark|\bph\b/i', $text);
    }

    private function normName(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $value = (string) preg_replace('/\b(inc|incorporated|corp|corporation|co|company|ltd|limited|llc|opc|the)\b/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function matchClient(iterable $clients, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $needle = $this->normName($name);
        if (strlen($needle) < 3) {
            return null;
        }
        $best = null;
        $bestLength = 0;
        foreach ($clients as $client) {
            $candidate = $this->normName((string) $client->client_name);
            if (strlen($candidate) < 3) {
                continue;
            }
            if ((str_contains($needle, $candidate) || str_contains($candidate, $needle)) && strlen($candidate) > $bestLength) {
                $best = (int) $client->client_id;
                $bestLength = strlen($candidate);
            }
        }

        return $best;
    }
}
