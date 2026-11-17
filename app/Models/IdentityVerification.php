<?php

/*
 * FILE PURPOSE
 * File   : app/Models/IdentityVerification.php
 * Feature: ID Recognition
 * Purpose: Table identity_verifications: one row per client ID check (status, reader result, DocuPass
 *          link, review decision).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    /** Uploaded; the background job has not finished reading it yet. */
    public const STATUS_PROCESSING = 'processing';

    /** A DocuPass live check was started; waiting for the client to finish it on ID Analyzer's page. */
    public const STATUS_PENDING = 'pending';

    /** After this many hours an unfinished DocuPass check no longer blocks starting a new one. */
    public const PENDING_EXPIRY_HOURS = 24;

    /** After this many minutes a still-processing ID is shown to admins (e.g. no queue worker running). */
    public const STALE_PROCESSING_MINUTES = 10;

    /** Bump when the consent text on the upload page changes. */
    public const CONSENT_VERSION = '2026-11-16b';

    protected $table = 'identity_verifications';

    protected $primaryKey = 'verification_id';

    protected $fillable = [
        'user_id', 'client_id', 'status', 'auto_decision', 'reader', 'reader_error', 'id_type',
        'id_number_hash', 'id_number_last4', 'extracted', 'checks', 'file_path', 'file_mime', 'original_name',
        'consent_version', 'consent_at', 'consent_ip', 'reviewed_by', 'reviewed_at', 'review_note',
        'purge_after', 'file_purged_at',
        'provider_reference', 'provider_transaction_id', 'provider_url', 'selfie_path',
    ];

    protected $hidden = ['id_number_hash', 'file_path', 'selfie_path', 'provider_url'];

    protected function casts(): array
    {
        return [
            'extracted' => 'encrypted:array',
            'checks' => 'array',
            'consent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'purge_after' => 'datetime',
            'file_purged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_PROCESSING => $this->isStaleProcessing() ? 'Waiting for reader' : 'Reading ID',
            self::STATUS_PENDING => $this->isOpenPending() ? 'Not finished' : 'Expired',
            default => 'Under review',
        };
    }

    /** A DocuPass check the client can still finish (its link has not expired on our side). */
    public function isOpenPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->created_at !== null
            && $this->created_at->gte(now()->subHours(self::PENDING_EXPIRY_HOURS));
    }

    public function isStaleProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(self::STALE_PROCESSING_MINUTES));
    }

    /** IDs an admin should look at: flagged for review, or stuck in processing (reader never ran). */
    public function scopeNeedsAdmin(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('status', self::STATUS_NEEDS_REVIEW)
            ->orWhere(fn (Builder $stale) => $stale->where('status', self::STATUS_PROCESSING)
                ->where('created_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))));
    }
}
