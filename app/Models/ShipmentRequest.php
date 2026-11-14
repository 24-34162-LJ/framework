<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's request for a new shipment, started from an uploaded document packet. */
class ShipmentRequest extends Model
{
    protected $table = 'shipment_requests';

    protected $primaryKey = 'request_id';

    public $timestamps = false;

    protected $fillable = [
        'client_id', 'submitted_by', 'status', 'file_path', 'original_name', 'file_hash',
        'extraction_status', 'extracted_json', 'extraction_error', 'client_notes', 'staff_note',
        'reviewed_by', 'reviewed_at', 'shipment_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'reviewed_at' => 'datetime', 'extracted_json' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function referenceLabel(): string
    {
        return 'REQ-'.($this->created_at?->format('Y') ?? date('Y')).'-'.str_pad((string) $this->request_id, 4, '0', STR_PAD_LEFT);
    }
}
