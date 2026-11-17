<?php

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
    ];

    protected $hidden = ['id_number_hash', 'file_path'];

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
            default => 'Under review',
        };
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
