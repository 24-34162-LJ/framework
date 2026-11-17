<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Billing.php
 * Feature: Database model
 * Purpose: Table billing: a bill for a client/shipment, with its items, payments and transactions.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Billing extends Model
{
    protected $table = 'billings';

    protected $primaryKey = 'billing_id';

    protected $fillable = [
        'billing_no', 'client_id', 'shipment_id', 'billing_date', 'due_date',
        'total_amount', 'payment_status', 'created_by',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'billing_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'billing_id', 'billing_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'billing_id', 'billing_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'billing_id', 'billing_id');
    }
}
