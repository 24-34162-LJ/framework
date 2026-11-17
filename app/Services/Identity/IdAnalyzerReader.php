<?php

/*
 * FILE PURPOSE
 * File   : app/Services/Identity/IdAnalyzerReader.php
 * Feature: ID Recognition
 * Purpose: ID reader that uses the ID Analyzer API to scan an ID photo, and converts its answer to our
 *          own fields.
 */

namespace App\Services\Identity;

/**
 * Reads an ID photo with the ID Analyzer v2 API (POST /scan) and maps the answer onto the
 * same fields GeminiIdReader returns, so PhIdRules keeps making the final decision.
 * Docs: https://developer.idanalyzer.com/help/understanding-scan-response
 */
class IdAnalyzerReader implements IdDocumentReader
{
    /** ID Analyzer warning codes that point to a forged, edited or non-original document. */
    private const TAMPER_CODES = [
        'FAKE_ID', 'IMAGE_FORGERY', 'TEXT_FORGERY', 'ARTIFICIAL_IMAGE', 'ARTIFICIAL_TEXT', 'IMAGE_EDITED',
        'INVALID_CAMERA_PERSPECTIVE', 'BLACK_WHITE_DOCUMENT', 'FEATURE_VERIFICATION_FAILED', 'CHECK_DIGIT_FAILED',
        'RECAPTURED_DOCUMENT', 'SCREEN_DETECTED',
    ];

    /** ID Analyzer warning codes about photo quality, mapped to the words the client sees. */
    private const QUALITY_CODES = [
        'IMAGE_TOO_BLURRY' => 'blurry',
        'IMAGE_TOO_SMALL' => 'too_small',
        'IMAGE_TOO_DARK' => 'too_dark',
        'IMAGE_GLARE' => 'glare',
        'DOCUMENT_CROPPED' => 'cropped',
    ];

    /** Keywords in ID Analyzer's documentName for Philippine IDs that are not passports or licenses. */
    private const NAME_KEYWORDS = [
        'philsys' => ['PHILSYS', 'PHILIPPINE IDENTIFICATION', 'NATIONAL ID', 'PHILID', 'PAMBANSANG'],
        'umid' => ['UMID', 'UNIFIED MULTI'],
        'sss' => ['SSS', 'SOCIAL SECURITY'],
        'prc' => ['PRC', 'PROFESSIONAL REGULATION'],
        'postal' => ['POSTAL', 'PHLPOST'],
        'voters' => ['VOTER', 'COMELEC'],
        'tin' => ['TIN', 'TAXPAYER', 'BUREAU OF INTERNAL REVENUE'],
        'philhealth' => ['PHILHEALTH'],
    ];

    /** Selfie problems that mean the selfie is not the ID holder, or not a live person: the ID is rejected. */
    public const FACE_FAIL_CODES = ['FACE_MISMATCH', 'FACE_LIVENESS_ERR', 'RECAPTURED_FACE', 'FACE_IDENTICAL'];

    /** Selfie problems that only mean the check could not run properly: an admin looks at it. */
    private const FACE_REVIEW_CODES = ['SELFIE_FACE_NOT_FOUND', 'SELFIE_MULTIPLE_FACES', 'SELFIE_FACE_LANDMARK_ERR', 'DOCUMENT_FACE_LANDMARK_ERR'];

    public function __construct(private IdAnalyzerClient $client) {}

    public function name(): string
    {
        return 'idanalyzer';
    }

    public function read(string $mimeType, string $bytes): array
    {
        $result = $this->client->post('/scan', [
            'profile' => (string) config('services.idanalyzer.profile', 'security_medium'),
            'document' => base64_encode($bytes),
        ]);

        return isset($result['error']) ? ['error' => $result['error']] : ['data' => $this->map($result['data'])];
    }

