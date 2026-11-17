<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/Admin/ForecastingController.php
 * Feature: Forecasting
 * Purpose: Customer volume forecasting: counts each client's shipments per month and asks the Python
 *          Chronos service (forecasting/) to predict the next months.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Customer Volume Forecasting (Amazon Chronos)
 * --------------------------------------------
 * The whole forecasting feature lives in this one controller:
 *
 *   Step 1  buildHistory()   Count each client's shipments per month (MySQL).
 *   Step 2  askChronos()     Send those monthly counts to the local Chronos
 *                            service (forecasting/chronos_service.py).
 *   Step 3  summarize()      Turn the answer into next-month predictions,
 *                            a low/high range and an up/down trend per client.
 *   Step 4  index()          Show it on Reports -> Forecasting tab (chart + table).
 *
 * Page: /admin/reports/forecasting      Demo data: /admin/reports/forecasting?demo=1
 */
class ForecastingController extends Controller
{
    private const HISTORY_MONTHS = 24;   // how far back we look
    private const MIN_MONTHS = 3;        // minimum history before we forecast a client
    private const TREND_THRESHOLD = 10;  // % change counted as "up" or "down"

    public function index(Request $request): View
    {
        $horizon = min(max($request->integer('horizon', 3), 1), 6);
        $demo = $request->boolean('demo');
        $clientId = $request->integer('client_id');
        $data = $this->forecast($demo, $horizon);
        $selected = $clientId && isset($data['forecasts'][$clientId]) ? $clientId : (array_key_first($data['forecasts']) ?? 0);

        // Step 4: show the page.
        return view('admin.forecasting.index', [
            'demo' => $demo,
            'horizon' => $horizon,
            'error' => $data['error'],
            'rows' => $data['rows'],
            'skipped' => $data['skipped'],
            'selected' => $selected,
            'clients' => array_intersect_key($data['names'], $data['forecasts']),
            'chart' => $selected ? [
                'labels' => $data['labels'],
                'actual' => $data['history'][$selected],
                'forecast' => $data['forecasts'][$selected],
            ] : null,
            'nextMonth' => $data['nextMonth'],
        ]);
    }

    /**
     * Short version for the Admin Dashboard card (same forecast, no chart).
     */
    public function summary(bool $demo, int $horizon = 3): array
    {
        $data = $this->forecast($demo, $horizon);

        return ['demo' => $demo] + array_intersect_key($data, array_flip(['rows', 'skipped', 'error', 'nextMonth', 'horizon', 'futureLabels']));
    }

    /**
     * Steps 1-3 together: history -> Chronos -> per-client summary.
     */
    private function forecast(bool $demo, int $horizon): array
    {
        // Step 1: monthly history per client.
        [$months, $history, $names] = $demo ? $this->demoHistory() : $this->buildHistory();

        // Only clients with enough history can be forecast.
        $eligible = array_filter($history, fn (array $counts) => $this->monthsSinceFirstShipment($counts) >= self::MIN_MONTHS);
        $skipped = array_diff_key($history, $eligible);

        // Step 2: ask Chronos.
        $error = null;
        $forecasts = [];
        if ($eligible) {
            try {
                $forecasts = $this->askChronos($eligible, $horizon);
            } catch (\Throwable $e) {
                $error = $e instanceof ConnectionException
                    ? 'The forecasting service is not running. Start it with: forecasting\\.venv\\Scripts\\python forecasting\\chronos_service.py'
                    : 'Forecasting service error: '.$e->getMessage();
            }
        }

        // Step 3: summarize per client.
        $futureMonths = $this->futureMonths(end($months) ?: now()->subMonth()->format('Y-m'), $horizon);
        return [
            'horizon' => $horizon,
            'error' => $error,
            'rows' => $this->summarize($forecasts, $history, $names),
            'skipped' => array_map(fn ($id) => rtrim($names[$id] ?? "Client #$id", '.'), array_keys($skipped)),
            'names' => $names,
            'history' => $history,
            'forecasts' => $forecasts,
            'labels' => array_merge(array_map([$this, 'monthLabel'], $months), array_map([$this, 'monthLabel'], $futureMonths)),
            'nextMonth' => $futureMonths ? $this->monthLabel($futureMonths[0]) : '',
            'futureLabels' => array_map([$this, 'monthLabel'], $futureMonths),
        ];
    }

