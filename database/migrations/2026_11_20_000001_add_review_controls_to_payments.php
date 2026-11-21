<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_20_000001_add_review_controls_to_payments.php
 * Feature: Billing - payment review
 * Purpose: Adds the columns behind the payment review controls: when a payment was verified, the
 *          staff check that large payments get before an admin's final approval, and an admin
 *          reversal of a verified payment (who, when and why).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * verification_status keeps its three values (pending / verified / rejected):
 * - a large payment checked by staff stays "pending" with staff_checked_by set, until an admin verifies it;
 * - a reversed payment goes back to "rejected" with reversed_by / reversed_at / reversal_reason set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || Schema::hasColumn('payments', 'verified_at')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->dateTime('verified_at')->nullable()->after('verified_by');
            $table->unsignedInteger('staff_checked_by')->nullable()->after('verified_at');
            $table->dateTime('staff_checked_at')->nullable()->after('staff_checked_by');
            $table->unsignedInteger('reversed_by')->nullable()->after('staff_checked_at');
            $table->dateTime('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 255)->nullable()->after('reversed_at');
            $table->index(['verification_status', 'verified_at'], 'idx_pay_status_verified_at');
        });
        self::backfillVerifiedAt();
    }

    /**
     * Payments verified before this migration have no verified_at. The activity log recorded each
     * decision ("Payment verified for bill …" with the payment_id), so take the time from there.
     */
    public static function backfillVerifiedAt(): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }
        DB::table('activity_log')->where('activity_type', 'Billing Change')->where('description', 'like', 'Payment verified for bill%')
            ->orderBy('activity_id')->get(['details_json', 'created_at'])
            ->each(function ($entry): void {
                $paymentId = (int) (json_decode((string) $entry->details_json, true)['payment_id'] ?? 0);
                if ($paymentId > 0 && $entry->created_at) {
                    DB::table('payments')->where('payment_id', $paymentId)->where('verification_status', 'verified')
                        ->whereNull('verified_at')->update(['verified_at' => $entry->created_at]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'verified_at')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('idx_pay_status_verified_at');
            $table->dropColumn(['verified_at', 'staff_checked_by', 'staff_checked_at', 'reversed_by', 'reversed_at', 'reversal_reason']);
        });
    }
};
