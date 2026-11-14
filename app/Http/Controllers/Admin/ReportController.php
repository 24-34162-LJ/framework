<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const REPORTS = ['overview', 'shipments', 'clients', 'transactions', 'billing', 'activity'];

    public function index(Request $request): View|StreamedResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $today = now()->toDateString();
        $report = (string) $request->query('report', 'overview');
        if (! in_array($report, self::REPORTS, true)) {
            $report = 'overview';
        }
        $from = $this->validDate((string) $request->query('from', now()->startOfMonth()->toDateString())) ?: now()->startOfMonth()->toDateString();
        $to = $this->validDate((string) $request->query('to', $today)) ?: $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $clientId = $report === 'activity' ? 0 : $request->integer('client_id');
        $filter = trim((string) $request->query('filter', ''));
        $search = trim((string) $request->query('q', ''));
        $export = $request->query('export') === 'csv';
        $labels = [
            'overview' => 'Reports Overview', 'shipments' => 'Shipment Report', 'clients' => 'Client Report',
            'transactions' => 'Transaction Report', 'billing' => 'Billing & Payments Report', 'activity' => 'Activity Report',
        ];
        $tabs = ['shipments' => 'Shipments', 'clients' => 'Clients', 'transactions' => 'Transactions', 'billing' => 'Billing & Payments', 'activity' => 'Activity'];
        $clients = DB::table('clients')->orderBy('client_name')->get(['client_id', 'client_name']);
        $statuses = [];
        $rows = collect();
        $stats = [];
        $chartA = [];
        $chartB = [];
        $total = 0;

        if ($report === 'overview') {
            $shipments = DB::table('shipments as s')->whereDate('s.created_at', '>=', $from)->whereDate('s.created_at', '<=', $to)
                ->when($clientId, fn ($query) => $query->where('s.client_id', $clientId));
            $stats['shipments'] = (clone $shipments)->count();
            $stats['clients'] = (clone $shipments)->distinct()->count('s.client_id');
            $stats['ongoing'] = DB::table('transactions as t')->join('shipments as s', 's.shipment_id', '=', 't.shipment_id')->where('t.status', 'ongoing')
                ->whereDate('t.created_at', '>=', $from)->whereDate('t.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('t.client_id', $clientId))->count();
            $billing = DB::table('billings as b')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')->leftJoin('v_billing_balance as v', 'v.billing_id', '=', 'b.billing_id')
                ->whereDate('b.created_at', '>=', $from)->whereDate('b.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('b.client_id', $clientId));
            $totals = (clone $billing)->selectRaw('COALESCE(SUM(b.total_amount),0) AS billed, COALESCE(SUM(v.amount_paid),0) AS paid, COALESCE(SUM(GREATEST(v.balance,0)),0) AS balance, COALESCE(SUM(CASE WHEN b.payment_status="unpaid" THEN 1 ELSE 0 END),0) AS unpaid')->first();
            $stats['billed'] = (float) $totals->billed;
            $stats['paid'] = (float) $totals->paid;
            $stats['balance'] = (float) $totals->balance;
            $stats['unpaid'] = (int) $totals->unpaid;
            $stats['verify'] = DB::table('payments as p')->join('billings as b', 'b.billing_id', '=', 'p.billing_id')->where('p.verification_status', 'pending')
                ->whereDate('b.created_at', '>=', $from)->whereDate('b.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('b.client_id', $clientId))->count();
            $chartA = (clone $shipments)->select('s.current_status as label')->selectRaw('COUNT(*) AS value')->groupBy('s.current_status')->orderByDesc('value')->limit(8)->get()->map(fn ($row) => (array) $row)->all();
            $chartB = (clone $shipments)->selectRaw('DATE(s.created_at) AS day, COUNT(*) AS value')->groupByRaw('DATE(s.created_at)')->orderBy('day')->get()->map(fn ($row) => (array) $row)->all();
        } elseif ($report === 'shipments') {
            $base = DB::table('shipments as s')->join('clients as c', 'c.client_id', '=', 's.client_id')->leftJoin('users as u', 'u.user_id', '=', 's.assigned_staff_id')
                ->whereDate('s.created_at', '>=', $from)->whereDate('s.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('c.client_id', $clientId));
            $statuses = DB::table('shipment_stages')->orderBy('sort_order')->get(['stage_name as value', 'stage_name as label'])->map(fn ($row) => (array) $row)->all();
            $stats = $this->groupCounts(clone $base, 's.current_status');
            $chartA = (clone $base)->selectRaw('DATE(s.created_at) AS day, COUNT(*) AS value')->groupByRaw('DATE(s.created_at)')->orderBy('day')->get()->map(fn ($row) => (array) $row)->all();
            $query = (clone $base)->when($filter !== '', fn ($q) => $q->where('s.current_status', $filter))
                ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('s.reference_no', 'like', '%'.$search.'%')->orWhere('s.bl_awb_no', 'like', '%'.$search.'%')->orWhere('c.client_name', 'like', '%'.$search.'%')));
            $total = (clone $query)->count('s.shipment_id');
            $rows = $export ? (clone $query)->select('s.shipment_id', 's.reference_no', 'c.client_name')->selectRaw('DATE(s.created_at) AS shipment_date')->addSelect('s.transaction_type', 's.mode', 's.current_status', 'u.full_name as staff_name', 's.eta_date')->orderByDesc('s.created_at')->orderByDesc('s.shipment_id')->get()->map(fn ($row) => (array) $row)->all()
                : (clone $query)->select('s.shipment_id', 's.reference_no', 'c.client_name')->selectRaw('DATE(s.created_at) AS shipment_date')->addSelect('s.transaction_type', 's.mode', 's.current_status', 'u.full_name as staff_name', 's.eta_date')->orderByDesc('s.created_at')->orderByDesc('s.shipment_id')->paginate(20)->withQueryString();
        } elseif ($report === 'clients') {
            $base = DB::table('clients as c')->leftJoin('shipments as s', 's.client_id', '=', 'c.client_id')
                ->whereDate('c.created_at', '>=', $from)->whereDate('c.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('c.client_id', $clientId));
            $statuses = [['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']];
            $stats = $this->groupCounts(clone $base, 'c.status', true);
            $chartA = (clone $base)->selectRaw('DATE(c.created_at) AS day, COUNT(DISTINCT c.client_id) AS value')->groupByRaw('DATE(c.created_at)')->orderBy('day')->get()->map(fn ($row) => (array) $row)->all();
            $query = (clone $base)->when($filter !== '', fn ($q) => $q->where('c.status', $filter))
                ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('c.client_name', 'like', '%'.$search.'%')->orWhere('c.email', 'like', '%'.$search.'%')));
            $total = (clone $query)->distinct()->count('c.client_id');
            $selection = (clone $query)->select('c.client_id', 'c.client_name', 'c.contact_person', 'c.email', 'c.phone', 'c.status')->selectRaw('DATE(c.created_at) AS created_date, COUNT(DISTINCT s.shipment_id) AS shipment_count')
                ->groupBy('c.client_id', 'c.client_name', 'c.contact_person', 'c.email', 'c.phone', 'c.status', 'c.created_at')->orderByDesc('c.created_at')->orderByDesc('c.client_id');
            $rows = $export ? $selection->get()->map(fn ($row) => (array) $row)->all() : $selection->paginate(20)->withQueryString();
        } elseif ($report === 'transactions') {
            $base = DB::table('transactions as t')->join('clients as c', 'c.client_id', '=', 't.client_id')->join('shipments as s', 's.shipment_id', '=', 't.shipment_id')->leftJoin('billings as b', 'b.billing_id', '=', 't.billing_id')
                ->whereDate('t.created_at', '>=', $from)->whereDate('t.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('c.client_id', $clientId));
            $statuses = [['value' => 'pending', 'label' => 'Pending'], ['value' => 'ongoing', 'label' => 'Ongoing'], ['value' => 'completed', 'label' => 'Completed']];
            $stats = $this->groupCounts(clone $base, 't.status');
            $chartA = (clone $base)->selectRaw('DATE(t.created_at) AS day, COUNT(*) AS value')->groupByRaw('DATE(t.created_at)')->orderBy('day')->get()->map(fn ($row) => (array) $row)->all();
            $query = (clone $base)->when(in_array($filter, ['pending', 'ongoing', 'completed'], true), fn ($q) => $q->where('t.status', $filter))
                ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('t.transaction_id', 'like', '%'.$search.'%')->orWhere('c.client_name', 'like', '%'.$search.'%')->orWhere('s.reference_no', 'like', '%'.$search.'%')->orWhere('b.billing_no', 'like', '%'.$search.'%')));
            $total = (clone $query)->count('t.transaction_id');
            $selection = (clone $query)->select('t.transaction_id', 't.shipment_id', 't.billing_id', 't.created_at', 'c.client_name', 's.reference_no', 'b.billing_no', 't.status')->selectRaw('DATE(t.created_at) AS created_date, DATE(t.completed_at) AS completed_date')->orderByDesc('t.created_at')->orderByDesc('t.transaction_id');
            $rows = $export ? $selection->get()->map(fn ($row) => (array) $row)->all() : $selection->paginate(20)->withQueryString();
        } elseif ($report === 'billing') {
            $base = DB::table('billings as b')->join('clients as c', 'c.client_id', '=', 'b.client_id')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')->leftJoin('v_billing_balance as v', 'v.billing_id', '=', 'b.billing_id')
                ->whereDate('b.created_at', '>=', $from)->whereDate('b.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('c.client_id', $clientId));
            $statuses = [['value' => 'unpaid', 'label' => 'Unpaid'], ['value' => 'partial', 'label' => 'Partially Paid'], ['value' => 'paid', 'label' => 'Paid']];
            $stats = $this->groupCounts(clone $base, 'b.payment_status');
            $chartB = DB::table('payments as p')->join('billings as b', 'b.billing_id', '=', 'p.billing_id')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')->join('clients as c', 'c.client_id', '=', 'b.client_id')
                ->whereDate('b.created_at', '>=', $from)->whereDate('b.created_at', '<=', $to)->when($clientId, fn ($query) => $query->where('c.client_id', $clientId))
                ->select('p.verification_status as label')->selectRaw('COUNT(*) AS value')->groupBy('p.verification_status')->orderByRaw("FIELD(p.verification_status, 'verified', 'pending', 'rejected')")->get()->map(fn ($row) => (array) $row)->all();
            $query = (clone $base)->when(in_array($filter, ['unpaid', 'partial', 'paid'], true), fn ($q) => $q->where('b.payment_status', $filter))
                ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('b.billing_no', 'like', '%'.$search.'%')->orWhere('c.client_name', 'like', '%'.$search.'%')->orWhere('s.reference_no', 'like', '%'.$search.'%')));
            $total = (clone $query)->count('b.billing_id');
            $selection = (clone $query)->select('b.billing_id', 'b.billing_no', 'c.client_name', 's.reference_no', 'b.due_date', 'b.total_amount', 'b.payment_status')->selectRaw('COALESCE(v.amount_paid,0) AS verified_paid, COALESCE(v.balance,b.total_amount) AS balance')->orderByDesc('b.created_at')->orderByDesc('b.billing_id');
            $rows = $export ? $selection->get()->map(fn ($row) => (array) $row)->all() : $selection->paginate(20)->withQueryString();
        } else {
            $base = DB::table('activity_log as a')->leftJoin('users as u', 'u.user_id', '=', 'a.performed_by')->whereDate('a.created_at', '>=', $from)->whereDate('a.created_at', '<=', $to);
            $statuses = DB::table('activity_log')->distinct()->orderBy('activity_type')->get(['activity_type as value'])->map(fn ($row) => ['value' => $row->value, 'label' => $row->value])->all();
            $stats = $this->groupCounts(clone $base, 'a.activity_type');
            $chartA = (clone $base)->selectRaw('DATE(a.created_at) AS day, COUNT(*) AS value')->groupByRaw('DATE(a.created_at)')->orderBy('day')->get()->map(fn ($row) => (array) $row)->all();
            $query = (clone $base)->when($filter !== '', fn ($q) => $q->where('a.activity_type', $filter))
                ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('a.description', 'like', '%'.$search.'%')->orWhere('a.activity_type', 'like', '%'.$search.'%')->orWhereRaw('COALESCE(a.performed_by_name,u.full_name) LIKE ?', ['%'.$search.'%'])));
            $total = (clone $query)->count('a.activity_id');
            $selection = (clone $query)->select('a.created_at', 'a.activity_type', 'a.description')->selectRaw("COALESCE(a.performed_by_name,u.full_name,'System') AS actor, COALESCE(a.performed_by_role,u.role,'system') AS actor_role")->orderByDesc('a.created_at')->orderByDesc('a.activity_id');
            $rows = $export ? $selection->get()->map(fn ($row) => (array) $row)->all() : $selection->paginate(20)->withQueryString();
        }

        if ($request->query('export') === 'csv') {
            $exportRows = $report === 'overview' ? [
                ['Metric' => 'Shipments created', 'Value' => $stats['shipments']], ['Metric' => 'Clients with shipments', 'Value' => $stats['clients']],
                ['Metric' => 'Ongoing transactions', 'Value' => $stats['ongoing']], ['Metric' => 'Total billed', 'Value' => number_format($stats['billed'], 2, '.', '')],
                ['Metric' => 'Verified payments', 'Value' => number_format($stats['paid'], 2, '.', '')], ['Metric' => 'Outstanding balance', 'Value' => number_format($stats['balance'], 2, '.', '')],
                ['Metric' => 'Unpaid bills', 'Value' => $stats['unpaid']], ['Metric' => 'Payments pending verification', 'Value' => $stats['verify']],
            ] : $rows;

            return $this->csv($report, $exportRows);
        }

        $maxStat = 1;
        foreach (array_merge($report === 'overview' ? [] : $stats, $chartA, $chartB) as $point) {
            $maxStat = max($maxStat, (int) ($point['value'] ?? 0));
        }
        $scopeLabel = $clientId ? 'Selected client' : 'Organization-wide';

        // Chart.js payloads keyed by canvas id (same contract as the prototype's reports.php).
        $series = fn (array $points, string $labelKey): array => [
            'labels' => array_column($points, $labelKey),
            'values' => array_map('intval', array_column($points, 'value')),
        ];
        if ($report === 'overview') {
            $chartPayload = [
                'overview-stage' => ['type' => 'doughnut'] + $series($chartA, 'label'),
                'overview-daily' => ['type' => 'line'] + $series($chartB, 'day'),
            ];
        } else {
            $chartPayload = ['report-status' => ['type' => $report === 'activity' ? 'bar' : 'doughnut'] + $series($stats, 'label')];
            if ($report === 'billing') {
                $chartPayload['report-verification'] = ['type' => 'doughnut'] + $series($chartB, 'label');
            } else {
                $chartPayload['report-daily'] = ['type' => 'line'] + $series($chartA, 'day');
            }
        }

        return view('admin.reports.index', compact('report', 'from', 'to', 'clientId', 'filter', 'search', 'labels', 'tabs', 'clients', 'statuses', 'rows', 'stats', 'chartA', 'chartB', 'total', 'maxStat', 'scopeLabel', 'chartPayload'));
    }

    private function validDate(string $date): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function groupCounts($query, string $column, bool $distinct = false): array
    {
        $count = $distinct ? 'COUNT(DISTINCT c.client_id)' : 'COUNT(*)';

        return $query->selectRaw($column.' AS label, '.$count.' AS value')->groupBy($column)->orderBy('label')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function csv(string $report, array $rows): StreamedResponse
    {
        $headers = $rows ? array_keys((array) $rows[0]) : ['No records'];

        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ',', '"', '');
            foreach ($rows as $row) {
                $values = array_map(function ($value): string {
                    $value = (string) ($value ?? '');
                    if (preg_match('/^[\s]*[=+@\-]/', $value)) {
                        $value = "'".$value;
                    }

                    return $value;
                }, array_values((array) $row));
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);
        }, $report.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
