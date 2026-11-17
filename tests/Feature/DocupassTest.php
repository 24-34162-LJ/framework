<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/DocupassTest.php
 * Feature: Testing - ID Recognition
 * Purpose: Tests the live ID + selfie check (DocuPass): start, return, result and webhook.
 */

namespace Tests\Feature;

use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class DocupassTest extends TestCase
{
    use CreatesNotificationTables;

    private User $client;

    private int $clientId;

    /** @var array<string, mixed>|null the finished transaction ID Analyzer returns (null = not finished) */
    private ?array $transaction = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        (require database_path('migrations/2026_11_16_000001_create_identity_verifications.php'))->up();
        (require database_path('migrations/2026_11_17_000001_add_docupass_to_identity_verifications.php'))->up();
        Storage::fake('local');
        config([
            'services.identity.method' => 'docupass',
            'services.idanalyzer.api_key' => 'test-key',
            'services.idanalyzer.profile' => 'profile123',
            'services.idanalyzer.base_url' => 'https://api2.idanalyzer.com',
            'services.idanalyzer.webhook_secret' => 'whsec',
        ]);

        $this->clientId = DB::table('clients')->insertGetId(['client_name' => 'Acme Imports', 'contact_person' => 'Juan Dela Cruz']);
        $this->client = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'role' => 'client', 'client_id' => $this->clientId,
        ]));
        DB::table('users')->insert(['full_name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin', 'status' => 'active']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                $url === 'https://api2.idanalyzer.com/docupass' => Http::response(['reference' => 'REF123', 'url' => 'https://docupass.app/REF123', 'qrCode' => 'x']),
                str_starts_with($url, 'https://api2.idanalyzer.com/transaction?') => Http::response([
                    'transactions' => $this->transaction ? [['transactionId' => 'TX1']] : [], 'total' => $this->transaction ? 1 : 0,
                ]),
                $url === 'https://api2.idanalyzer.com/transaction/TX1' => Http::response($this->transaction ?? []),
                str_starts_with($url, 'https://api2.idanalyzer.com/imagevault/') => Http::response(base64_decode('/9j/4AAQSkZJRgABAQ=='), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response(['success' => false, 'error' => ['message' => 'unexpected '.$url]], 500),
            };
        });
    }

    private function field(string $value): array
    {
        return [['value' => $value, 'confidence' => 0.97, 'source' => 'visual', 'index' => 0]];
    }

    private function finishedTransaction(array $warnings = [], float $faceScore = 0.91, string $decision = 'accept'): array
    {
        return [
            'success' => true,
            'transactionId' => 'TX1',
            'docupass' => 'REF123',
            'customData' => 'idv-1',
            'data' => [
                'firstName' => $this->field('JUAN'), 'lastName' => $this->field('DELA CRUZ'),
                'dob' => $this->field('1990/05/01'), 'expiry' => $this->field('2030/05/01'),
                'documentNumber' => $this->field('N01-23-456789'), 'documentType' => $this->field('D'), 'countryIso2' => $this->field('PH'),
            ],
            'outputImage' => ['front' => 'fronttoken123', 'face' => 'facetoken12345'],
            'warning' => $warnings,
            'scores' => ['faceCompare' => $faceScore],
            'decision' => $decision,
        ];
    }

    private function start()
    {
        return $this->actingAs($this->client)->post(route('client.identity.live'), ['live_consent' => '1']);
    }

    public function test_panel_offers_the_live_check(): void
    {
        $this->actingAs($this->client)->get(route('client.profile'))->assertOk()
            ->assertSee('Live check with your camera')->assertSee('Start live ID check')
            ->assertDontSee('Upload and verify');
    }

    public function test_photo_upload_is_closed_while_the_live_check_is_on(): void
    {
        // A photo upload would skip the selfie (face match + liveness).
        $this->actingAs($this->client)->post(route('client.identity.store'), ['consent' => '1'])->assertNotFound();
        $this->assertSame(0, IdentityVerification::query()->count());
    }

    public function test_start_needs_consent(): void
    {
        $this->actingAs($this->client)->post(route('client.identity.live'))->assertSessionHasErrors('live_consent');
        $this->assertSame(0, IdentityVerification::query()->count());
    }

    public function test_start_creates_a_pending_check_and_sends_the_client_to_docupass(): void
    {
        $this->start()->assertRedirect('https://docupass.app/REF123');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('pending', $verification->status);
        $this->assertSame('REF123', $verification->provider_reference);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api2.idanalyzer.com/docupass'
            && $request['mode'] === 0 && $request['profile'] === 'profile123' && $request['customData'] === 'idv-'.$verification->verification_id);

        // Starting again resumes the same link instead of creating (and paying for) a new one.
        $this->start()->assertRedirect('https://docupass.app/REF123');
        $this->assertSame(1, IdentityVerification::query()->count());
    }

    public function test_return_before_finishing_keeps_it_pending(): void
    {
        $this->start();

        $this->actingAs($this->client)->get(route('client.identity.live.return', ['reference' => 'REF123']))
            ->assertRedirect(route('client.profile').'#id-verification')->assertSessionHas('identity_pending');
        $this->assertSame('pending', IdentityVerification::query()->sole()->status);
    }

    public function test_finished_check_with_matching_selfie_is_verified(): void
    {
        $this->start();
        $this->transaction = $this->finishedTransaction();

        $this->actingAs($this->client)->get(route('client.identity.live.return', ['reference' => 'REF123']))
            ->assertSessionHas('identity_result', 'verified');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('verified', $verification->status);
        $this->assertSame('TX1', $verification->provider_transaction_id);
        $this->assertTrue($verification->extracted['face_checked']);
        $this->assertSame(0.91, $verification->extracted['face_score']);
        $this->assertContains('face', array_column($verification->checks['items'], 'key'));
        $service = app(IdentityVerificationService::class);
        $this->assertNotNull($service->imageBytes($verification));
        $this->assertNotNull($service->imageBytes($verification, 'selfie'));
        $this->assertSame('image/jpeg', $verification->file_mime);
        $this->assertNotNull(DB::table('clients')->where('client_id', $this->clientId)->value('identity_verified_at'));
    }

    public function test_selfie_that_does_not_match_is_rejected(): void
    {
        $this->start();
        $this->transaction = $this->finishedTransaction([
            ['code' => 'FACE_MISMATCH', 'description' => 'Face mismatch.', 'severity' => 'high', 'decision' => 'reject'],
        ], 0.21, 'reject');

        $this->actingAs($this->client)->post(route('client.identity.live.check'))->assertSessionHas('identity_result', 'rejected');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('rejected', $verification->status);
        $this->assertStringContainsString('selfie', implode(' ', $verification->checks['reasons']));
    }

    public function test_webhook_requires_a_valid_signature(): void
    {
        $this->start();
        $body = json_encode(['event' => 'docupass_conclusive'] + $this->finishedTransaction());

        $this->call('POST', route('webhooks.idanalyzer'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_IDA_TIMESTAMP' => (string) time(), 'HTTP_X_IDA_SIGNATURE' => 'v1=bad',
        ], $body)->assertStatus(401);

        $this->assertSame('pending', IdentityVerification::query()->sole()->status);
    }

    public function test_signed_webhook_completes_the_check_once(): void
    {
        $this->start();
        $body = json_encode(['event' => 'docupass_conclusive'] + $this->finishedTransaction());
        $timestamp = (string) time();
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_IDA_TIMESTAMP' => $timestamp,
            'HTTP_X_IDA_SIGNATURE' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec'),
        ];

        $this->call('POST', route('webhooks.idanalyzer'), [], [], [], $headers, $body)->assertOk();
        $this->call('POST', route('webhooks.idanalyzer'), [], [], [], $headers, $body)->assertOk();

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('verified', $verification->status);
        // Completed once: one ID image + one selfie stored, no duplicates from the second call.
        $this->assertCount(2, Storage::disk('local')->allFiles('identity'));
    }

    public function test_live_routes_are_off_when_docupass_is_disabled(): void
    {
        config(['services.identity.method' => 'upload']);

        $this->start()->assertNotFound();
        $this->actingAs($this->client)->get(route('client.profile'))->assertOk()->assertDontSee('Start live ID check');
    }
}
