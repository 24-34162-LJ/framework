<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentStatusUpdate extends Model
{
    protected $table = 'shipment_status_updates';

    protected $primaryKey = 'update_id';

    protected $fillable = ['shipment_id', 'stage', 'status', 'remarks', 'updated_by'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function stageDefinition(): BelongsTo
    {
        return $this->belongsTo(ShipmentStage::class, 'stage', 'stage_name');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }
}
