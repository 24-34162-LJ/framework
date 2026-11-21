{{--
    FILE PURPOSE
    File   : resources/views/admin/forecasting/index.blade.php
    Feature: Forecasting
    Purpose: Customer volume forecasting page.
--}}

{{-- Customer Volume Forecasting page. Data comes from App\Http\Controllers\Admin\ForecastingController. --}}
<x-app-layout title="Volume Forecasting" active="reports">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
        <style>
            .fc-note {
                color: #4f5f78;
                font-size: 13px;
                line-height: 1.5;
                margin: 0;
            }

            .fc-demo {
                border: 1px solid #f3d38b;
                background: #fff8ec;
            }

            .fc-demo .fc-note {
                color: #6b4a12;
            }

            .fc-trend {
                font-weight: 600;
                white-space: nowrap;
            }

            .fc-trend.up {
                color: #2f855a;
            }

            .fc-trend.down {
                color: #c53030;
            }

            .fc-trend.steady {
                color: #4f5f78;
            }

            .report-table td.num,
            .report-table th.num {
                text-align: right;
                font-variant-numeric: tabular-nums;
            }
        </style>
    @endpush
    @if ($chart)
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const c = @json($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    const pad = Array(c.actual.length - 1).fill(null);
                    const last = c.actual[c.actual.length - 1];
                    if (!window.Chart) return;
                    new Chart(document.getElementById('fc-chart'), {
                        type: 'line',
                        data: {
                            labels: c.labels,
                            datasets: [{
                                    label: 'High (90%)',
                                    data: [...pad, last, ...c.forecast.high],
                                    borderColor: 'transparent',
                                    backgroundColor: 'rgba(56,166,160,.18)',
                                    fill: '+1',
                                    pointRadius: 0
                                },
                                {
                                    label: 'Low (10%)',
                                    data: [...pad, last, ...c.forecast.low],
                                    borderColor: 'transparent',
                                    pointRadius: 0,
                                    fill: false
                                },
                                {
                                    label: 'Actual shipments',
                                    data: c.actual,
                                    borderColor: '#395886',
                                    backgroundColor: '#395886',
                                    tension: .3,
                                    pointRadius: 2
                                },
                                {
                                    label: 'Forecast (most likely)',
                                    data: [...pad, last, ...c.forecast.median],
                                    borderColor: '#38a6a0',
                                    backgroundColor: '#38a6a0',
                                    borderDash: [6, 4],
                                    tension: .3,
                                    pointRadius: 3
                                },
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        filter: i => !i.text.startsWith('Low')
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Shipments per month'
                                    }
                                }
                            }
                        }
                    });
                });
            </script>
        @endpush
    @endif

    <div class="page report-page">
        <header class="page-head">
            <div>
                <h1>Customer Volume Forecasting</h1>
            </div>
        </header>
        <nav class="report-tabs" aria-label="Report categories">
            <a href="{{ route('admin.reports') }}">Overview</a>
            @foreach (['shipments' => 'Shipments', 'clients' => 'Clients', 'transactions' => 'Transactions', 'billing' => 'Billing & Payments', 'activity' => 'Activity'] as $key => $label)
                <a href="{{ route('admin.reports', ['report' => $key]) }}">{{ $label }}</a>
            @endforeach
            <a class="active" href="{{ route('admin.forecasting') }}" aria-current="page">Forecasting</a>
        </nav>

        @if ($demo)
            <section class="fcard fc-demo">
                <p class="fc-note">
                    <strong>Demo data.</strong> These customers and volumes are generated examples that show how the forecast works.
                    <a href="{{ route('admin.forecasting') }}">Switch to real shipment data</a>.
                </p>
            </section>
        @endif

        <form class="fcard report-filters" method="get" action="{{ route('admin.forecasting') }}">
            @if ($demo)
                <input type="hidden" name="demo" value="1">
            @endif
            @if ($clients)
                <label>
                    <span>Customer for chart</span>
                    <select name="client_id">
                        @foreach ($clients as $id => $name)
                            <option value="{{ $id }}" @selected($selected === $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>
                <span>Months to predict</span>
                <select name="horizon">
                    @foreach ([1, 3, 6] as $h)
                        <option value="{{ $h }}" @selected($horizon === $h)>{{ $h }} month{{ $h > 1 ? 's' : '' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <button class="btn btn-primary" type="submit">Update</button>
            @unless ($demo)
                <a class="btn" href="{{ route('admin.forecasting', ['demo' => 1]) }}">View demo data</a>
            @endunless
        </form>

        @if ($error)
            <div class="flash flash-error">{{ $error }}</div>
        @elseif (!$rows)
            <section class="fcard">
                <p class="fc-note">Not enough history to forecast yet. A customer needs at least 3 full months of shipments.@if ($skipped)
                        Waiting for more data: {{ rtrim(implode(', ', $skipped), '.') }}.
                    @endif @unless ($demo)
                    <a href="{{ route('admin.forecasting', ['demo' => 1]) }}">View demo data</a> to see how it works.
                @endunless
            </p>
        </section>
    @else
        <section class="fcard report-chart">
            <header>
                <h2>{{ $clients[$selected] ?? '' }}: actual vs. forecast</h2>
            </header>
            <div class="report-canvas-wrap" style="height:340px">
                <canvas id="fc-chart" aria-label="Monthly shipments, actual and forecast"></canvas>
            </div>
            <p class="fc-note">The dashed line is the most likely volume. The shaded band is the range Chronos expects 80% of outcomes
                to fall in.</p>
        </section>

        <section class="fcard">
            <header>
                <h2>Forecast for {{ $nextMonth }}</h2>
            </header>
            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="num">Avg. last 3 months</th>
                            <th class="num">Predicted next month</th>
                            <th class="num">Likely range</th>
                            <th class="num">Next {{ $horizon }} month{{ $horizon > 1 ? 's' : '' }} total</th>
                            <th>Trend</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td><a
                                        href="{{ route('admin.forecasting', array_filter(['client_id' => $r['id'], 'horizon' => $horizon, 'demo' => $demo ? 1 : null])) }}">{{ $r['name'] }}</a>
                                </td>
                                <td class="num">{{ $r['recent_avg'] }}</td>
                                <td class="num"><strong>{{ $r['next'] }}</strong></td>
                                <td class="num">{{ $r['low'] }} – {{ $r['high'] }}</td>
                                <td class="num">{{ $r['total'] }}</td>
                                <td><span
                                        class="fc-trend {{ $r['trend'] }}">{{ ['up' => '▲ Up', 'down' => '▼ Down', 'steady' => '● Steady'][$r['trend']] }}
                                        {{ $r['change'] > 0 ? '+' : '' }}{{ $r['change'] }}%</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($skipped)
                <p class="fc-note">Not forecast yet (under 3 months of history): {{ implode(', ', $skipped) }}.</p>
            @endif
        </section>
    @endif
</div>
</x-app-layout>
