<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Shipment.php
 * Feature: Database model
 * Purpose: Table shipments: the main shipment record, with client, staff, stage, containers, customs,
 *          deliveries.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $table = 'shipments';

    protected $primaryKey = 'shipment_id';

    protected $fillable = [
        'reference_no', 'client_id', 'assigned_staff_id', 'transaction_type', 'mode', 'bl_awb_no',
        'shipper_name', 'consignee_name', 'vessel_or_flight', 'port_of_loading', 'port_of_discharge',
        'hs_code', 'total_packages', 'total_gross_weight', 'measurement_cbm', 'incoterm', 'invoice_no',
        'currency', 'invoice_value', 'freight_amount', 'fob_value', 'current_status',
        'actual_arrival_date', 'eta_date', 'first_free_day', 'delivery_due_date', 'archived_at',
        'archived_by', 'archive_reason', 'remarks',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'actual_arrival_date' => 'date',
            'eta_date' => 'date',
            'first_free_day' => 'date',
            'delivery_due_date' => 'date',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'invoice_value' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'fob_value' => 'decimal:2',
            'total_gross_weight' => 'decimal:3',
            'measurement_cbm' => 'decimal:3',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id', 'user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'user_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ShipmentStage::class, 'current_status', 'stage_name');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class, 'shipment_id', 'shipment_id');
    }

    public function customsProcessingRecords(): HasMany
    {
        return $this->hasMany(CustomsProcessing::class, 'shipment_id', 'shipment_id');
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(ShipmentStatusUpdate::class, 'shipment_id', 'shipment_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'shipment_id', 'shipment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'shipment_id', 'shipment_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class, 'shipment_id', 'shipment_id');
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class, 'shipment_id', 'shipment_id');
    }

    /** Clients may upload while the shipment is open: not archived and not delivered/cancelled. */
    public function acceptsClientUploads(): bool
    {
        $this->loadMissing('stage');

        return $this->isOpen();
    }

    /** Not archived and not in a closed stage (delivered/cancelled). */
    public function isOpen(): bool
    {
        $this->loadMissing('stage');

        return $this->archived_at === null && ! (bool) $this->stage?->is_closed;
    }

    /**
     * Why an admin/staff user may not change this shipment's details, or null when they may.
     * Same rule as the Edit page: archived is read-only for everyone; closed is read-only except for admins.
     */
    /** Staff may look at an unassigned shipment, but must claim it ("Assign to me") before changing it. */
    public function needsClaimBy(User $user): bool
    {
        return $user->role === 'staff' && $this->assigned_staff_id === null;
    }

    public function officeChangeBlockedFor(User $user): ?string
    {
        $this->loadMissing('stage');

        if ($this->archived_at !== null) {
            return $this->reference_no.' is archived and read-only.';
        }
        if ($this->needsClaimBy($user)) {
            return 'No staff is assigned to '.$this->reference_no.'. Assign it to yourself (Assign to me) before making changes.';
        }
        if ((bool) $this->stage?->is_closed && $user->role !== 'admin') {
            return $this->reference_no.' is closed. Only an administrator can change it.';
        }

        return null;
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class, 'shipment_id', 'shipment_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'shipment_id', 'shipment_id');
    }
}
