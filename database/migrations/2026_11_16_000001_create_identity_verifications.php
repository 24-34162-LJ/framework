<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ID recognition for client onboarding (KYC): one row per uploaded government ID.
 * The ID image is stored encrypted on the private disk; the ID number is kept only as an
 * HMAC hash plus its last 4 characters; the fields read from the ID are an encrypted JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_verifications')) {
            Schema::create('identity_verifications', function (Blueprint $table): void {
                $table->bigIncrements('verification_id');
                $table->unsignedInteger('user_id')->index('idx_idv_user');
                $table->unsignedInteger('client_id')->nullable()->index('idx_idv_client');
                $table->string('status', 20)->default('needs_review')->index('idx_idv_status');
                $table->string('auto_decision', 20)->nullable();
                $table->string('reader', 20)->nullable();
                $table->string('reader_error', 255)->nullable();
                $table->string('id_type', 30)->nullable();
                $table->char('id_number_hash', 64)->nullable()->index('idx_idv_number');
                $table->string('id_number_last4', 4)->nullable();
                $table->text('extracted')->nullable();
                $table->text('checks')->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('file_mime', 60)->nullable();
                $table->string('original_name', 255)->nullable();
                $table->string('consent_version', 20);
                $table->dateTime('consent_at');
                $table->string('consent_ip', 45)->nullable();
                $table->unsignedInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->string('review_note', 255)->nullable();
                $table->dateTime('purge_after')->nullable();
                $table->dateTime('file_purged_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'identity_verified_at')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dateTime('identity_verified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'identity_verified_at')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dropColumn('identity_verified_at');
            });
        }
    }
};
