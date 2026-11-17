<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Container.php
 * Feature: Database model
 * Purpose: Table containers: a container that belongs to a shipment.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Container extends Model
{
    protected $table = 'containers';

    protected $primaryKey = 'container_id';

    protected $fillable = ['shipment_id', 'container_no', 'seal_no', 'size_type'];

    public $timestamps = false;

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }
}
