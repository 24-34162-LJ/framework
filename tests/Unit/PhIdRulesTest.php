<?php

/*
 * FILE PURPOSE
 * File   : tests/Unit/PhIdRulesTest.php
 * Feature: Testing - ID Recognition
 * Purpose: Tests the Philippine ID rules: verified, review and rejected cases.
 */

namespace Tests\Unit;

use App\Services\Identity\PhIdRules;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class PhIdRulesTest extends TestCase
{
    private PhIdRules $rules;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new PhIdRules(0.85, 0.7);
        $this->today = Carbon::create(2026, 11, 16);
    }

    private function goodLicense(array $overrides = []): array
    {
        return array_merge([
            'id_type' => 'drivers_license',
            'full_name' => 'DELA CRUZ, JUAN SANTOS',
            'birth_date' => '1990-05-01',
            'id_number' => 'N01-23-456789',
            'expiry_date' => '2030-05-01',
            'has_face_photo' => true,
            'quality_issues' => [],
            'tamper_signs' => [],
            'confidence' => 0.95,
        ], $overrides);
    }

    public function test_good_drivers_license_is_verified(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(), ['Juan Dela Cruz'], $this->today);

        $this->assertSame('verified', $result['decision']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_provider_review_or_reject_is_never_auto_verified(): void
    {
        foreach (['review', 'reject'] as $provider) {
            $result = $this->rules->evaluate($this->goodLicense([
                'provider_decision' => $provider, 'provider_warnings' => ['Selfie photo liveness verification failed.'],
            ]), ['Juan Dela Cruz'], $this->today);

            $this->assertSame('needs_review', $result['decision'], $provider);
            $this->assertStringContainsString('liveness', implode(' ', $result['reasons']));
        }

        $accepted = $this->rules->evaluate($this->goodLicense(['provider_decision' => 'accept']), ['Juan Dela Cruz'], $this->today);
        $this->assertSame('verified', $accepted['decision']);
    }

    public function test_live_check_without_a_face_match_score_goes_to_review(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(['face_expected' => true, 'face_score' => null]), ['Juan Dela Cruz'], $this->today);

        $this->assertSame('needs_review', $result['decision']);
        $this->assertStringContainsString('compares the faces', implode(' ', $result['reasons']));

        $matched = $this->rules->evaluate($this->goodLicense(['face_expected' => true, 'face_checked' => true, 'face_score' => 0.9]), ['Juan Dela Cruz'], $this->today);
        $this->assertSame('verified', $matched['decision']);
    }

    public function test_expired_id_is_rejected(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(['expiry_date' => '2025-01-01']), ['Juan Dela Cruz'], $this->today);

        $this->assertSame('rejected', $result['decision']);
        $this->assertStringContainsString('expired', $result['reasons'][0]);
    }

    public function test_not_an_id_is_rejected(): void
    {
        $result = $this->rules->evaluate(['id_type' => 'not_an_id', 'confidence' => 0.9], ['Juan Dela Cruz'], $this->today);

        $this->assertSame('rejected', $result['decision']);
    }

    public function test_name_mismatch_goes_to_review(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(['full_name' => 'REYES, MARIA']), ['Juan Dela Cruz'], $this->today);

        $this->assertSame('needs_review', $result['decision']);
        $this->assertContains('Name on the ID does not match the account or company contact name.', $result['reasons']);
    }

    public function test_name_matches_either_account_name_or_company_contact(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(['full_name' => 'REYES, MARIA L.']), ['Juan Dela Cruz', 'Maria Reyes'], $this->today);

        $this->assertSame('verified', $result['decision']);
    }

    public function test_tampering_low_confidence_and_bad_format_go_to_review(): void
    {
        foreach ([['tamper_signs' => ['photo_of_screen']], ['confidence' => 0.4], ['id_number' => '12345']] as $override) {
            $this->assertSame('needs_review', $this->rules->evaluate($this->goodLicense($override), ['Juan Dela Cruz'], $this->today)['decision'], json_encode($override));
        }
    }

    public function test_philsys_needs_no_expiry_and_checks_16_digits(): void
    {
        $philsys = $this->goodLicense(['id_type' => 'philsys', 'id_number' => '1234-5678-9012-3456', 'expiry_date' => null]);

        $this->assertSame('verified', $this->rules->evaluate($philsys, ['Juan Dela Cruz'], $this->today)['decision']);
    }

    public function test_used_by_another_client_goes_to_review(): void
    {
        $result = $this->rules->evaluate($this->goodLicense(), ['Juan Dela Cruz'], $this->today, true);

        $this->assertSame('needs_review', $result['decision']);
    }

    public function test_number_formats(): void
    {
        $this->assertTrue($this->rules->formatValid('drivers_license', 'N01-23-456789'));
        $this->assertTrue($this->rules->formatValid('passport', 'P1234567A'));
        $this->assertTrue($this->rules->formatValid('tin', '123-456-789-000'));
        $this->assertFalse($this->rules->formatValid('philsys', '1234'));
        $this->assertNull($this->rules->formatValid('postal', 'anything'));
    }

    public function test_name_score_ignores_order_punctuation_and_initials(): void
    {
        $this->assertSame(1.0, $this->rules->nameScore('DELA CRUZ, JUAN S.', 'Juan Dela Cruz'));
        $this->assertSame(1.0, $this->rules->nameScore('PEÑA, JOSÉ', 'Jose Pena'));
        $this->assertLessThan(0.85, $this->rules->nameScore('SANTOS, PEDRO', 'Juan Dela Cruz'));
    }
}
