<?php

/*
 * FILE PURPOSE
 * File   : app/Services/Identity/IdentityVerificationService.php
 * Feature: ID Recognition
 * Purpose: Main ID recognition flow: save the ID photo encrypted, have it read, apply the rules,
 *          notify, and admin decisions.
 */

namespace App\Services\Identity;

use App\Jobs\ReadIdentityDocument;
use App\Models\Client;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Client ID check, step by step:
 *  1. submit(): store the ID image encrypted on the private disk, status "processing", queue the read
 *  2. process() (in ReadIdentityDocument): the IdDocumentReader (Gemini) reads the printed fields
 *  3. PhIdRules decides verified / needs_review / rejected
 *  4. a reader failure never blocks the client: the ID goes to the admin review queue
 */
class IdentityVerificationService
{
    public function __construct(
        private IdDocumentReader $reader,
        private PhIdRules $rules,
        private ActivityLogger $activity,
        private NotificationService $notifications,
    ) {}

    public function submit(User $user, UploadedFile $file, ?string $ip): IdentityVerification
    {
        $path = 'identity/'.(int) $user->client_id.'/'.Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        $verification = IdentityVerification::query()->create([
            'user_id' => $user->user_id,
            'client_id' => $user->client_id,
            'status' => IdentityVerification::STATUS_PROCESSING,
            'reader' => $this->reader->name(),
            'file_path' => $path,
            'file_mime' => (string) $file->getMimeType(),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'consent_version' => IdentityVerification::CONSENT_VERSION,
            'consent_at' => now(),
            'consent_ip' => $ip,
        ]);

        $this->activity->record($user, 'ID Verification', 'ID uploaded for checking', [
            'verification_id' => $verification->verification_id,
        ]);

        // Background when a queue worker runs (QUEUE_CONNECTION=database); inline with "sync".
        ReadIdentityDocument::dispatch((int) $verification->verification_id);

        return $verification->refresh();
    }

    /** Read the stored image and decide. Called by ReadIdentityDocument. */
    public function process(IdentityVerification $verification): void
    {
        $verification->loadMissing(['user', 'client']);
        $bytes = $this->imageBytes($verification);
        if ($bytes === null) {
            $this->markReaderFailed($verification, 'The uploaded image could not be found.');

            return;
        }

        try {
            $result = $this->reader->read((string) $verification->file_mime, $bytes);
        } catch (\Throwable $exception) {
            report($exception);
            $result = ['error' => 'The ID reader failed unexpectedly.'];
        }

        if (isset($result['error'])) {
            $this->markReaderFailed($verification, (string) $result['error']);

            return;
        }

        $this->applyReading($verification, (array) ($result['data'] ?? []));
    }

    /**
     * Run PhIdRules on what a reader extracted and save the decision. Shared by the photo upload
     * (process) and the DocuPass live check (DocupassService::complete).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyReading(IdentityVerification $verification, array $data): void
    {
        $verification->loadMissing(['user', 'client']);
        $number = PhIdRules::normalizeNumber(is_scalar($data['id_number'] ?? null) ? (string) $data['id_number'] : '');
        $hash = $number !== '' ? $this->hashNumber($number) : null;
        $usedElsewhere = $hash !== null && IdentityVerification::query()
            ->where('id_number_hash', $hash)
            ->where('status', IdentityVerification::STATUS_VERIFIED)
            ->where('client_id', '<>', (int) $verification->client_id)
            ->exists();

        $outcome = $this->rules->evaluate($data, [$verification->user?->full_name, $verification->client?->contact_person], Carbon::today(), $usedElsewhere);

        $verification->fill([
            'status' => $outcome['decision'],
            'auto_decision' => $outcome['decision'],
            'reader_error' => null,
            'id_type' => is_string($data['id_type'] ?? null) ? mb_substr(strtolower($data['id_type']), 0, 30) : null,
            'id_number_hash' => $hash,
            'id_number_last4' => $number !== '' ? substr($number, -4) : null,
            'extracted' => $this->safeFields($data),
            'checks' => ['decision' => $outcome['decision'], 'reasons' => $outcome['reasons'], 'items' => $outcome['checks']],
        ]);
        if ($verification->status !== IdentityVerification::STATUS_NEEDS_REVIEW) {
            $verification->purge_after = now()->addDays((int) config('services.identity.retention_days', 90));
        }
        $verification->save();

        if ($verification->status === IdentityVerification::STATUS_VERIFIED && $verification->client) {
            $verification->client->identity_verified_at = now()->toDateTimeString();
            $verification->client->save();
        }

        $this->afterDecision($verification);
    }

    /** The reader could not run or answer: hand the ID to the admins instead of blocking the client. */
    public function markReaderFailed(IdentityVerification $verification, string $error): void
    {
        $verification->fill([
            'status' => IdentityVerification::STATUS_NEEDS_REVIEW,
            'auto_decision' => null,
            'reader_error' => mb_substr($error, 0, 255),
            'checks' => ['decision' => 'needs_review', 'reasons' => ['The ID could not be read automatically. An admin will check it.'], 'items' => []],
        ])->save();

        $this->afterDecision($verification);
    }

