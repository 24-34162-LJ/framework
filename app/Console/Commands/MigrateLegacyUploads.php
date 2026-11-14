<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\ShipmentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateLegacyUploads extends Command
{
    protected $signature = 'astratrack:migrate-uploads {source : Absolute path to the old project uploads folder} {--dry-run : List the copies and database path updates without making changes}';

    protected $description = 'Copy referenced AstraTrack uploads into private Laravel storage and update their database paths';

    public function handle(): int
    {
        $sourceRoot = realpath((string) $this->argument('source'));
        if (! $sourceRoot || ! is_dir($sourceRoot)) {
            $this->error('The source uploads folder does not exist.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $copied = 0;
        $missing = 0;

        $documents = ShipmentDocument::query()->where('file_path', 'like', 'uploads/%')->get();
        foreach ($documents as $document) {
            $sourceRelative = $this->safeRelative((string) $document->file_path);
            $targetRelative = $sourceRelative ? 'shipment-documents/legacy/'.substr($sourceRelative, strlen('uploads/')) : null;
            if (! $sourceRelative || ! $targetRelative || ! $this->copyAndUpdate($sourceRoot, $sourceRelative, $targetRelative, $document, 'file_path', $dryRun)) {
                $missing++;
                $this->warn('Skipped missing or unsafe shipment document '.$document->document_id.'.');
                continue;
            }
            $copied++;
        }

        $payments = Payment::query()->where('proof_file_path', 'like', 'uploads/%')->get();
        foreach ($payments as $payment) {
            $sourceRelative = $this->safeRelative((string) $payment->proof_file_path);
            $targetRelative = $sourceRelative ? 'payment-proofs/legacy/'.substr($sourceRelative, strlen('uploads/')) : null;
            if (! $sourceRelative || ! $targetRelative || ! $this->copyAndUpdate($sourceRoot, $sourceRelative, $targetRelative, $payment, 'proof_file_path', $dryRun)) {
                $missing++;
                $this->warn('Skipped missing or unsafe payment proof '.$payment->payment_id.'.');
                continue;
            }
            $copied++;
        }

        $this->info(($dryRun ? 'Dry run: ' : '').$copied.' referenced file(s) '.($dryRun ? 'would be copied.' : 'copied into private storage.'));
        if ($missing > 0) {
            $this->warn($missing.' database reference(s) were skipped.');
        }

        return self::SUCCESS;
    }

    private function safeRelative(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (! str_starts_with($path, 'uploads/') || str_contains($path, "\0")) {
            return null;
        }
        $segments = explode('/', $path);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        return $path;
    }

    private function copyAndUpdate(string $sourceRoot, string $sourceRelative, string $targetRelative, ShipmentDocument|Payment $record, string $column, bool $dryRun): bool
    {
        $candidate = realpath($sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($sourceRelative, strlen('uploads/'))));
        if (! $candidate || ! str_starts_with($candidate, $sourceRoot.DIRECTORY_SEPARATOR) || ! is_file($candidate)) {
            return false;
        }
        if ($dryRun) {
            $this->line($sourceRelative.' -> '.$targetRelative);

            return true;
        }

        if (! Storage::disk('local')->exists($targetRelative)) {
            $stream = fopen($candidate, 'rb');
            if ($stream === false) {
                return false;
            }
            try {
                if (! Storage::disk('local')->put($targetRelative, $stream)) {
                    return false;
                }
            } finally {
                fclose($stream);
            }
        }
        DB::transaction(function () use ($record, $column, $targetRelative): void {
            $record->forceFill([$column => $targetRelative])->save();
        }, 3);

        return true;
    }
}
