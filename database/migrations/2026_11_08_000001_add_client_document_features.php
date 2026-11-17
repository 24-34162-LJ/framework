<?php

/*
 * FILE PURPOSE
 * File   : database/migrations/2026_11_08_000001_add_client_document_features.php
 * Feature: Database migration
 * Purpose: Adds client document uploads, document requests and shipment requests.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client document uploads: review status, duplicate hash, version history and Gemini check on
 * shipment_documents; staff document requests; client shipment requests.
 * Additive only: existing documents become "accepted" staff documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_documents')) {
            Schema::table('shipment_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('shipment_documents', 'source')) {
                    $table->string('source', 10)->default('staff');
                }
                if (! Schema::hasColumn('shipment_documents', 'review_status')) {
                    $table->string('review_status', 20)->default('accepted');
                }
                if (! Schema::hasColumn('shipment_documents', 'reviewed_by')) {
                    $table->unsignedInteger('reviewed_by')->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'reviewed_at')) {
                    $table->dateTime('reviewed_at')->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'review_note')) {
                    $table->string('review_note', 255)->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'file_hash')) {
                    $table->char('file_hash', 64)->nullable()->index('idx_doc_hash');
                }
                if (! Schema::hasColumn('shipment_documents', 'original_name')) {
                    $table->string('original_name', 255)->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'replaces_document_id')) {
                    $table->unsignedInteger('replaces_document_id')->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'superseded_at')) {
                    $table->dateTime('superseded_at')->nullable();
                }
                if (! Schema::hasColumn('shipment_documents', 'ai_check')) {
                    $table->text('ai_check')->nullable();
                }
            });
        }

        if (! Schema::hasTable('document_requests')) {
            Schema::create('document_requests', function (Blueprint $table): void {
                $table->increments('request_id');
                $table->unsignedInteger('shipment_id')->index();
                $table->string('doc_type', 40);
                $table->string('note', 255)->nullable();
                $table->date('due_date')->nullable();
                $table->string('status', 20)->default('open'); // open | fulfilled | cancelled
                $table->unsignedInteger('requested_by')->nullable();
                $table->dateTime('requested_at')->useCurrent();
                $table->unsignedInteger('fulfilled_document_id')->nullable();
                $table->dateTime('fulfilled_at')->nullable();
            });
        }

        if (! Schema::hasTable('shipment_requests')) {
            Schema::create('shipment_requests', function (Blueprint $table): void {
                $table->increments('request_id');
                $table->unsignedInteger('client_id')->index();
                $table->unsignedInteger('submitted_by')->nullable();
                $table->string('status', 20)->default('pending'); // pending | converted | declined
                $table->string('file_path', 255);
                $table->string('original_name', 255)->nullable();
                $table->char('file_hash', 64)->nullable();
                $table->string('extraction_status', 20)->default('waiting'); // waiting | done | failed
                $table->mediumText('extracted_json')->nullable();
                $table->string('extraction_error', 255)->nullable();
                $table->string('client_notes', 1000)->nullable();
                $table->string('staff_note', 255)->nullable();
                $table->unsignedInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedInteger('shipment_id')->nullable();
                $table->dateTime('created_at')->useCurrent();
            });
        }

        // Backfill hashes for existing readable files so duplicate detection covers them too.
        if (Schema::hasColumn('shipment_documents', 'file_hash')) {
            foreach (DB::table('shipment_documents')->whereNull('file_hash')->get(['document_id', 'file_path']) as $row) {
                $path = storage_path('app/private/'.ltrim(str_replace('\\', '/', (string) $row->file_path), '/'));
                if (! str_contains((string) $row->file_path, '..') && is_file($path)) {
                    DB::table('shipment_documents')->where('document_id', $row->document_id)->update(['file_hash' => hash_file('sha256', $path)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_requests');
        Schema::dropIfExists('document_requests');
        if (Schema::hasTable('shipment_documents')) {
            $columns = array_values(array_filter(
                ['source', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'file_hash', 'original_name', 'replaces_document_id', 'superseded_at', 'ai_check'],
                fn (string $column): bool => Schema::hasColumn('shipment_documents', $column),
            ));
            if (in_array('file_hash', $columns, true)) {
                Schema::table('shipment_documents', fn (Blueprint $table) => $table->dropIndex('idx_doc_hash'));
            }
            if ($columns) {
                Schema::table('shipment_documents', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
