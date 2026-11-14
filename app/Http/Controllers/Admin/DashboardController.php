<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $totalUsers = User::query()->count();
        $totalShipments = Shipment::query()->whereNull('archived_at')->count();
        $ongoingTransactions = Transaction::query()->where('status', 'ongoing')->count();
        $outstandingAmount = (float) DB::table('v_billing_balance')
            ->where('balance', '>', 0)
            ->sum('balance');

        $statusCounts = Shipment::query()
            ->whereNull('archived_at')
            ->select('current_status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('current_status')
            ->get()
            ->mapWithKeys(fn ($row): array => [mb_strtolower(trim($row->current_status)) => (int) $row->total]);

        $groupColors = [
            'Pending Documents' => 'amber',
            'Customs Processing' => 'blue',
            'In Transit' => 'teal',
            'Delivered' => 'green',
        ];

        $shipmentStatus = ShipmentStage::query()
            ->whereNotIn('stage_name', ['On Hold', 'Cancelled'])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('stage_group')
            ->map(function ($stages, string $label) use ($groupColors, $statusCounts): array {
                $count = $stages->sum(fn (ShipmentStage $stage): int => (int) ($statusCounts[mb_strtolower(trim($stage->stage_name))] ?? 0));

                return [
                    'label' => $label,
                    'class' => $groupColors[$label] ?? 'blue',
                    'count' => $count,
                ];
            })
            ->values();

        $shipmentTotal = (int) $shipmentStatus->sum('count');
        $shipmentStatus = $shipmentStatus->map(function (array $status) use ($shipmentTotal): array {
            $status['percentage'] = $shipmentTotal > 0
                ? round($status['count'] / $shipmentTotal * 100, 1)
                : 0;

            return $status;
        });

        $transactionCounts = Transaction::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->status => (int) $row->total]);

        $transactionStatus = collect([
            ['key' => 'pending', 'label' => 'Pending', 'class' => 'amber'],
            ['key' => 'ongoing', 'label' => 'Ongoing', 'class' => 'blue'],
            ['key' => 'completed', 'label' => 'Completed', 'class' => 'green'],
        ])->map(function (array $status) use ($transactionCounts): array {
            $status['count'] = (int) ($transactionCounts[$status['key']] ?? 0);

            return $status;
        });

        $transactionTotal = (int) $transactionStatus->sum('count');
        $transactionStatus = $transactionStatus->map(function (array $status) use ($transactionTotal): array {
            $status['percentage'] = $transactionTotal > 0
                ? round($status['count'] / $transactionTotal * 100, 1)
                : 0;

            return $status;
        });

        $today = Carbon::today();
        $weekEnd = $today->copy()->addDays(7);

        $needsAttention = Shipment::query()
            ->whereNull('archived_at')
            ->whereRaw("LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')")
            ->where(function ($query) use ($today, $weekEnd): void {
                $query->whereBetween('eta_date', [$today->toDateString(), $weekEnd->toDateString()])
                    ->orWhere('delivery_due_date', '<', $today->toDateString());
            })
            ->orderByRaw('CASE WHEN delivery_due_date < ? THEN 1 ELSE 0 END DESC', [$today->toDateString()])
            ->orderByRaw('COALESCE(delivery_due_date, eta_date) ASC')
            ->limit(8)
            ->get(['shipment_id', 'reference_no', 'current_status', 'eta_date', 'delivery_due_date'])
            ->map(fn (Shipment $shipment): array => [
                'shipment_id' => $shipment->shipment_id,
                'reference_no' => $shipment->reference_no,
                'current_status' => $shipment->current_status,
                'eta_label' => $shipment->eta_date?->format('M d, Y'),
                'is_overdue' => $shipment->delivery_due_date?->lt($today) ?? false,
            ]);

        $recentActivity = ActivityLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('activity_id')
            ->limit(5)
            ->get(['created_at', 'activity_type', 'description', 'performed_by_name', 'performed_by_role'])
            ->map(function (ActivityLog $activity): array {
                if ($activity->performed_by_name === null) {
                    $performedBy = 'System';
                } elseif ($activity->performed_by_role === 'admin') {
                    $performedBy = $activity->performed_by_name;
                } else {
                    $performedBy = $activity->performed_by_name.' ('.ucfirst((string) $activity->performed_by_role).')';
                }

                return [
                    'happened' => $activity->created_at?->format('M d, h:i A') ?? '—',
                    'type' => $activity->activity_type,
                    'description' => (string) $activity->description,
                    'performed_by' => $performedBy,
                    'badge_class' => $this->activityBadgeClass($activity->activity_type),
                ];
            });

        // Monthly shipment trend for the current month and previous 11 months.
        $trendStart = Carbon::now()->startOfMonth()->subMonths(11);
        $trendEnd = Carbon::now()->startOfMonth()->addMonth();
        $trendCounts = Shipment::query()->whereNull('archived_at')
            ->where('created_at', '>=', $trendStart)->where('created_at', '<', $trendEnd)
            ->pluck('created_at')
            ->countBy(fn ($createdAt): string => Carbon::parse($createdAt)->format('Y-m'));
        $shipmentTrend = ['labels' => [], 'values' => []];
        for ($month = $trendStart->copy(); $month->lt($trendEnd); $month->addMonth()) {
            $shipmentTrend['labels'][] = $month->format('M Y');
            $shipmentTrend['values'][] = (int) ($trendCounts[$month->format('Y-m')] ?? 0);
        }

        return view('admin.dashboard', [
            'shipmentTrend' => $shipmentTrend,
            'totalUsers' => $totalUsers,
            'totalShipments' => $totalShipments,
            'ongoingTransactions' => $ongoingTransactions,
            'outstandingAmount' => $outstandingAmount,
            'shipmentStatus' => $shipmentStatus,
            'shipmentTotal' => $shipmentTotal,
            'transactionStatus' => $transactionStatus,
            'transactionTotal' => $transactionTotal,
            'needsAttention' => $needsAttention,
            'recentActivity' => $recentActivity,
            // Customer volume forecast card (Amazon Chronos): next month only. ?forecast=demo shows demo data.
            'forecast' => app(ForecastingController::class)->summary(request()->query('forecast') === 'demo', 1),
        ]);
    }

    private function activityBadgeClass(string $type): string
    {
        $type = mb_strtolower($type);

        if (str_contains($type, 'bill') || str_contains($type, 'pay')) {
            return 'badge-amber';
        }

        if (str_contains($type, 'user') || str_contains($type, 'account')) {
            return 'badge-teal';
        }

        return 'badge-blue';
    }
}
