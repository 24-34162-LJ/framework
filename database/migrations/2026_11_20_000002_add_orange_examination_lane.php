<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_20_000002_add_orange_examination_lane.php
 * Feature: Shipments - customs processing
 * Purpose: Adds "orange" to customs_processing.examination_lane (green / yellow / orange / red),
 *          so the lane can match the "Orange lane" bill charge.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM only exists on MySQL/MariaDB; other drivers store the lane as plain text.
        if (! Schema::hasTable('customs_processing') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement("ALTER TABLE customs_processing MODIFY examination_lane ENUM('green','yellow','orange','red') NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('customs_processing') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        // Orange rows have no older equivalent: clear them before narrowing the column.
        DB::table('customs_processing')->where('examination_lane', 'orange')->update(['examination_lane' => null]);
        DB::statement("ALTER TABLE customs_processing MODIFY examination_lane ENUM('green','yellow','red') NULL");
    }
};
