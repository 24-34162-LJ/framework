<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_17_000002_add_digital_id_to_identity_verifications.php
 * Feature: ID Recognition
 * Purpose: Digital ID (eGovPH / ePhilID screenshot) + live selfie: remembers where the ID image came from
 *          and the QR code ID Analyzer returns, so the client can take the selfie on their phone.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_verifications') || Schema::hasColumn('identity_verifications', 'document_source')) {
            return;
        }
        Schema::table('identity_verifications', function (Blueprint $table): void {
            // "card" = scanned on DocuPass, "digital" = screenshot uploaded on our page, "upload" = photo upload flow
            $table->string('document_source', 20)->nullable();
            // Base64 QR code image of the DocuPass link (cleared when the check finishes)
            $table->mediumText('provider_qr')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('identity_verifications') || ! Schema::hasColumn('identity_verifications', 'document_source')) {
            return;
        }
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->dropColumn(['document_source', 'provider_qr']);
        });
    }
};
