<?php

namespace App\Services\Identity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Laravel's own checks on what the reader extracted. The AI only reads the card;
 * these rules decide verified / needs_review / rejected, so the outcome is explainable and testable.
 */
class PhIdRules
{
    /**
     * Accepted Philippine IDs: label, ID-number pattern (on the number with spaces/dashes removed,
     * null = no format check), and whether the card must show an expiry date.
     * Patterns are starting points — confirm them against real sample cards.
     */
    public const TYPES = [
        'philsys' => ['PhilSys National ID / ePhilID', '/^\d{16}$/', false],
        'drivers_license' => ["LTO Driver's License", '/^[A-Z]\d{10}$/', true],
        'passport' => ['Philippine Passport', '/^([A-Z]\d{7}[A-Z]|[A-Z]{2}\d{7})$/', true],
        'umid' => ['UMID', '/^\d{12}$/', false],
        'sss' => ['SSS ID', '/^\d{10}$/', false],
        'prc' => ['PRC ID', '/^\d{6,7}$/', true],
        'postal' => ['Postal ID', null, true],
        'voters' => ["Voter's ID", null, false],
        'tin' => ['TIN ID', '/^\d{9}(\d{3}|\d{5})?$/', false],
        'philhealth' => ['PhilHealth ID', '/^\d{12}$/', false],
    ];

    public const MIN_AGE = 18;

    public function __construct(
        private float $nameThreshold = 0.85,
        private float $minConfidence = 0.7,
    ) {}

    public static function label(?string $type): string
    {
        return self::TYPES[$type][0] ?? match ($type) {
            'not_an_id' => 'Not an ID',
            null, '' => 'Unknown',
            default => 'Other ID',
        };
    }