    /** Turn the /scan response into the reader fields PhIdRules understands. */
    public function map(array $body): array
    {
        $fields = is_array($body['data'] ?? null) ? $body['data'] : [];
        $warnings = collect(is_array($body['warning'] ?? null) ? $body['warning'] : [])
            ->filter(fn ($warning) => is_array($warning) && is_string($warning['code'] ?? null));
        $codes = $warnings->pluck('code')->map(fn ($code) => strtoupper($code))->all();

        $first = $this->value($fields, 'firstName');
        $middle = $this->value($fields, 'middleName');
        $last = $this->value($fields, 'lastName');
        $full = $this->value($fields, 'fullName') ?? (trim(implode(' ', array_filter([$first, $middle, $last]))) ?: null);
        $number = $this->value($fields, 'documentNumber') ?? $this->value($fields, 'personalNumber');

        // Lowest confidence among the fields the decision depends on.
        $confidences = array_filter(array_map(
            fn (string $key) => $this->confidence($fields, $key),
            ['fullName', 'firstName', 'lastName', 'documentNumber', 'personalNumber', 'dob', 'expiry'],
        ), fn ($value) => $value !== null);

        // Selfie face match + liveness: only present when a selfie was taken (DocuPass, or /scan with "face").
        $faceScore = $body['scores']['faceCompare'] ?? null;
        $faceCodes = array_values(array_intersect($codes, [...self::FACE_FAIL_CODES, ...self::FACE_REVIEW_CODES]));
        $face = is_numeric($faceScore) || $faceCodes !== [] ? [
            'face_checked' => true,
            'face_score' => is_numeric($faceScore) ? round((float) $faceScore, 3) : null,
            'face_failures' => array_values(array_map('strtolower', array_intersect($faceCodes, self::FACE_FAIL_CODES))),
            'face_issues' => array_values(array_map('strtolower', array_intersect($faceCodes, self::FACE_REVIEW_CODES))),
        ] : [];

        return $face + [
            'id_type' => $this->idType($fields, $codes),
            'full_name' => $full,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'birth_date' => $this->date($this->value($fields, 'dob')),
            'id_number' => $number,
            'expiry_date' => $this->date($this->value($fields, 'expiry')),
            'has_face_photo' => ! in_array('DOCUMENT_FACE_NOT_FOUND', $codes, true),
            'quality_issues' => array_values(array_unique(array_filter(array_map(fn ($code) => self::QUALITY_CODES[$code] ?? null, $codes)))),
            'tamper_signs' => array_values(array_unique(array_map('strtolower', array_intersect($codes, self::TAMPER_CODES)))),
            'confidence' => $confidences !== [] ? round(min($confidences), 3) : 0.0,
            // ID Analyzer's own verdict and warnings; PhIdRules never auto-verifies over a review/reject here.
            'provider_decision' => is_string($body['decision'] ?? null) ? strtolower($body['decision']) : null,
            'provider_warnings' => $warnings
                ->reject(fn ($warning) => strtolower((string) ($warning['decision'] ?? '')) === 'accept')
                ->map(fn ($warning) => mb_substr((string) ($warning['description'] ?? $warning['code']), 0, 150))
                ->values()->all(),
        ];
    }

    /** Map ID Analyzer's document type / name / country onto the PhIdRules::TYPES keys. */
    private function idType(array $fields, array $codes): string
    {
        if (in_array('PHYSICAL_DOCUMENT_MISSING', $codes, true)) {
            return 'not_an_id';
        }
        $country = strtoupper((string) $this->value($fields, 'countryIso2'));
        if ($country !== '' && $country !== 'PH') {
            return 'other';
        }
        $type = strtoupper((string) $this->value($fields, 'documentType'));
        if ($type === 'P') {
            return 'passport';
        }
        if ($type === 'D') {
            return 'drivers_license';
        }
        $name = strtoupper((string) $this->value($fields, 'documentName'));
        foreach (self::NAME_KEYWORDS as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if ($name !== '' && preg_match('/\b'.preg_quote($keyword, '/').'\b/', $name) === 1) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /** First value of a field: ID Analyzer returns each as [{value, confidence, source, index}]. */
    private function value(array $fields, string $key): ?string
    {
        $value = $fields[$key][0]['value'] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function confidence(array $fields, string $key): ?float
    {
        $confidence = $fields[$key][0]['confidence'] ?? null;

        return $this->value($fields, $key) !== null && is_numeric($confidence) ? (float) $confidence : null;
    }

    /** ID Analyzer dates are YYYY/MM/DD; PhIdRules expects YYYY-MM-DD. */
    private function date(?string $value): ?string
    {
        return $value !== null && preg_match('#^(\d{4})[/-](\d{2})[/-](\d{2})$#', $value, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

}
