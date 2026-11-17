<?php

/*
 * FILE PURPOSE
 * File   : app/Services/ActivityLogger.php
 * Feature: Activity log
 * Purpose: Helper that writes one row to the activity log.
 */

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Throwable;

class ActivityLogger
{
    /** @param array<string, mixed> $details */
    public function record(?User $actor, string $type, string $description, array $details = []): void
    {
        try {
            ActivityLog::query()->create([
                'activity_type' => mb_substr($type, 0, 50),
                'description' => mb_substr($description, 0, 255),
                'performed_by' => $actor?->user_id,
                'performed_by_name' => $actor?->full_name,
                'performed_by_role' => $actor?->role,
                'details_json' => $details ?: null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