    /** Upper-case letters and digits only, e.g. "A01-23-456789" -> "A0123456789". */
    public static function normalizeNumber(?string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    public function formatValid(string $type, string $number): ?bool
    {
        $pattern = self::TYPES[$type][1] ?? null;

        return $pattern === null ? null : preg_match($pattern, self::normalizeNumber($number)) === 1;
    }

    /**
     * Share of the expected name's words found on the ID (order-free, so "DELA CRUZ, JUAN" matches
     * "Juan Dela Cruz"; a one-letter typo in a longer word still counts).
     */
    public function nameScore(?string $nameOnId, ?string $expected): float
    {
        $idWords = $this->nameWords($nameOnId);
        $expectedWords = $this->nameWords($expected);
        if ($idWords === [] || $expectedWords === []) {
            return 0.0;
        }
        $matched = 0;
        foreach ($expectedWords as $word) {
            foreach ($idWords as $candidate) {
                if ($word === $candidate || (strlen($word) > 4 && levenshtein($word, $candidate) <= 1)) {
                    $matched++;
                    break;
                }
            }
        }

        return round($matched / count($expectedWords), 2);
    }

    /**
     * @param  array<string, mixed>  $data  what the reader extracted
     * @param  list<string|null>  $expectedNames  account name, company contact person, ...
     * @return array{decision: string, reasons: list<string>, checks: list<array{key: string, label: string, result: string, detail: string}>}
     */
    public function evaluate(array $data, array $expectedNames, ?Carbon $today = null, bool $usedByAnotherClient = false): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $type = is_string($data['id_type'] ?? null) ? strtolower(trim($data['id_type'])) : null;
        $name = $this->str($data['full_name'] ?? null)
            ?? trim(implode(' ', array_filter([$this->str($data['first_name'] ?? null), $this->str($data['middle_name'] ?? null), $this->str($data['last_name'] ?? null)])));
        $number = $this->str($data['id_number'] ?? null);
        $expiry = $this->date($data['expiry_date'] ?? null);
        $birth = $this->date($data['birth_date'] ?? null);
        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;
        $quality = $this->list($data['quality_issues'] ?? []);
        $tamper = $this->list($data['tamper_signs'] ?? []);

        $checks = [];
        $reject = [];
        $review = [];
        $add = function (string $key, string $label, string $result, string $detail) use (&$checks): void {
            $checks[] = compact('key', 'label', 'result', 'detail');
        };

        // 1. Is it an accepted ID at all?
        if ($type === 'not_an_id') {
            $add('type', 'ID type', 'fail', 'The image does not look like a government ID.');
            $reject[] = 'The image does not look like a government ID. Upload a photo of the front of your ID.';
        } elseif (isset(self::TYPES[$type])) {
            $add('type', 'ID type', 'pass', self::label($type));
        } else {
            $add('type', 'ID type', 'warn', 'Not on the accepted list');
            $review[] = 'ID type is not on the accepted list.';
        }

        // 2. Could the main fields be read?
        if ($name === '' && $number === null) {
            $add('readable', 'Readable', 'fail', 'Name and ID number could not be read.');
            $reject[] = 'The name and ID number could not be read. Retake the photo in good light, flat and uncropped.';
        }
        if ($quality !== []) {
            $add('quality', 'Photo quality', 'warn', implode(', ', $quality));
            $review[] = 'Photo quality: '.implode(', ', $quality).'.';
        } else {
            $add('quality', 'Photo quality', 'pass', 'No problems found');
        }

        // 3. Expiry
        $needsExpiry = (bool) (self::TYPES[$type][2] ?? false);
        if ($expiry && $expiry->lt($today)) {
            $add('expiry', 'Expiry', 'fail', 'Expired on '.$expiry->format('M d, Y'));
            $reject[] = 'This ID expired on '.$expiry->format('M d, Y').'. Upload a valid ID.';
        } elseif ($expiry) {
            $add('expiry', 'Expiry', 'pass', 'Valid until '.$expiry->format('M d, Y'));
        } elseif ($needsExpiry) {
            $add('expiry', 'Expiry', 'warn', 'Expiry date not readable');
            $review[] = 'Expiry date could not be read.';
        } else {
            $add('expiry', 'Expiry', 'pass', 'This ID type has no expiry date');
        }

        // 4. ID number format
        if ($number === null) {
            if ($name !== '') {
                $add('number', 'ID number', 'warn', 'Not readable');
                $review[] = 'ID number could not be read.';
            }
        } elseif ($type && isset(self::TYPES[$type])) {
            $valid = $this->formatValid($type, $number);
            match ($valid) {
                true => $add('number', 'ID number', 'pass', 'Format matches '.self::label($type)),
                false => $add('number', 'ID number', 'warn', 'Unusual format for '.self::label($type)),
                null => $add('number', 'ID number', 'pass', 'Read (no format rule for this ID type)'),
            };
            if ($valid === false) {
                $review[] = 'ID number does not match the usual '.self::label($type).' format.';
            }
        }

        // 5. Name on the ID vs the account
        $score = collect($expectedNames)->filter()->map(fn ($expected) => $this->nameScore($name, (string) $expected))->max() ?? 0.0;
        if ($name !== '') {
            $passed = $score >= $this->nameThreshold;
            $add('name', 'Name match', $passed ? 'pass' : 'warn', 'Score '.number_format($score * 100).'%'.($passed ? '' : ' — name on the ID differs from the account'));
            if (! $passed) {
                $review[] = 'Name on the ID does not match the account or company contact name.';
            }
        }

        // 6. Adult and face photo present
        if ($birth) {
            $age = $birth->diffInYears($today);
            $adult = $age >= self::MIN_AGE;
            $add('age', 'Age', $adult ? 'pass' : 'warn', $adult ? 'Adult' : 'Under '.self::MIN_AGE);
            if (! $adult) {
                $review[] = 'Holder appears to be under '.self::MIN_AGE.'.';
            }
        }
        if (($data['has_face_photo'] ?? null) === false && $type !== 'not_an_id') {
            $add('photo', 'Face photo', 'warn', 'No face photo seen on the card');
            $review[] = 'No face photo was found on the card.';
        }

        // 7. Tampering, confidence, duplicates
        if ($tamper !== []) {
            $add('tamper', 'Tampering signs', 'warn', implode(', ', $tamper));
            $review[] = 'Possible tampering: '.implode(', ', $tamper).'.';
        } else {
            $add('tamper', 'Tampering signs', 'pass', 'None found');
        }
        if ($confidence < $this->minConfidence) {
            $add('confidence', 'Reader confidence', 'warn', number_format($confidence * 100).'%');
            $review[] = 'Low reading confidence.';
        } else {
            $add('confidence', 'Reader confidence', 'pass', number_format($confidence * 100).'%');
        }
        if ($usedByAnotherClient) {
            $add('duplicate', 'Duplicate ID', 'warn', 'Already used by another client account');
            $review[] = 'This ID number is already linked to another client account.';
        }

        // 8. A dedicated ID provider (e.g. ID Analyzer) has its own verdict: never auto-verify over it.
        $provider = is_string($data['provider_decision'] ?? null) ? strtolower($data['provider_decision']) : null;
        if ($provider === 'reject' || $provider === 'review') {
            $warnings = $this->list($data['provider_warnings'] ?? []);
            $add('provider', 'ID provider check', 'warn', ucfirst($provider).($warnings !== [] ? ': '.implode('; ', $warnings) : ''));
            $review[] = 'The ID provider flagged this ID'.($warnings !== [] ? ': '.implode('; ', $warnings) : '').'.';
        } elseif ($provider === 'accept') {
            $add('provider', 'ID provider check', 'pass', 'Accepted');
        }

        $decision = $reject !== [] ? 'rejected' : ($review !== [] ? 'needs_review' : 'verified');

        return ['decision' => $decision, 'reasons' => $reject !== [] ? $reject : $review, 'checks' => $checks];
    }

    /** @return list<string> */
    private function nameWords(?string $name): array
    {
        $plain = strtoupper(Str::ascii((string) $name));
        $words = preg_split('/[^A-Z]+/', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Drop suffixes and single initials; they are often omitted on one side.
        return array_values(array_filter($words, fn (string $word) => strlen($word) > 1 && ! in_array($word, ['JR', 'SR', 'II', 'III', 'IV'], true)));
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private function date(mixed $value): ?Carbon
    {
        $value = $this->str($value);
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => str_replace('_', ' ', mb_substr(trim((string) $item), 0, 40)))
            ->unique()->values()->all();
    }
}
