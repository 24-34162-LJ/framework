<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_18_000001_create_psgc_locations_table.php
 * Feature: Delivery address lookup
 * Purpose: Creates psgc_locations, the local copy of the PSA's list of PH provinces, cities/towns and
 *          barangays that the Delivery Address field suggests from. Filled by `php artisan psgc:import`.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('psgc_locations')) {
            return;
        }
        Schema::create('psgc_locations', function (Blueprint $table): void {
            $table->string('code', 10)->primary();
            $table->string('type', 15);
            $table->string('name', 120);
            $table->string('label', 255);
            $table->string('name_key', 120)->index();
            $table->string('search_key', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_locations');
    }
};
