<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal SQLite copy of the legacy tables the notification code touches.
 * The real baseline migration only runs on MySQL, so tests build these by hand
 * and then run the SMS/email migration on top of them.
 */
trait CreatesNotificationTables
{
    protected function createNotificationTables(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->increments('client_id');
            $table->string('client_name', 150);
            $table->string('contact_person', 100)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('status', 10)->default('active');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('full_name', 100);
            $table->string('email', 120);
            $table->string('password_hash', 255)->default('x');
            $table->string('role', 10);
            $table->unsignedInteger('client_id')->nullable();
            $table->string('status', 10)->default('active');
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->increments('shipment_id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('assigned_staff_id')->nullable();
            $table->string('reference_no', 30);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('notification_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('shipment_id')->nullable();
            $table->string('event_type', 40);
            $table->string('title', 120);
            $table->string('message', 255);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('read_at')->nullable();
        });

        (require database_path('migrations/2026_11_12_000001_add_sms_email_notifications.php'))->up();
    }
}
