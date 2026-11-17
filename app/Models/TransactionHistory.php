<?php

/*
 * FILE PURPOSE
 * File   : app/Models/TransactionHistory.php
 * Feature: Database model
 * Purpose: Table transaction_history: changes / corrections made to a transaction.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionHistory extends Model
{
    protected $table = 'transaction_history';

    protected $primaryKey = 'history_id';

    protected $fillable = ['transaction_id', 'event_type', 'description', 'performed_by'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