    /**
     * Step 1: shipments per client per month for the last HISTORY_MONTHS full months.
     * The current month is excluded because it is not finished yet.
     *
     * @return array{0: list<string>, 1: array<int, list<int>>, 2: array<int, string>}
     */
    private function buildHistory(): array
    {
        $end = CarbonImmutable::now()->startOfMonth();
        $start = $end->subMonths(self::HISTORY_MONTHS);
        $months = [];
        for ($m = $start; $m < $end; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        $counts = DB::table('shipments')
            ->selectRaw("client_id, DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS volume")
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('client_id', 'month')
            ->get();

        $history = [];
        foreach ($counts as $row) {
            $history[$row->client_id] ??= array_fill_keys($months, 0);   // months with no shipments = 0
            $history[$row->client_id][$row->month] = (int) $row->volume;
        }
        $history = array_map('array_values', $history);

        $names = DB::table('clients')->whereIn('client_id', array_keys($history))->pluck('client_name', 'client_id')->all();

        return [$months, $history, $names];
    }

    /**
     * Step 2: call the Chronos service. Results are cached for 6 hours per input,
     * so refreshing the page does not re-run the model.
     *
     * @param  array<int, list<int>>  $series
     * @return array<int, array{low: list<float>, median: list<float>, high: list<float>}>
     */
    private function askChronos(array $series, int $horizon): array
    {
        $url = rtrim(config('services.chronos.url', 'http://127.0.0.1:8001'), '/');
        $payload = ['horizon' => $horizon, 'series' => array_map(fn ($c) => $this->trimLeadingZeros($c), $series)];

        return Cache::remember('chronos:'.md5(json_encode($payload)), now()->addHours(6), function () use ($url, $payload) {
            $response = Http::timeout(120)->post("$url/forecast", (object) $payload)->throw();

            $result = [];
            foreach ($response->json('forecasts', []) as $id => $values) {
                $result[(int) $id] = $values;
            }

            return $result;
        });
    }

    /**
     * Step 3: next-month prediction vs. the average of the last 3 months.
     */
    private function summarize(array $forecasts, array $history, array $names): array
    {
        $rows = [];
        foreach ($forecasts as $id => $f) {
            $recent = array_slice($history[$id], -3);
            $recentAvg = array_sum($recent) / max(count($recent), 1);
            $next = $f['median'][0];
            $change = $recentAvg > 0 ? (($next - $recentAvg) / $recentAvg) * 100 : ($next > 0 ? 100 : 0);

            $rows[] = [
                'id' => $id,
                'name' => $names[$id] ?? "Client #$id",
                'recent_avg' => round($recentAvg, 1),
                'next' => round($next, 1),
                'low' => round($f['low'][0], 1),
                'high' => round($f['high'][0], 1),
                'total' => round(array_sum($f['median']), 1),
                'monthly' => array_map(fn ($v) => round($v, 1), $f['median']),   // one value per forecast month
                'change' => round($change),
                'trend' => $change >= self::TREND_THRESHOLD ? 'up' : ($change <= -self::TREND_THRESHOLD ? 'down' : 'steady'),
            ];
        }
        usort($rows, fn ($a, $b) => $b['next'] <=> $a['next']);

        return $rows;
    }

    /** Months from the client's first shipment to the end of the history window. */
    private function monthsSinceFirstShipment(array $counts): int
    {
        return count($this->trimLeadingZeros($counts));
    }

    /** Drop the empty months before a client's first shipment (they were not a client yet). */
    private function trimLeadingZeros(array $counts): array
    {
        while ($counts && reset($counts) == 0) {
            array_shift($counts);
        }

        return array_values($counts);
    }

    private function futureMonths(string $lastMonth, int $horizon): array
    {
        $m = CarbonImmutable::createFromFormat('!Y-m', $lastMonth);

        return array_map(fn ($i) => $m->addMonths($i)->format('Y-m'), range(1, $horizon));
    }

    private function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y');
    }

    /**
     * Demo mode (?demo=1): realistic made-up histories so the feature can be shown
     * before the system has collected enough real shipments. Clearly labelled on screen.
     */
    private function demoHistory(): array
    {
        $end = CarbonImmutable::now()->startOfMonth();
        $months = array_map(fn ($i) => $end->subMonths(self::HISTORY_MONTHS - $i)->format('Y-m'), range(0, self::HISTORY_MONTHS - 1));
        $profiles = [
            1 => ['Demo: Pacific Imports Co.', 18, 0.4, 0.25],   // name, base volume, monthly growth, seasonality
            2 => ['Demo: Luzon Hardware Trading', 12, -0.25, 0.15],
            3 => ['Demo: Visayas Food Distributors', 9, 0.05, 0.45],
            4 => ['Demo: Metro Electronics Inc.', 6, 0.3, 0.1],
        ];

        mt_srand(42);   // same numbers every time
        $history = [];
        $names = [];
        foreach ($profiles as $id => [$name, $base, $growth, $season]) {
            $names[$id] = $name;
            foreach ($months as $i => $month) {
                $monthNo = (int) substr($month, 5, 2);
                $seasonal = 1 + $season * sin(($monthNo - 3) / 12 * 2 * M_PI);   // peaks mid-year, dips in December
                $history[$id][] = max(0, (int) round(($base + $growth * $i) * $seasonal + mt_rand(-2, 2)));
            }
        }

        return [$months, $history, $names];
    }
}
