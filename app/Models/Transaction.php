<?php

/*
 * FILE PURPOSE
 * File   : app/Models/Transaction.php
 * Feature: Database model
 * Purpose: Table transactions: a money transaction linked to a client, shipment and bill.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $primaryKey = 'transaction_id';

    protected $fillable = ['client_id', 'shipment_id', 'billing_id', 'status', 'completed_at'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Payment state of a transaction, from all bills of its shipment. Kept separate from `status`,
     * which follows shipment progress: a shipment can be fully paid before it is delivered, and
     * delivered with bills still open. Amounts are compared in cents to avoid float rounding.
     *
     * @return array{key: string, label: string}
     */
    public static function paymentState(int $billCount, float|string|null $total, float|string|null $paid): array
    {
        $totalCents = (int) round((float) $total * 100);
        $paidCents = (int) round((float) $paid * 100);

        return match (true) {
            $billCount < 1 => ['key' => 'none', 'label' => 'No bills'],
            $paidCents >= $totalCents => ['key' => 'paid', 'label' => 'Fully paid'],
            $paidCents > 0 => ['key' => 'partial', 'label' => 'Partially paid'],
            default => ['key' => 'unpaid', 'label' => 'Unpaid'],
        };
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id', 'shipment_id');
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class, 'billing_id', 'billing_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TransactionHistory::class, 'transaction_id', 'transaction_id');
    }
}
