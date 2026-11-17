<?php

/*
 * FILE PURPOSE
 * File   : app/Models/DocumentRequest.php
 * Feature: Database model
 * Purpose: Table document_requests: staff asking a client to upload a specific document.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff request asking the client to upload a specific document type for a shipment. */
class DocumentRequest extends Model
{
    protected $table = 'document_requests';

    protected $primaryKey = 'request_id';

    public $timestamps = false;

    protected $fillable = ['shipment_id', 'doc_type', 'note', 'due_date', 'status', 'requested_by', 'requested_at', 'fulfilled_document_id', 'fulfilled_at'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'requested_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_date !== null && $this->due_date->lt(now()->startOfDay());
    }
}
