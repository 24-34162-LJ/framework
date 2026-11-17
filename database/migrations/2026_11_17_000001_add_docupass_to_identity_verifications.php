<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live ID + selfie verification through ID Analyzer DocuPass: the hosted session's reference and link,
 * the resulting ID Analyzer transaction, and the selfie image (stored encrypted, like the ID image).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_verifications') || Schema::hasColumn('identity_verifications', 'provider_reference')) {
            return;
        }
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->string('provider_reference', 64)->nullable()->index('idx_idv_provider_ref');
            $table->string('provider_transaction_id', 64)->nullable();
            $table->string('provider_url', 500)->nullable();
            $table->string('selfie_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('identity_verifications') || ! Schema::hasColumn('identity_verifications', 'provider_reference')) {
            return;
        }
        Schema::table('identity_verifications', function (Blueprint $table): void {
            $table->dropIndex('idx_idv_provider_ref');
            $table->dropColumn(['provider_reference', 'provider_transaction_id', 'provider_url', 'selfie_path']);
        });
    }
};
