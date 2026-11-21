{{--
    FILE PURPOSE
    File   : resources/views/admin/reports/index.blade.php
    Feature: Admin - Reports
    Purpose: Admin reports page.
--}}

<x-app-layout :title="$labels[$report]" active="reports">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const reportCharts = @json($chartPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                const palette = ['#395886', '#38a6a0', '#f0a33a', '#8b78c6', '#e16b6b', '#6b9b54', '#576782', '#c477a8'];
                if (!window.Chart) return;
                Object.entries(reportCharts).forEach(([canvasId, chart]) => {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas || !chart.labels.length) return;
                    const horizontal = canvasId === 'report-status' && @json($report === 'activity');
                    const isLine = chart.type === 'line';
                    new Chart(canvas, {
                        type: chart.type,
                        data: {
                            labels: chart.labels,
                            datasets: [{
                                label: canvasId.includes('daily') ? 'Records' : 'Count',
                                data: chart.values,
                                backgroundColor: isLine ? 'rgba(70,107,162, 0.18)' : palette,
                                borderColor: isLine ? '#395886' : '#fff',
                                borderWidth: isLine ? 2 : 1,
                                borderRadius: horizontal ? 5 : 0,
                                fill: isLine,
                                tension: 0.3,
                                pointRadius: isLine ? 3 : 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: horizontal ? 'y' : 'x',
                            plugins: {
                                legend: {
                                    display: chart.type === 'doughnut',
                                    position: 'bottom'
                                }
                            },
                            scales: chart.type === 'doughnut' ? {} : {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0,
                                        maxRotation: 45,
                                        minRotation: 0
                                    },
                                    grid: {
                                        display: !horizontal
                                    }
                                },
                                y: {
                                    beginAtZero: false,
                                    ticks: {
                                        precision: 0
                                    },
                                    grid: {
                                        display: horizontal
                                    }
                                }
                            }
                        }
                    });
                });
            });
        </script>
    @endpush
    <div class="page report-page">
        <header class="page-head">
            <div>
                <h1>{{ $labels[$report] }}</h1>
            </div><a class="btn btn-primary" href="{{ route('admin.reports', array_merge(request()->query(), ['export' => 'csv'])) }}">Export
                CSV</a>
        </header>
        <nav class="report-tabs" aria-label="Report categories">
            <a class="{{ $report === 'overview' ? 'active' : '' }}"
                href="{{ route('admin.reports', ['from' => $from, 'to' => $to]) }}">Overview</a>
            @foreach ($tabs as $key => $label)
                <a class="{{ $report === $key ? 'active' : '' }}"
                    href="{{ route('admin.reports', ['report' => $key, 'from' => $from, 'to' => $to]) }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('admin.forecasting') }}">Forecasting</a>
        </nav>
        <form class="fcard report-filters" method="get" action="{{ route('admin.reports') }}">
            <input type="hidden" name="report" value="{{ $report }}">
            <label><span>Date from</span>
                <input type="date" name="from" value="{{ $from }}"></label>
            <label><span>Date to</span>
                <input type="date" name="to" value="{{ $to }}"></label>
            @if ($report !== 'activity')
                <label><span>Client</span>
                    <select name="client_id">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->client_id }}" @selected($clientId === $client->client_id)>{{ $client->client_name }}</option>
                        @endforeach
                    </select></label>@endif @if ($report !== 'overview')
                    <label><span>{{ $report === 'clients' ? 'Client status' : ($report === 'billing' ? 'Bill status' : ($report === 'activity' ? 'Activity type' : 'Status')) }}</span>
                        <select name="filter">
                            <option value="">All {{ $report === 'activity' ? 'types' : 'statuses' }}</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status['value'] }}" @selected($filter === $status['value'])>{{ $status['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="report-query">
                        <span>Search</span>
                        <input name="q" value="{{ $search }}" placeholder="Reference, client or record"></label>
                @endif
                <button class="btn btn-primary" type="submit">Apply Filters</button>
                <a class="btn btn-secondary" href="{{ route('admin.reports', $report === 'overview' ? [] : ['report' => $report]) }}">Clear
                    Filters</a>
                <small>Applied: {{ \Illuminate\Support\Carbon::parse($from)->format('M d, Y') }} –
                    {{ \Illuminate\Support\Carbon::parse($to)->format('M d, Y') }} · {{ $scopeLabel }}</small>
        </form>

        @if ($report === 'overview')
            <section class="report-kpis report-kpis-overview">
                <article class="fcard"><span>Shipments created</span><strong>{{ number_format($stats['shipments']) }}</strong></article>
                <article class="fcard"><span>Clients with shipments</span><strong>{{ number_format($stats['clients']) }}</strong>
                </article>
                <article class="fcard"><span>Ongoing transactions</span><strong>{{ number_format($stats['ongoing']) }}</strong></article>
                <article class="fcard"><span>Total billed</span><strong>₱{{ number_format($stats['billed'], 2) }}</strong></article>
                <article class="fcard"><span>Verified
                        payments</span><strong>₱{{ number_format($stats['paid'], 2) }}</strong><small>Verified entries only</small>
                </article>
                <article class="fcard"><span>Outstanding
                        balance</span><strong>₱{{ number_format($stats['balance'], 2) }}</strong><small>Total billed less verified
                        payments</small></article>
            </section>
            <div class="report-charts">
                <section class="fcard report-chart">
                    <header>
                        <h2>Shipments by stage</h2><a
                            href="{{ route('admin.reports', ['report' => 'shipments', 'from' => $from, 'to' => $to]) }}">View records</a>
                    </header>
                    @if (!$chartA)
                    <p class="report-empty">No shipment records in this period.</p>@else<div class="report-canvas-wrap"><canvas
                                id="overview-stage" aria-label="Shipments by stage"></canvas></div>
                    @endif
                </section>
                <section class="fcard report-chart">
                    <header>
                        <h2>Shipment activity by date</h2><a
                            href="{{ route('admin.reports', ['report' => 'shipments', 'from' => $from, 'to' => $to]) }}">View records</a>
                    </header>
                    @if (!$chartB)
                    <p class="report-empty">No shipment records in this period.</p>@else<div class="report-canvas-wrap"><canvas
                                id="overview-daily" aria-label="Shipment activity by date"></canvas></div>
                    @endif
                </section>
            </div>
            <section class="fcard report-attention">
                <h2>Operational attention</h2>
                <div><span>Unpaid bills</span><strong>{{ number_format($stats['unpaid']) }}</strong><a
                        href="{{ route('admin.reports', ['report' => 'billing', 'filter' => 'unpaid', 'from' => $from, 'to' => $to]) }}">View
                        bills</a></div>
                <div><span>Payments pending verification</span><strong>{{ number_format($stats['verify']) }}</strong><a
                        href="{{ route('billing.index', ['view' => 'review']) }}">View payments</a></div>
            </section>
        @else
            <section class="report-kpis report-kpis-small">
                @foreach ($stats as $point)
                    <article class="fcard">
                        <span>{{ $point['label'] }}</span>
                        <strong>{{ number_format((int) $point['value']) }}</strong>
                        <small>{{ $report === 'shipments' ? 'Shipments' : ($report === 'transactions' ? 'Transactions' : ($report === 'billing' ? 'Bills' : 'Records')) }}</small>
                    </article>
                    @endforeach @if ($report === 'billing')
                        <article class="fcard">
                            <span>Payments pending verification</span>
                            <strong>{{ number_format(array_sum(array_map(fn($value) => (int) $value['value'], $chartB))) }}</strong>
                        </article>
                    @endif
            </section>
            <div class="report-charts report-charts-compact">
                <section class="fcard report-chart">
                    <header>
                        <h2>{{ $report === 'billing' ? 'Bill statuses' : ($report === 'clients' ? 'Client status' : 'Records by type') }}
                        </h2>
                    </header>
                    @if (!$stats)
                        <p class="report-empty">No matching records.</p>
                    @else
                        <div class="report-canvas-wrap{{ $report === 'activity' ? ' report-canvas-horizontal' : '' }}"
                            style="--report-chart-rows: {{ count($stats) }}">
                            <canvas id="report-status"
                                aria-label="{{ $report === 'activity' ? 'Activity records by type' : ($report === 'clients' ? 'Clients by status' : ($report === 'shipments' ? 'Shipments by status' : 'Transactions by status')) }}"></canvas>
                        </div>
                    @endif
                </section>
                @if ($report === 'billing')
                    <section class="fcard report-chart">
                        <header>
                            <h2>Payment verification</h2>
                        </header>
                        @if (!$chartB)
                            <p class="report-empty">No payments in this period.</p>
                        @else
                            <div class="report-canvas-wrap">
                                <canvas id="report-verification" aria-label="Payment verification status"></canvas>
                            </div>
                        @endif
                    </section>
                @else
                    <section class="fcard report-chart">
                        <header>
                            <h2>Daily record volume</h2>
                        </header>
                        @if (!$chartA)
                            <p class="report-empty">No records in this period.</p>
                        @else
                            <div class="report-canvas-wrap">
                                <canvas id="report-daily" aria-label="Daily report volume"></canvas>
                            </div>
                        @endif
                    </section>
                @endif
            </div>
            <section class="fcard report-records">
                <header>
                    <div>
                        <h2>Filtered records</h2>
                        <p>{{ number_format($total) }} records · select a reference to open it</p>
                    </div>
                </header>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                @if ($report === 'shipments')
                                    <th>Shipment Reference</th>
                                    <th>Client</th>
                                    <th>Shipment Date</th>
                                    <th>Type</th>
                                    <th>Mode</th>
                                    <th>Current Status</th>
                                    <th>Assigned Staff</th>
                                    <th>ETA</th>
                                @elseif ($report === 'clients')
                                    <th>Client</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th class="num">Shipments in scope</th>
                                @elseif ($report === 'transactions')
                                    <th>Transaction ID</th>
                                    <th>Client</th>
                                    <th>Shipment</th>
                                    <th>Linked Bill</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Completed</th>
                                @elseif ($report === 'billing')
                                    <th>Bill No.</th>
                                    <th>Client</th>
                                    <th>Shipment</th>
                                    <th>Due Date</th>
                                    <th class="num">Total</th>
                                    <th class="num">Verified Paid</th>
                                    <th class="num">Balance</th>
                                    <th>Status</th>
                                @else
                                    <th>Date / Time</th>
                                    <th>Activity Type</th>
                                    <th>Description</th>
                                    <th>User</th>
                                    <th>Role</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    @if ($report === 'shipments')
                                        <td><a href="{{ route('shipments.show', $row->shipment_id) }}">{{ $row->reference_no }}</a></td>
                                        <td>{{ $row->client_name }}</td>
                                        <td>{{ \Illuminate\Support\Carbon::parse($row->shipment_date)->format('M d, Y') }}</td>
                                        <td>{{ ucfirst($row->transaction_type) }}</td>
                                        <td>{{ ucfirst($row->mode) }}</td>
                                        <td>{{ $row->current_status }}</td>
                                        <td>{{ $row->staff_name ?: 'Unassigned' }}</td>
                                        <td>{{ $row->eta_date ? \Illuminate\Support\Carbon::parse($row->eta_date)->format('M d, Y') : '—' }}
                                        </td>
                                    @elseif ($report === 'clients')
                                        <td>{{ $row->client_name }}</td>
                                        <td>{{ $row->contact_person ?: '—' }}</td>
                                        <td>{{ $row->email ?: '—' }}</td>
                                        <td>{{ ucfirst($row->status) }}</td>
                                        <td>{{ \Illuminate\Support\Carbon::parse($row->created_date)->format('M d, Y') }}</td>
                                        <td class="num">{{ (int) $row->shipment_count }}</td>
                                    @elseif ($report === 'transactions')
                                        <td><a
                                                href="{{ route('transactions.index', ['view' => 'detail', 'id' => $row->transaction_id]) }}">TXN-{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y') }}-{{ str_pad((string) $row->transaction_id, 4, '0', STR_PAD_LEFT) }}</a>
                                        </td>
                                        <td>{{ $row->client_name }}</td>
                                        <td><a href="{{ route('shipments.show', $row->shipment_id) }}">{{ $row->reference_no }}</a></td>
                                        <td>{{ $row->billing_no ?: '—' }}</td>
                                        <td>{{ ucfirst($row->status) }}</td>
                                        <td>{{ \Illuminate\Support\Carbon::parse($row->created_date)->format('M d, Y') }}</td>
                                        <td>{{ $row->completed_date ? \Illuminate\Support\Carbon::parse($row->completed_date)->format('M d, Y') : '—' }}
                                        </td>
                                    @elseif ($report === 'billing')
                                        <td><a
                                                href="{{ route('billing.index', ['view' => 'detail', 'id' => $row->billing_id]) }}">{{ $row->billing_no }}</a>
                                        </td>
                                        <td>{{ $row->client_name }}</td>
                                        <td>{{ $row->reference_no }}</td>
                                        <td>{{ $row->due_date ? \Illuminate\Support\Carbon::parse($row->due_date)->format('M d, Y') : '—' }}
                                        </td>
                                        <td class="num">₱{{ number_format((float) $row->total_amount, 2) }}</td>
                                        <td class="num">₱{{ number_format((float) $row->verified_paid, 2) }}</td>
                                        <td class="num">₱{{ number_format((float) $row->balance, 2) }}</td>
                                        <td>{{ ucfirst($row->payment_status) }}</td>
                                    @else<td>{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('M d, Y · g:i A') }}</td>
                                        <td>{{ $row->activity_type }}</td>
                                        <td>{{ $row->description }}</td>
                                        <td>{{ $row->actor }}</td>
                                        <td>{{ ucfirst($row->actor_role) }}</td>
                                    @endif
                                </tr>
                            @empty<tr>
                                    <td colspan="9" class="report-empty">No records match these filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="report-pager"><span>Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of
                        {{ number_format($total) }}</span>
                    <nav aria-label="Report pages">
                        @if ($rows->currentPage() > 1)
                            <a href="{{ $rows->previousPageUrl() }}">Previous</a>
                        @endif
                        <span>
                            Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
                        @if ($rows->hasMorePages())
                            <a href="{{ $rows->nextPageUrl() }}">Next</a>
                        @endif
                    </nav>
                </footer>
            </section>
        @endif
    </div>
</x-app-layout>
