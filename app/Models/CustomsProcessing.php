<?php

/*
 * FILE PURPOSE
 * File   : app/Models/CustomsProcessing.php
 * Feature: Database model
 * Purpose: Table customs_processing: customs steps and results recorded for a shipment.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomsProcessing extends Model
{
    protected $table = 'customs_processing';

    protected $primaryKey = 'customs_id';

    protected $fillable = [
        'shipment_id', 'entry_no', 'declaration_type', 'lodgement_date', 'customs_value',
        'duties_taxes_amount', 'duties_paid_date', 'assessment_status', 'examination_lane',
        'clearance_date', 'customs_remarks', 'updated_by',
    ];

    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    protected function casts(): array
    {
        return [
            'lodgement_date' => 'date',
            'duties_paid_date' => 'date',
            'clearance_date' => 'date',
            'customs_value' => 'decimal:2',
            'duties_taxes_amount' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'user_id');
    }
}
