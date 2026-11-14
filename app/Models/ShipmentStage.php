<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentStage extends Model
{
    protected $table = 'shipment_stages';

    protected $primaryKey = 'stage_id';

    protected $fillable = ['stage_name', 'stage_group', 'sort_order', 'is_closed'];

    public $timestamps = false;

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'current_status', 'stage_name');
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(ShipmentStatusUpdate::class, 'stage', 'stage_name');
    }
}
