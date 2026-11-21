<?php

/*
 * FILE PURPOSE
 * File   : app/Services/Identity/DocupassService.php
 * Feature: ID Recognition
 * Purpose: Live ID + selfie check with ID Analyzer DocuPass: creates the DocuPass link, then downloads
 *          and saves the result.
 */

namespace App\Services\Identity;

use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Live ID + selfie check through ID Analyzer DocuPass (hosted page, mode 0 = document + face).
 *  1. start(): create a DocuPass link for one verification row (status "pending") and send the client there
 *  2. the client scans the ID and takes a selfie on ID Analyzer's page (live camera, or on the phone by QR)
 *  3. the result arrives by the return redirect (refresh), the "Check my result" button, or the webhook
 *  4. complete(): download the ID image + selfie, map the result, and let PhIdRules decide as usual
 */
class DocupassService
{
    public const CUSTOM_DATA_PREFIX = 'idv-';

    public function __construct(
        private IdAnalyzerClient $client,
        private IdAnalyzerReader $reader,
        private IdentityVerificationService $verifications,
        private ActivityLogger $activity,
    ) {}

    public static function enabled(): bool
    {
        return config('services.identity.method') === 'docupass';
    }

    /**
     * Create the DocuPass session; returns the verification row with provider_url set.
     * With $digitalId (a screenshot of an eGovPH / ePhilID digital ID, uploaded on our own page) the image is sent
     * as referenceDocument: DocuPass then skips the ID step and only takes the live selfie, matched against it.
     */
    public function start(User $user, ?string $ip, ?string $digitalId = null, ?string $digitalMime = null): IdentityVerification
    {
        $verification = IdentityVerification::query()->create([
            'user_id' => $user->user_id,
            'client_id' => $user->client_id,
            'status' => IdentityVerification::STATUS_PENDING,
            'reader' => 'docupass',
            'document_source' => $digitalId !== null ? 'digital' : 'card',
            'consent_version' => IdentityVerification::CONSENT_VERSION,
            'consent_at' => now(),
            'consent_ip' => $ip,
        ]);

        $body = [
            'version' => 3,
            'mode' => 0,
            'profile' => (string) config('services.idanalyzer.profile'),
            'customData' => self::CUSTOM_DATA_PREFIX.$verification->verification_id,
        ];
        if ($digitalId !== null) {
            $body['referenceDocument'] = base64_encode($digitalId);
        }
        $result = $this->client->post('/docupass', $body, 60);

        $reference = $result['data']['reference'] ?? null;
        $url = $result['data']['url'] ?? null;
        if (isset($result['error']) || ! is_string($reference) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            $verification->delete();
            throw new RuntimeException($result['error'] ?? 'ID Analyzer did not return a verification link.');
        }

        $verification->forceFill([
            'provider_reference' => mb_substr($reference, 0, 64),
            'provider_url' => mb_substr($url, 0, 500),
            'provider_qr' => $this->qrImage($result['data']['qrCode'] ?? null),
            // The uploaded digital ID is the ID photo admins see (DocuPass does not capture one).
            'file_path' => $digitalId !== null ? $this->verifications->storeImage($verification, $digitalId) : null,
            'file_mime' => $digitalId !== null ? $digitalMime : null,
            'original_name' => $digitalId !== null ? 'digital-id' : null,
        ])->save();
        $this->activity->record($user, 'ID Verification', $digitalId !== null ? 'Digital ID uploaded, selfie check started' : 'Live ID check started', [
            'verification_id' => $verification->verification_id,
        ]);

        return $verification;
    }

    /** ID Analyzer's qrCode is a base64 image; keep it only if it really is a PNG/JPEG/GIF. */
    private function qrImage(mixed $qr): ?string
    {
        if (! is_string($qr) || $qr === '') {
            return null;
        }
        $base64 = preg_replace('#^data:image/[a-z]+;base64,#i', '', trim($qr));
        $bytes = base64_decode((string) $base64, true);
        if ($bytes === false || strlen($bytes) > 2_000_000) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }

