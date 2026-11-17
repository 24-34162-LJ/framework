<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Delivery.php
 * Feature: Database model
 * Purpose: Table deliveries: delivery schedule and details of a shipment.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $primaryKey = 'delivery_id';

    protected $fillable = [
        'shipment_id', 'trucker', 'plate_no', 'driver_name', 'delivery_address',
        'receiving_contact', 'scheduled_date', 'scheduled_time', 'delivered_date', 'status',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'scheduled_time' => 'string',
            'delivered_date' => 'date',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }
}
