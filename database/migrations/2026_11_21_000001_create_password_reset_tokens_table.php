<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_21_000001_create_password_reset_tokens_table.php
 * Feature: Security
 * Purpose: Stores one hashed "reset your password" token per email for the Forgot password page.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 120)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
