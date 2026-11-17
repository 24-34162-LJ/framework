<?php

namespace Tests\Feature;

use App\Jobs\ReadIdentityDocument;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Identity\IdDocumentReader;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class IdentityVerificationTest extends TestCase
{
    use CreatesNotificationTables;

    private User $client;

    private User $otherClient;

    private User $admin;

    private int $clientId;

    /** @var array<string, mixed> what the fake reader returns */
    private array $readerResult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        (require database_path('migrations/2026_11_16_000001_create_identity_verifications.php'))->up();
        Storage::fake('local');

        $this->clientId = DB::table('clients')->insertGetId(['client_name' => 'Acme Imports', 'contact_person' => 'Juan Dela Cruz']);
        $otherId = DB::table('clients')->insertGetId(['client_name' => 'Other Co']);
        $this->client = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'role' => 'client', 'client_id' => $this->clientId,
        ]));
        $this->otherClient = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Other', 'email' => 'other@example.com', 'role' => 'client', 'client_id' => $otherId,
        ]));
        $this->admin = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin',
        ]));

        $this->readerResult = ['data' => [
            'id_type' => 'drivers_license', 'full_name' => 'DELA CRUZ, JUAN', 'birth_date' => '1990-05-01',
            'id_number' => 'N01-23-456789', 'expiry_date' => '2030-05-01', 'has_face_photo' => true,
            'quality_issues' => [], 'tamper_signs' => [], 'confidence' => 0.95,
        ]];
        $this->app->bind(IdDocumentReader::class, fn () => new class($this) implements IdDocumentReader
        {
            public function __construct(private IdentityVerificationTest $test) {}

            public function name(): string
            {
                return 'fake';
            }

            public function read(string $mimeType, string $bytes): array
            {
                return $this->test->readerResult();
            }
        });
    }

    public function readerResult(): array
    {
        return $this->readerResult;
    }

    /** A real PNG built without GD (GD is not installed on this PHP). */
    private function idPhoto(int $width = 800, int $height = 500): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\0".str_repeat("\xcc", $width * 3), $height);
        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($rows))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('license.png', $png);
    }

    private function upload(array $extra = [])
    {
        return $this->actingAs($this->client)->post(route('client.identity.store'), array_merge(['id_image' => $this->idPhoto(), 'consent' => '1'], $extra));
    }

    public function test_id_panel_is_on_my_profile(): void
    {
        $this->actingAs($this->client)->get(route('client.identity'))->assertRedirect(route('client.profile').'#id-verification');
        $this->actingAs($this->client)->get(route('client.profile'))->assertOk()->assertSee('ID verification')->assertSee('Upload your ID');
    }

    public function test_good_id_is_verified_and_stored_encrypted(): void
    {
        $this->upload()->assertRedirect(route('client.profile').'#id-verification')->assertSessionHas('identity_result', 'verified');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('verified', $verification->status);
        $this->assertSame('6789', $verification->id_number_last4);
        $this->assertSame(64, strlen((string) $verification->id_number_hash));
        $this->assertSame('DELA CRUZ, JUAN', $verification->extracted['full_name']);
        $this->assertNotNull($verification->purge_after);
        $this->assertNotNull(DB::table('clients')->where('client_id', $this->clientId)->value('identity_verified_at'));

        $raw = DB::table('identity_verifications')->first();
        $this->assertStringNotContainsString('DELA CRUZ', (string) $raw->extracted);
        // On disk: an encrypted payload, not the image (PNG files start with "\x89PNG").
        $this->assertStringNotContainsString("\x89PNG", Storage::disk('local')->get($verification->file_path));
        $this->assertStringStartsWith("\x89PNG", (string) app(IdentityVerificationService::class)->imageBytes($verification));
    }

    public function test_reader_failure_goes_to_admin_review(): void
    {
        $this->readerResult = ['error' => 'Gemini: quota exceeded'];

        $this->upload()->assertSessionHas('identity_result', 'needs_review');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('needs_review', $verification->status);
        $this->assertSame('Gemini: quota exceeded', $verification->reader_error);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->admin->user_id)->where('event_type', 'identity_review')->count());
    }

    public function test_consent_is_required(): void
    {
        $this->upload(['consent' => null])->assertSessionHasErrors('consent');
        $this->assertSame(0, IdentityVerification::query()->count());
    }

    public function test_non_images_and_tiny_photos_are_rejected(): void
    {
        $fakeJpg = UploadedFile::fake()->createWithContent('id.jpg', '%PDF-1.4 not an image');
        $this->upload(['id_image' => $fakeJpg])->assertSessionHasErrors('id_image');
        $this->upload(['id_image' => $this->idPhoto(200, 120)])->assertSessionHasErrors('id_image');
        $this->assertSame(0, IdentityVerification::query()->count());
    }

    public function test_clients_cannot_use_the_admin_review_pages(): void
    {
        $this->upload();
        $id = IdentityVerification::query()->sole()->verification_id;

        $this->actingAs($this->otherClient)->get(route('admin.identity.index'))->assertForbidden();
        $this->actingAs($this->otherClient)->get(route('admin.identity.image', $id))->assertForbidden();
        $this->actingAs($this->client)->post(route('admin.identity.decide', $id), ['decision' => 'verified'])->assertForbidden();
    }

    public function test_admin_can_review_view_image_and_approve(): void
    {
        $this->readerResult['data']['full_name'] = 'REYES, MARIA';
        $this->upload()->assertSessionHas('identity_result', 'needs_review');
        $id = IdentityVerification::query()->sole()->verification_id;

        $this->actingAs($this->admin)->get(route('admin.identity.index'))->assertOk()->assertSee('Acme Imports');
        $this->actingAs($this->admin)->get(route('admin.identity.show', $id))->assertOk()->assertSee('Name match');
        $this->actingAs($this->admin)->get(route('admin.identity.image', $id))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->admin)->post(route('admin.identity.decide', $id), ['decision' => 'rejected'])->assertSessionHasErrors('review_note');
        $this->actingAs($this->admin)->post(route('admin.identity.decide', $id), ['decision' => 'verified'])->assertRedirect(route('admin.identity.index'));

        $this->assertSame('verified', IdentityVerification::query()->find($id)->status);
        $this->assertNotNull(DB::table('clients')->where('client_id', $this->clientId)->value('identity_verified_at'));
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->client->user_id)->where('event_type', 'identity_decision')->count());
    }

    public function test_daily_upload_limit(): void
    {
        config(['services.identity.max_attempts_per_day' => 1]);
        $this->readerResult = ['data' => ['id_type' => 'not_an_id', 'confidence' => 0.9]];

        $this->upload()->assertSessionHas('identity_result', 'rejected');
        $this->upload()->assertSessionHasErrors('id_image');
        $this->assertSame(1, IdentityVerification::query()->count());
    }

    public function test_upload_returns_at_once_and_the_id_is_read_in_the_background(): void
    {
        Queue::fake();

        $this->upload()->assertRedirect(route('client.profile').'#id-verification')->assertSessionHas('identity_result', 'processing');

        $verification = IdentityVerification::query()->sole();
        $this->assertSame('processing', $verification->status);
        Queue::assertPushed(ReadIdentityDocument::class, fn (ReadIdentityDocument $job) => $job->verificationId === (int) $verification->verification_id);

        $this->actingAs($this->client)->get(route('client.profile'))->assertOk()->assertSee('Reading your ID')->assertDontSee('Upload your ID');
        $this->actingAs($this->client)->getJson(route('client.identity.status'))->assertJson(['status' => 'processing', 'processing' => true]);
        $this->upload()->assertSessionHasErrors(['id_image' => 'Your last ID is still being read. Please wait for the result.']);

        (new ReadIdentityDocument((int) $verification->verification_id))->handle(app(IdentityVerificationService::class));

        $this->assertSame('verified', $verification->refresh()->status);
        $this->actingAs($this->client)->getJson(route('client.identity.status'))->assertJson(['status' => 'verified', 'processing' => false]);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->client->user_id)->where('event_type', 'identity_result')->count());
    }

    public function test_a_failed_background_read_goes_to_admin_review(): void
    {
        Queue::fake();
        $this->upload();
        $verification = IdentityVerification::query()->sole();

        (new ReadIdentityDocument((int) $verification->verification_id))->failed(new \RuntimeException('worker killed'));

        $verification->refresh();
        $this->assertSame('needs_review', $verification->status);
        $this->assertNotNull($verification->reader_error);
    }

    public function test_ids_stuck_in_processing_show_up_for_admins(): void
    {
        Queue::fake();
        $this->upload();
        $verification = IdentityVerification::query()->sole();

        $this->assertSame(0, IdentityVerification::query()->needsAdmin()->count());

        $verification->forceFill(['created_at' => now()->subMinutes(IdentityVerification::STALE_PROCESSING_MINUTES + 1)])->save();

        $this->assertSame(1, IdentityVerification::query()->needsAdmin()->count());
        $this->actingAs($this->admin)->get(route('admin.identity.index'))->assertOk()->assertSee('queue worker');
        $this->actingAs($this->client)->get(route('client.profile'))->assertSee('Your ID is waiting in line');
    }

    public function test_purge_deletes_expired_images_but_keeps_the_record(): void
    {
        $this->upload();
        $verification = IdentityVerification::query()->sole();
        $path = $verification->file_path;
        $verification->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('identity:purge-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $verification->refresh();
        $this->assertNull($verification->file_path);
        $this->assertNotNull($verification->file_purged_at);
        $this->assertSame('verified', $verification->status);
    }
}
