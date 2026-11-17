<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_17_000002_add_remember_token_to_users.php
 * Feature: Security
 * Purpose: Adds users.remember_token so "Remember me" on the login page keeps people signed in.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy schema has no remember_token column. Databases that already have it are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'remember_token')) {
            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->string('remember_token', 100)->nullable();
        });
    }

    public function down(): void
    {
        // Never drop: databases that had the column before this migration still rely on it.
    }
};
