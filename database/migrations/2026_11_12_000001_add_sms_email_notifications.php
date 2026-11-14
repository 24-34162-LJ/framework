<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS and email notifications: a mobile number and SMS on/off switch per user,
 * and one notification_deliveries row per SMS/email attempt (sent / failed / skipped).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'phone')) {
                    $table->string('phone', 20)->nullable();
                }
                if (! Schema::hasColumn('users', 'sms_enabled')) {
                    $table->boolean('sms_enabled')->default(true);
                }
            });
        }

        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table): void {
                $table->bigIncrements('delivery_id');
                $table->unsignedBigInteger('notification_id')->nullable();
                $table->unsignedInteger('shipment_id')->nullable();
                $table->string('channel', 10);
                $table->string('recipient', 120);
                $table->string('subject', 150)->nullable();
                $table->string('message', 500);
                $table->string('status', 10)->default('pending')->index('idx_delivery_status');
                $table->string('provider_message_id', 64)->nullable();
                $table->string('error', 255)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('sent_at')->nullable();

                if (Schema::hasTable('notifications')) {
                    $table->foreign('notification_id', 'fk_delivery_notification')
                        ->references('notification_id')->on('notifications')->cascadeOnDelete();
                }
                if (Schema::hasTable('shipments')) {
                    $table->foreign('shipment_id', 'fk_delivery_shipment')
                        ->references('shipment_id')->on('shipments')->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');

        if (Schema::hasTable('users')) {
            foreach (['phone', 'sms_enabled'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    Schema::table('users', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