    /**
     * Ask ID Analyzer whether the client finished; complete the row if so.
     * Returns true when the row is no longer pending.
     */
    public function refresh(IdentityVerification $verification): bool
    {
        if ($verification->status !== IdentityVerification::STATUS_PENDING || ! $verification->provider_reference) {
            return $verification->status !== IdentityVerification::STATUS_PENDING;
        }

        $list = $this->client->get('/transaction', ['docupass' => $verification->provider_reference, 'limit' => 1, 'order' => -1]);
        $transactionId = $list['data']['transactions'][0]['transactionId'] ?? $list['data']['transactions'][0]['id'] ?? null;
        if (! is_string($transactionId) || $transactionId === '') {
            return false; // not finished yet
        }

        $detail = $this->client->get('/transaction/'.rawurlencode($transactionId));
        if (isset($detail['error'])) {
            report(new RuntimeException('DocuPass result fetch failed: '.$detail['error']));

            return false;
        }

        $this->complete($verification, $detail['data'] + ['transactionId' => $transactionId]);

        return true;
    }

    /** Find the verification a webhook / transaction belongs to (by DocuPass reference, then customData). */
    public function findFor(array $transaction): ?IdentityVerification
    {
        $reference = $transaction['docupass'] ?? null;
        if (is_string($reference) && $reference !== '') {
            $found = IdentityVerification::query()->where('provider_reference', $reference)->first();
            if ($found) {
                return $found;
            }
        }
        $custom = $transaction['customData'] ?? null;
        if (is_string($custom) && str_starts_with($custom, self::CUSTOM_DATA_PREFIX)) {
            return IdentityVerification::query()->where('reader', 'docupass')
                ->find((int) substr($custom, strlen(self::CUSTOM_DATA_PREFIX)));
        }

        return null;
    }

    /** Save the images and decide. Safe to call twice (redirect + webhook): only a pending row is completed. */
    public function complete(IdentityVerification $verification, array $transaction): void
    {
        $claimed = DB::table('identity_verifications')
            ->where('verification_id', $verification->verification_id)
            ->where('status', IdentityVerification::STATUS_PENDING)
            ->update(['status' => IdentityVerification::STATUS_PROCESSING, 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $verification->refresh();

        // Once claimed, the check must always leave "processing": any failure sends it to admin review.
        try {
            $digital = $verification->document_source === 'digital';
            $images = is_array($transaction['outputImage'] ?? null) ? $transaction['outputImage'] : [];
            // A digital ID was uploaded on our page and is already stored; only a scanned card is downloaded.
            $front = ! $digital && is_string($images['front'] ?? null) ? $this->client->image($images['front']) : null;
            $selfie = is_string($images['face'] ?? null) ? $this->client->image($images['face']) : null;
            $verification->forceFill(array_filter([
                'provider_transaction_id' => is_string($transaction['transactionId'] ?? null) ? mb_substr($transaction['transactionId'], 0, 64) : null,
                'file_path' => $front !== null ? $this->verifications->storeImage($verification, $front) : null,
                'file_mime' => $front !== null ? $this->mime($front) : null,
                'selfie_path' => $selfie !== null ? $this->verifications->storeImage($verification, $selfie) : null,
            ], fn ($value) => $value !== null) + ['provider_url' => null, 'provider_qr' => null])->save();

            $this->verifications->applyReading($verification, $this->reader->map($transaction) + ['digital_id' => $digital, 'face_expected' => true]);
        } catch (\Throwable $exception) {
            report($exception);
            $verification->refresh();
            if ($verification->status === IdentityVerification::STATUS_PROCESSING) {
                $this->verifications->markReaderFailed($verification, 'The live ID check result could not be processed.');
            }
        }
    }

    private function mime(string $bytes): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg';
    }
}
