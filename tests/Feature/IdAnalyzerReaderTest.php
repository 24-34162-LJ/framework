<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/IdAnalyzerReaderTest.php
 * Feature: Testing - ID Recognition
 * Purpose: Tests the ID Analyzer reader: request sent and answer mapped, errors handled.
 */

namespace Tests\Feature;

use App\Services\Identity\IdAnalyzerReader;
use App\Services\Identity\IdDocumentReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdAnalyzerReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.identity.reader' => 'idanalyzer',
            'services.idanalyzer.api_key' => 'test-key',
            'services.idanalyzer.profile' => 'security_medium',
            'services.idanalyzer.base_url' => 'https://api2.idanalyzer.com',
        ]);
    }

    private function field(string $value, float $confidence = 0.98): array
    {
        return [['value' => $value, 'confidence' => $confidence, 'source' => 'visual', 'index' => 0]];
    }

    public function test_container_uses_id_analyzer_when_configured(): void
    {
        $this->assertInstanceOf(IdAnalyzerReader::class, app(IdDocumentReader::class));
    }

    public function test_scan_is_sent_and_mapped_to_reader_fields(): void
    {
        Http::fake(['api2.idanalyzer.com/scan' => Http::response([
            'success' => true,
            'data' => [
                'firstName' => $this->field('JUAN'),
                'middleName' => $this->field('SANTOS'),
                'lastName' => $this->field('DELA CRUZ'),
                'dob' => $this->field('1990/05/01'),
                'expiry' => $this->field('2030/05/01'),
                'documentNumber' => $this->field('N01-23-456789', 0.91),
                'documentType' => $this->field('D'),
                'countryIso2' => $this->field('PH'),
            ],
            'warning' => [
                ['code' => 'IMAGE_TOO_BLURRY', 'description' => 'The document image is too blurry.', 'severity' => 'medium', 'decision' => 'review'],
                ['code' => 'TEXT_FORGERY', 'description' => 'Possible artificial text modification.', 'severity' => 'medium', 'decision' => 'review'],
                ['code' => 'MISSING_EYE_COLOR', 'description' => 'Eye color is missing.', 'severity' => 'low', 'decision' => 'accept'],
            ],
            'decision' => 'review',
        ])]);

        $result = app(IdAnalyzerReader::class)->read('image/jpeg', 'jpeg-bytes');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api2.idanalyzer.com/scan'
            && $request->hasHeader('X-API-KEY', 'test-key')
            && $request['profile'] === 'security_medium'
            && $request['document'] === base64_encode('jpeg-bytes'));

        $data = $result['data'];
        $this->assertSame('drivers_license', $data['id_type']);
        $this->assertSame('JUAN SANTOS DELA CRUZ', $data['full_name']);
        $this->assertSame('1990-05-01', $data['birth_date']);
        $this->assertSame('2030-05-01', $data['expiry_date']);
        $this->assertSame('N01-23-456789', $data['id_number']);
        $this->assertSame(0.91, $data['confidence']);
        $this->assertSame(['blurry'], $data['quality_issues']);
        $this->assertSame(['text_forgery'], $data['tamper_signs']);
        $this->assertSame('review', $data['provider_decision']);
        $this->assertCount(2, $data['provider_warnings']); // the "accept" warning is left out
    }

    public function test_id_types_from_document_name_and_country(): void
    {
        $reader = app(IdAnalyzerReader::class);
        $type = fn (array $fields, array $warnings = []) => $reader->map(['data' => $fields, 'warning' => $warnings])['id_type'];

        $this->assertSame('passport', $type(['documentType' => $this->field('P'), 'countryIso2' => $this->field('PH')]));
        $this->assertSame('philsys', $type(['documentType' => $this->field('I'), 'documentName' => $this->field('Philippine Identification Card')]));
        $this->assertSame('prc', $type(['documentType' => $this->field('O'), 'documentName' => $this->field('PRC Professional ID')]));
        $this->assertSame('other', $type(['documentType' => $this->field('P'), 'countryIso2' => $this->field('US')]));
        $this->assertSame('not_an_id', $type([], [['code' => 'PHYSICAL_DOCUMENT_MISSING']]));
    }

    public function test_api_errors_become_reader_errors(): void
    {
        Http::fake(['api2.idanalyzer.com/scan' => Http::response([
            'success' => false,
            'error' => ['status' => 401, 'code' => 'ERROR_UNAUTHORIZED', 'message' => 'Failed to authenticate user.'],
        ], 401)]);

        $result = app(IdAnalyzerReader::class)->read('image/jpeg', 'x');

        $this->assertSame('ID Analyzer: Failed to authenticate user.', $result['error']);
    }

    public function test_missing_key_is_reported_without_calling_the_api(): void
    {
        config(['services.idanalyzer.api_key' => '']);
        Http::fake();

        $result = app(IdAnalyzerReader::class)->read('image/jpeg', 'x');

        $this->assertStringContainsString('IDANALYZER_API_KEY', $result['error']);
        Http::assertNothingSent();
    }
}
