<?php

/*
 * FILE PURPOSE
 * File   : app/Jobs/ReadIdentityDocument.php
 * Feature: ID Recognition
 * Purpose: Background job that reads an uploaded ID photo (Gemini or ID Analyzer) so the client does
 *          not wait for the result.
 */

namespace App\Jobs;

use App\Models\IdentityVerification;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads an uploaded ID with the IdDocumentReader (Gemini) in the background, so the client's
 * upload returns at once instead of waiting ~30 s. Runs inline when QUEUE_CONNECTION=sync.
 */
class ReadIdentityDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Gemini can take up to ~170 s; keep DB_QUEUE_RETRY_AFTER above this. */
    public int $timeout = 200;

    public function __construct(public int $verificationId) {}

    public function handle(IdentityVerificationService $service): void
    {
        $verification = IdentityVerification::query()->find($this->verificationId);
        if ($verification && $verification->status === IdentityVerification::STATUS_PROCESSING) {
            $service->process($verification);
        }
    }

    /** A crashed or timed-out read never leaves the client stuck: the ID goes to admin review. */
    public function failed(?Throwable $exception): void
    {
        $verification = IdentityVerification::query()->find($this->verificationId);
        if ($verification && $verification->status === IdentityVerification::STATUS_PROCESSING) {
            app(IdentityVerificationService::class)->markReaderFailed($verification, 'The background ID reader stopped unexpectedly.');
        }
    }
}
