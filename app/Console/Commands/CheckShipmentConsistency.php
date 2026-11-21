<?php

/*
 * FILE PURPOSE
 * File   : app/Console/Commands/CheckShipmentConsistency.php
 * Feature: Shipments - consistency rules
 * Purpose: Report of every shipment whose status, Customs Details, bills, payments or transaction
 *          disagree (the same checks as the shipment page panel). Read-only: it changes nothing.
 *          Usage: php artisan shipments:check-consistency [--all] [--include-archived]
 */

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\ShipmentConsistencyService;
use Illuminate\Console\Command;

class CheckShipmentConsistency extends Command
{
    protected $signature = 'shipments:check-consistency
        {--all : List every shipment, including those with no issues}
        {--include-archived : Also check archived shipments}';

    protected $description = 'List shipments whose status, customs details, bills, payments or transaction disagree';

    public function handle(ShipmentConsistencyService $consistency): int
    {
        $rows = [];
        $withIssues = 0;
        $query = Shipment::query()->orderBy('shipment_id')->when(! $this->option('include-archived'), fn ($q) => $q->whereNull('archived_at'));
        foreach ($query->cursor() as $shipment) {
            $issues = array_values(array_filter($consistency->checks($shipment), fn (array $check) => ! $check['ok']));
            $withIssues += $issues === [] ? 0 : 1;
            if ($issues === [] && ! $this->option('all')) {
                continue;
            }
            if ($issues === []) {
                $rows[] = [$shipment->reference_no, $shipment->current_status, 'OK', 'All records agree'];
            }
            foreach ($issues as $i => $issue) {
                $rows[] = [$i === 0 ? $shipment->reference_no : '', $i === 0 ? $shipment->current_status : '', $issue['issue'], $issue['detail']];
            }
        }

        if ($rows !== []) {
            $this->table(['Shipment', 'Stage', 'Check', 'Detail'], $rows);
        }
        $this->line($withIssues === 0 ? 'No shipment has an inconsistency.' : $withIssues.' '.($withIssues === 1 ? 'shipment needs' : 'shipments need').' review.');

        return self::SUCCESS;
    }
}
