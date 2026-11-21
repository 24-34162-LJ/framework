<?php

/*
 * FILE PURPOSE
 * File   : app/Console/Commands/ImportPsgcLocations.php
 * Feature: Delivery address lookup
 * Purpose: Artisan command that loads database/data/psgc_locations.csv (PSA PSGC list of provinces,
 *          cities/towns and barangays) into the psgc_locations table. Safe to re-run after an update.
 */

namespace App\Console\Commands;

use App\Models\PsgcLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPsgcLocations extends Command
{
    protected $signature = 'psgc:import {--path= : CSV file to load (defaults to database/data/psgc_locations.csv)}';

    protected $description = 'Load the PH provinces, cities/towns and barangays used by the delivery address suggestions';

    private const TYPES = ['province', 'city', 'municipality', 'barangay'];

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: database_path('data/psgc_locations.csv'));
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if (! $handle) {
            $this->error('PSGC file not found: '.$path);

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header !== ['code', 'type', 'name', 'city', 'province', 'region']) {
            fclose($handle);
            $this->error('Unexpected columns. Expected: code,type,name,city,province,region');

            return self::FAILURE;
        }

        $count = 0;
        DB::transaction(function () use ($handle, &$count): void {
            PsgcLocation::query()->delete();
            $batch = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== 6 || ! in_array($row[1], self::TYPES, true)) {
                    continue;
                }
                [$code, $type, $name, $city, $province] = $row;
                $label = $this->label($type, $name, $city, $province);
                $batch[] = [
                    'code' => $code,
                    'type' => $type,
                    'name' => mb_substr($name, 0, 120),
                    'label' => mb_substr($label, 0, 255),
                    // "City of Cebu" ranks as "cebu", so typing "cebu" puts the city first.
                    'name_key' => substr(PsgcLocation::normalize((string) preg_replace('/^city of\s+/i', '', $name)), 0, 120),
                    'search_key' => substr(PsgcLocation::normalize($label), 0, 255),
                ];
                if (count($batch) === 1000) {
                    PsgcLocation::query()->insert($batch);
                    $count += count($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                PsgcLocation::query()->insert($batch);
                $count += count($batch);
            }
        });
        fclose($handle);

        $this->info("Imported {$count} locations.");

        return self::SUCCESS;
    }

    /** "Brgy. Santol, Mataasnakahoy, Batangas" · "Mataasnakahoy, Batangas" · "Batangas" */
    private function label(string $type, string $name, string $city, string $province): string
    {
        if ($type === 'barangay' && ! preg_match('/^(barangay|brgy)\b/i', $name)) {
            $name = 'Brgy. '.$name;
        }
        $parts = [$name];
        if ($type === 'barangay' && $city !== '') {
            $parts[] = $city;
        }
        if ($type !== 'province' && $province !== '') {
            $parts[] = $province;
        }

        return implode(', ', $parts);
    }
}
