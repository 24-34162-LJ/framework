<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Payment.php
 * Feature: Database model
 * Purpose: Table payments: a payment made against a bill, who recorded / checked / verified it,
 *          and an admin reversal when a verified payment is undone.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'billing_id', 'amount', 'payment_date', 'payment_method', 'reference_no',
        'proof_file_path', 'verification_status', 'verified_by', 'recorded_by',
        'verified_at', 'staff_checked_by', 'staff_checked_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'verified_at' => 'datetime',
            'staff_checked_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function staffCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_checked_by', 'user_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by', 'user_id');
    }

    /** A verified payment an admin later undid; it no longer counts toward the bill. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Amount at or above which a staff verification still needs an admin's final approval. */
    public static function needsAdminApproval(float|string $amount): bool
    {
        return (int) round((float) $amount * 100) >= (int) round((float) config('billing.admin_approval_threshold', 50000) * 100);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }
}
