<?php

/*
 * FILE PURPOSE
 * File   : app/Models/ActivityLog.php
 * Feature: Database model
 * Purpose: Table activity_log: one row per action a user did (used by the activity pages and audit
 *          trail).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_log';

    protected $primaryKey = 'activity_id';

    protected $fillable = [
        'activity_type', 'description', 'performed_by', 'performed_by_name',
        'performed_by_role', 'details_json',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
