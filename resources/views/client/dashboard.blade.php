{{--
    FILE PURPOSE
    File   : resources/views/client/dashboard.blade.php
    Feature: Client portal
    Purpose: Client dashboard page.
--}}

<x-app-layout title="Welcome to AstraTrack" active="dashboard" legacy-dashboard>
    @push('styles')
        <link href="{{ asset('assets/css/client_dashbroad.css') }}?v=12" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
        <link href="{{ asset('assets/css/client_motion.css') }}?v=1" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/client_dashbroad.js') }}?v=2" defer></script>
        <script src="{{ asset('assets/js/client_motion.js') }}?v=1" defer></script>
    @endpush

    <div class="client-dashboard-page">
        @if ($clientId === 0)
            <p class="notice" role="status">Your account is not linked to a client company yet. Please contact AstraTrack staff.</p>
        @endif

        <section class="track">
            <div class="track-text">
                <h2>Track your shipment</h2>
                <p>Only shipments on your account can be tracked.</p>
            </div>
            <form class="track-form" id="trackForm" data-track-url="{{ route('client.dashboard.track') }}" method="get" novalidate>
                <label class="track-input">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5" />
                        <path d="m20 20-4.2-4.2" />
                    </svg>
                    <input type="text" id="trackInput" name="q" aria-label="Bill of Lading or shipment reference number"
                        aria-describedby="trackError" placeholder="Bill of Lading or shipment reference no." autocomplete="off"
                        maxlength="50">
                </label>
                <button type="submit" class="btn-track">Track</button>
            </form>
            <p class="track-error" id="trackError" role="alert" hidden></p>
        </section>

        @if ($documentTasks->isNotEmpty())
            <section class="card cd-dash-card" aria-label="Documents needed">
                <div class="card-head">
                    <h2>Documents needed</h2><span class="soft-text small">{{ $documentTasks->count() }}
                        {{ $documentTasks->count() === 1 ? 'item' : 'items' }} across
                        {{ $documentTasks->pluck('shipment_id')->unique()->count() }}
                        {{ $documentTasks->pluck('shipment_id')->unique()->count() === 1 ? 'shipment' : 'shipments' }}</span>
                </div>
                <ul class="cd-dash-list">
                    @foreach ($documentTasks->take(6) as $task)
                        <li class="is-{{ $task['tone'] }}"><a
                                href="{{ route('client.shipments.show', $task['shipment_id']) }}#documents"><span
                                    class="cd-dash-doc"><strong>{{ $task['label'] }}</strong><span>{{ $task['reference'] }}</span></span>
                                @if ($task['tone'] !== 'missing')
                                    <span class="cd-dash-flag">{{ $task['note'] }}</span>
                                @endif
                                <span class="cd-dash-action">Upload</span>
                            </a></li>
                    @endforeach
                </ul>
                <p class="soft-text small">Starting a new shipment? <a href="{{ route('client.shipment-requests') }}">Send us your
                        documents</a>.</p>
            </section>
        @endif

        <section class="stats" aria-label="Shipment totals">
            <article class="card stat">
                <div class="stat-head"><span>Total Shipments</span></div><strong>{{ number_format($totalShipments) }}</strong>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>In Progress</span></div>
                <strong>{{ number_format($inProgressShipments) }}</strong><small>Documents, customs, or transit</small>
            </article>
            <article class="card stat">
                <div class="stat-head"><span>Delivered</span></div><strong>{{ number_format($deliveredShipments) }}</strong>
            </article>
        </section>

        <section class="row">
            <article class="card" id="shipments">
                <div class="card-head">
                    <h2>My Recent Shipments</h2><a href="{{ url('/client/shipments') }}" class="link">View all</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($shipments as $shipment)
                                <tr>
                                    <td data-label="Reference" class="strong"><button type="button" class="ref-button"
                                            data-ref="{{ $shipment->reference_no }}"
                                            aria-label="View details for {{ $shipment->reference_no }}">{{ $shipment->reference_no }}</button>
                                    </td>
                                    <td data-label="Date" class="muted-cell">{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</td>
                                    <td data-label="Status"><span
                                            class="badge badge-{{ $shipment->dashboard_status_class }}">{{ $shipment->dashboard_status_label }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="empty">No shipments yet. Shipments linked to your account will appear here.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-foot"><span class="muted">Showing {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }}
                        of {{ $shipments->total() }}
                        shipments</span>{{ $shipments->onEachSide(2)->links('staff.partials.pager', ['anchor' => 'shipments']) }}</div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Recent Updates</h2>
                </div>
                @if ($updates->isEmpty())
                    <p class="empty">No updates yet. New shipment activity will show up here.</p>
                @else
                    <ol class="updates">
                        @foreach ($updates as $update)
                            <li class="dot-{{ $update->dashboard_status_class }}">
                                <time>{{ $update->updated_at?->format('M d, Y') ?? '—' }}</time><strong>{{ $update->shipment?->reference_no }}
                                    · {{ $update->dashboard_status_label }}</strong>
                                <p>{{ $update->dashboard_note }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </article>
        </section>

        <section class="card" id="billing">
            <div class="card-head">
                <h2>My Billing &amp; Payments</h2><a href="{{ url('/client/billing') }}" class="link">View all</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Billing Reference</th>
                            <th>Related Shipment</th>
                            <th class="num">Amount Due</th>
                            <th class="num">Amount Paid</th>
                            <th>Payment Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bills as $bill)
                            @php($paymentStyle = ['paid' => ['Paid', 'green'], 'partial' => ['Partially Paid', 'amber']][$bill->payment_status] ?? ['Unpaid', 'red'])
                            <tr>
                                <td data-label="Billing Reference" class="strong">{{ $bill->billing_no }}</td>
                                <td data-label="Related Shipment" class="muted-cell">{{ $bill->reference_no }}</td>
                                <td data-label="Amount Due" class="strong num">₱{{ number_format((float) $bill->total_amount, 2) }}</td>
                                <td data-label="Amount Paid" class="muted-cell num">₱{{ number_format((float) $bill->amount_paid, 2) }}
                                </td>
                                <td data-label="Payment Status"><span
                                        class="badge badge-{{ $paymentStyle[1] }}">{{ $paymentStyle[0] }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="empty">No billing records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-foot"><span class="muted">Showing {{ $bills->firstItem() ?? 0 }}–{{ $bills->lastItem() ?? 0 }} of
                    {{ $billingCount }} records</span>{{ $bills->onEachSide(2)->links('staff.partials.pager', ['anchor' => 'billing']) }}
            </div>
        </section>
    </div>

    <dialog class="modal" id="detailModal" aria-labelledby="modalTitle">
        <div class="modal-head">
            <div>
                <h2 id="modalTitle"></h2><span class="badge" id="modalStatus"></span>
            </div><button type="button" class="modal-close" id="modalClose" aria-label="Close">×</button>
        </div>
        <dl class="detail-grid" id="modalDetails"></dl>
        <h3>Progress</h3>
        <ol class="updates" id="modalTimeline"></ol>
    </dialog>
</x-app-layout>