    public function decide(IdentityVerification $verification, User $admin, string $decision, ?string $note): void
    {
        $verification->fill([
            'status' => $decision,
            'reviewed_by' => $admin->user_id,
            'reviewed_at' => now(),
            'review_note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'purge_after' => now()->addDays((int) config('services.identity.retention_days', 90)),
        ])->save();

        $client = $verification->client;
        if ($client) {
            $hasVerified = IdentityVerification::query()->where('client_id', $client->client_id)
                ->where('status', IdentityVerification::STATUS_VERIFIED)->exists();
            $client->identity_verified_at = $hasVerified ? ($client->identity_verified_at ?? now()->toDateTimeString()) : null;
            $client->save();
        }

        $this->activity->record($admin, 'ID Verification', 'ID #'.$verification->verification_id.' '.($decision === IdentityVerification::STATUS_VERIFIED ? 'approved' : 'rejected'), [
            'verification_id' => $verification->verification_id,
            'client_id' => $verification->client_id,
            'decision' => $decision,
        ]);

        $this->notifySafely(fn () => $this->notifications->forUsers(
            [$verification->user_id], $admin, 'identity_decision',
            $decision === IdentityVerification::STATUS_VERIFIED ? 'ID verified' : 'ID not accepted',
            $decision === IdentityVerification::STATUS_VERIFIED
                ? 'Your ID has been verified.'
                : 'Your ID was not accepted'.($note ? ': '.$note : '.').' Please verify again on My Profile.',
            null, null, $verification->client_id,
        ));
    }

    /** Decrypted ID image (or the selfie, for $kind "selfie"), or null after the retention purge. */
    public function imageBytes(IdentityVerification $verification, string $kind = 'id'): ?string
    {
        $path = $kind === 'selfie' ? $verification->selfie_path : $verification->file_path;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Crypt::decryptString((string) Storage::disk('local')->get($path));
    }

    /** Store an image encrypted on the private disk; returns its path. */
    public function storeImage(IdentityVerification $verification, string $bytes): string
    {
        $path = 'identity/'.(int) $verification->client_id.'/'.Str::uuid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($bytes));

        return $path;
    }

    /** Delete ID images and selfies whose retention period has passed; the decision record stays. */
    public function purgeExpiredFiles(): int
    {
        $count = 0;
        IdentityVerification::query()
            ->where(fn ($query) => $query->whereNotNull('file_path')->orWhereNotNull('selfie_path'))
            ->whereNull('file_purged_at')
            ->where('purge_after', '<', now())->each(function (IdentityVerification $verification) use (&$count): void {
                foreach (array_filter([$verification->file_path, $verification->selfie_path]) as $path) {
                    Storage::disk('local')->delete((string) $path);
                }
                $verification->forceFill(['file_path' => null, 'selfie_path' => null, 'file_purged_at' => now()])->save();
                $count++;
            });

        return $count;
    }

    /** Data for the ID verification panel on the client's My Profile page. */
    public function panelData(User $user): array
    {
        $history = IdentityVerification::query()->where('user_id', $user->user_id)
            ->orderByDesc('verification_id')->limit(10)->get();
        $client = $user->client_id ? Client::query()->find($user->client_id) : null;

        return [
            'latest' => $history->first(),
            'history' => $history,
            'companyVerified' => (bool) $client?->identity_verified_at,
            'idTypes' => collect(PhIdRules::TYPES)->map(fn ($type) => $type[0])->all(),
        ];
    }

    public function attemptsToday(User $user): int
    {
        return IdentityVerification::query()->where('user_id', $user->user_id)->where('created_at', '>=', Carbon::today())->count();
    }

    /** Log the automatic result and tell the right people (the client is no longer waiting on the page). */
    private function afterDecision(IdentityVerification $verification): void
    {
        $user = $verification->user;
        $client = $verification->client;

        $this->activity->record($user, 'ID Verification', 'ID checked: '.$verification->statusLabel(), [
            'verification_id' => $verification->verification_id,
            'id_type' => $verification->id_type,
            'status' => $verification->status,
        ]);

        if ($verification->status === IdentityVerification::STATUS_NEEDS_REVIEW) {
            $this->notifySafely(fn () => $this->notifications->forUsers(
                User::query()->where('status', 'active')->where('role', 'admin')->pluck('user_id'),
                $user, 'identity_review', 'ID needs review',
                ($client?->client_name ?? $user?->full_name ?? 'A client').' uploaded an ID that needs a manual check.',
                null, null, $client?->client_id,
            ));
        }

        [$title, $message] = match ($verification->status) {
            IdentityVerification::STATUS_VERIFIED => ['ID verified', 'Your ID has been verified.'],
            IdentityVerification::STATUS_REJECTED => ['ID not accepted', 'Your ID could not be accepted. Open My Profile to see why and try again.'],
            default => ['ID under review', 'Your ID needs a quick manual check. We will notify you when it is done.'],
        };
        $this->notifySafely(fn () => $this->notifications->forUsers(
            [$verification->user_id], null, 'identity_result', $title, $message, null, null, $verification->client_id,
        ));
    }

    private function hashNumber(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /** Keep only the fields the review page shows; the full ID number is never stored. */
    private function safeFields(array $data): array
    {
        $fields = [];
        foreach (['full_name', 'first_name', 'middle_name', 'last_name', 'birth_date', 'expiry_date', 'has_face_photo', 'confidence', 'quality_issues', 'tamper_signs', 'provider_decision', 'provider_warnings',
            'face_checked', 'face_score', 'face_failures', 'face_issues', 'digital_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = is_string($data[$key]) ? mb_substr($data[$key], 0, 150) : $data[$key];
            }
        }

        return $fields;
    }

    private function notifySafely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
