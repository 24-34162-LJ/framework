<x-app-layout title="Admin Dashboard" active="dashboard" :search-action="'/operations/search.php'" search-placeholder="Search records or users" legacy-dashboard>
    @push('styles')
        <link href="{{ asset('assets/css/admin.css') }}?v=9" rel="stylesheet">
        <style>
            .fc-card { margin-bottom: 20px; }
            .fc-card .card-head { flex-wrap: wrap; gap: 12px; align-items: center; }
            .fc-card .card-head .muted { display: block; margin-top: 3px; }
            .fc-controls { display: flex; gap: 16px; flex-wrap: wrap; }
            .fc-switch { display: flex; gap: 8px; flex-wrap: wrap; }
            .fc-msg { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
            .fc-demo-note { margin-bottom: 12px; padding: 8px 12px; border: 1px solid #f3d38b; background: #fff8ec; border-radius: 8px; color: #6b4a12; }
            .fc-error { color: #c53030; }
            .fc-table-wrap { overflow-x: auto; }
            .fc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
            .fc-table th { background: #eef2f9; color: #4f5f78; font-weight: 600; font-size: 13px; text-align: left; padding: 11px 16px; white-space: nowrap; }
            .fc-table th:first-child { border-radius: 8px 0 0 8px; } .fc-table th:last-child { border-radius: 0 8px 8px 0; }
            .fc-table td { padding: 13px 16px; border-bottom: 1px solid #e6ebf3; color: #1d2d4a; }
            .fc-table tr:last-child td { border-bottom: 0; }
            .fc-table .num { text-align: right; font-variant-numeric: tabular-nums; }
            .fc-trend { font-weight: 600; white-space: nowrap; }
            .fc-trend.up { color: #2f855a; } .fc-trend.down { color: #c53030; } .fc-trend.steady { color: #4f5f78; }
        </style>
    @endpush
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const shipmentTrend = @json($shipmentTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                const trendCanvas = document.getElementById('shipmentTrendChart');
                if (!trendCanvas || !window.Chart) return;
                new Chart(trendCanvas, {
                    type: 'line',
                    data: {
                        labels: shipmentTrend.labels,
                        datasets: [{
                            label: 'Shipments',
                            data: shipmentTrend.values,
                            borderColor: '#395886',
                            backgroundColor: 'rgba(73,112,171, 0.14)',
                            borderWidth: 3,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#395886',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Shipments' } }
                        }
                    }
                });
            });
        </script>
        <script src="{{ asset('assets/js/admin.js') }}?v=2" defer></script>
    @endpush

    <div class="admin-dashboard">
        {{-- Customer volume forecast (Amazon Chronos) - see ForecastingController --}}
        <section class="card fc-card" aria-label="Customer volume forecast">
            @php
                $fcMonths = $forecast['horizon'];
                $fcUrl = fn (array $change) => url()->current().'?'.http_build_query(array_filter(array_merge(['forecast' => $forecast['demo'] ? 'demo' : null], $change)));
                $fcLabels = $forecast['futureLabels'];
            @endphp
            <div class="card-head">
                <div><h2>Forecast for {{ $fcLabels[0] ?? '' }}{{ count($fcLabels) > 1 ? ' – '.end($fcLabels) : '' }}</h2><span class="muted">Predicted shipments per customer · Amazon Chronos</span></div>
                <div class="fc-controls">
                    <div class="fc-switch" role="group" aria-label="Forecast data source">
                        <a class="btn {{ $forecast['demo'] ? '' : 'btn-primary' }}" href="{{ $fcUrl(['forecast' => null]) }}" @unless ($forecast['demo']) aria-current="true" @endunless>Real data</a>
                        <a class="btn {{ $forecast['demo'] ? 'btn-primary' : '' }}" href="{{ $fcUrl(['forecast' => 'demo']) }}" @if ($forecast['demo']) aria-current="true" @endif>Demo data</a>
                        <a class="btn" href="{{ route('admin.forecasting', array_filter(['demo' => $forecast['demo'] ? 1 : null])) }}">View chart</a>
                    </div>
                </div>
            </div>
            @if ($forecast['demo'])<p class="fc-msg fc-demo-note">Demo data: generated example customers, shown because real history is still being collected.</p>@endif
            @if ($forecast['error'])
                <p class="fc-msg fc-error">{{ $forecast['error'] }}</p>
            @elseif (! $forecast['rows'])
                <p class="fc-msg">Not enough history yet. A customer needs at least 3 full months of shipments.@if ($forecast['skipped']) Collecting data for: {{ implode(', ', $forecast['skipped']) }}.@endif <a href="{{ url()->current() }}?forecast=demo">See demo data</a>.</p>
            @else
                <div class="fc-table-wrap"><table class="fc-table">
                    <thead><tr><th>Customer</th><th class="num">Avg. last 3 months</th>@foreach ($fcLabels as $label)<th class="num">{{ $label }}</th>@endforeach<th class="num">{{ $fcLabels[0] ?? '' }} range</th>@if ($fcMonths > 1)<th class="num">{{ $fcMonths }}-month total</th>@endif<th>Trend</th></tr></thead>
                    <tbody>@foreach ($forecast['rows'] as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td class="num">{{ $r['recent_avg'] }}</td>
                            @foreach ($r['monthly'] as $i => $value)<td class="num">@if ($i === 0)<strong>{{ $value }}</strong>@else{{ $value }}@endif</td>@endforeach
                            <td class="num">{{ $r['low'] }} – {{ $r['high'] }}</td>
                            @if ($fcMonths > 1)<td class="num"><strong>{{ $r['total'] }}</strong></td>@endif
                            <td><span class="fc-trend {{ $r['trend'] }}">{{ ['up' => '▲ Up', 'down' => '▼ Down', 'steady' => '● Steady'][$r['trend']] }} {{ $r['change'] > 0 ? '+' : '' }}{{ $r['change'] }}%</span></td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </section>
        <section class="stats" aria-label="System totals">
            <article class="card stat">
                <div class="stat-head"><span>Total Users</span><span aria-hidden="true">♙</span></div>
                <strong data-count="{{ $totalUsers }}">{{ number_format($totalUsers) }}</strong>
                
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Total Shipments</span><span aria-hidden="true">▣</span></div>
                <strong data-count="{{ $totalShipments }}">{{ number_format($totalShipments) }}</strong>
                
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Ongoing Transactions</span><span aria-hidden="true">⇄</span></div>
                <strong data-count="{{ $ongoingTransactions }}">{{ number_format($ongoingTransactions) }}</strong>
                
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Outstanding Balances</span><span aria-hidden="true">ⓘ</span></div>
                <strong>PHP {{ number_format($outstandingAmount, 2) }}</strong>
                <small>Billed minus payments received</small>
            </article>
        </section>

        <section class="overview" aria-label="Operational overview">
            <article class="card">
                <div class="card-head"><h2>Shipment Overview</h2><span class="muted">{{ number_format($shipmentTotal) }} shipments by status</span></div>
                @forelse ($shipmentStatus as $status)
                    <div class="bar-row">
                        <span class="bar-label">{{ $status['label'] }}</span>
                        <div class="bar-track" role="progressbar" aria-label="{{ $status['label'] }}" aria-valuenow="{{ $status['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="bar-fill {{ $status['class'] }}" data-width="{{ $status['percentage'] }}"></div>
                        </div>
                        <span class="bar-count">{{ number_format($status['count']) }}</span>
                    </div>
                @empty
                    <p class="muted">No shipment stage groups are configured.</p>
                @endforelse
            </article>

            <article class="card">
                <div class="card-head"><h2>Transaction Overview</h2><span class="muted">{{ number_format($transactionTotal) }} total</span></div>
                <div class="stack" role="img" aria-label="Transaction status distribution">
                    @foreach ($transactionStatus as $status)
                        <div class="{{ $status['class'] }}" style="width: {{ $status['percentage'] }}%"></div>
                    @endforeach
                </div>
                <ul class="legend">
                    @foreach ($transactionStatus as $status)
                        <li><i class="swatch {{ $status['class'] }}" aria-hidden="true"></i><span class="legend-name">{{ $status['label'] }}</span><b>{{ number_format($status['count']) }}</b><small>{{ $status['percentage'] }}%</small></li>
                    @endforeach
                </ul>
            </article>

            <article class="card">
                <div class="card-head"><h2>Quick Actions</h2></div>
                
                <div class="actions">
                    <a href="{{ url('/admin/users') }}" class="btn btn-primary">Manage Users</a>
                    <a href="{{ url('/admin/reports') }}" class="btn">View Reports</a>
                    <a href="{{ url('/admin/shipments') }}" class="btn">View Shipments</a>
                </div>
            </article>
        </section>

        <section class="card shipment-trend">
            <div class="card-head">
                <div><h2>Shipments Created</h2><p class="muted">Monthly totals for the last 12 months</p></div>
            </div>
            <div class="shipment-trend-chart"><canvas id="shipmentTrendChart" aria-label="Shipments created per month over the last 12 months"></canvas></div>
        </section>

        <section class="card dashboard-attention">
            <div class="card-head"><h2>Needs Attention</h2><a class="link" href="{{ url('/admin/shipments?arrival=overdue') }}">View shipments</a></div>
            <div class="attention-list">
                @forelse ($needsAttention as $item)
                    <a class="attention-row" href="{{ url('/admin/shipments/'.$item['shipment_id']) }}">
                        <span><strong>{{ $item['reference_no'] }}</strong><small>{{ $item['current_status'] }}</small></span>
                        <span class="attention-date {{ $item['is_overdue'] ? 'is-overdue' : '' }}">{{ $item['is_overdue'] ? 'Delivery overdue' : 'ETA '.($item['eta_label'] ?? '—') }}</span>
                    </a>
                @empty
                    <p class="muted">No upcoming arrivals or overdue deliveries.</p>
                @endforelse
            </div>
        </section>

        <section class="card activity">
            <div class="card-head"><h2>Recent Activity</h2><a href="{{ url('/admin/activity') }}" class="link">View all</a></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date &amp; Time</th><th>Activity Type</th><th>Description</th><th>Performed By</th></tr></thead>
                    <tbody>
                        @forelse ($recentActivity as $activity)
                            <tr>
                                <td data-label="Date &amp; Time">{{ $activity['happened'] }}</td>
                                <td data-label="Activity Type"><span class="badge {{ $activity['badge_class'] }}">{{ $activity['type'] }}</span></td>
                                <td data-label="Description">{{ $activity['description'] }}</td>
                                <td class="muted-cell" data-label="Performed By">{{ $activity['performed_by'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted-cell">No activity recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
