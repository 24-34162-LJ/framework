<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the legacy schema only for an empty MySQL database.
     * Existing AstraTrack tables and their data are deliberately left untouched.
     */
    public function up(): void
    {
        $legacyTables = [
            'users', 'clients', 'shipment_stages', 'shipments', 'containers', 'customs_processing',
            'shipment_status_updates', 'notifications', 'deliveries', 'shipment_documents', 'billings',
            'billing_items', 'payments', 'transactions', 'transaction_history', 'activity_log',
        ];

        foreach ($legacyTables as $table) {
            if (Schema::hasTable($table)) {
                return;
            }
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $schemaFile = database_path('legacy-schema/astratrack_mysql.sql');
        if (! is_file($schemaFile)) {
            throw new RuntimeException('The AstraTrack baseline schema file is missing.');
        }

        $source = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($schemaFile));
        foreach (explode(';', (string) $source) as $statement) {
            $statement = trim($statement);
            if (preg_match('/^CREATE\s+(?:TABLE|VIEW)\b/i', $statement) !== 1) {
                continue;
            }

            DB::unprepared($statement);
        }
    }

    /**
     * The baseline never drops application tables or data.
     */
    public function down(): void {}
};
