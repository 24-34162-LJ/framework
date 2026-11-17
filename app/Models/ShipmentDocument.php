<?php

/*
 * FILE PURPOSE
 * File   : app/Models/ShipmentDocument.php
 * Feature: Database model
 * Purpose: Table shipment_documents: files attached to a shipment, with review status and versions.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDocument extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    protected $table = 'shipment_documents';

    protected $primaryKey = 'document_id';

    protected $fillable = [
        'shipment_id', 'doc_type', 'file_path', 'remarks', 'uploaded_by',
        'source', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
        'file_hash', 'original_name', 'replaces_document_id', 'superseded_at', 'ai_check',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'reviewed_at' => 'datetime', 'superseded_at' => 'datetime', 'ai_check' => 'array'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id', 'document_id');
    }

    /** Current versions only (older versions stay as history). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /** Documents that count toward the shipment checklist and downloads for everyone. */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->where(fn ($q) => $q->where('review_status', self::REVIEW_ACCEPTED)->orWhereNull('review_status'));
    }

    public function isAccepted(): bool
    {
        return ($this->review_status ?? self::REVIEW_ACCEPTED) === self::REVIEW_ACCEPTED && $this->superseded_at === null;
    }
}
