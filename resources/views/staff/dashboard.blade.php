<x-app-layout title="Dashboard" active="dashboard" :search-action="url('/staff/dashboard#shipments')" search-placeholder="Search shipments or clients" legacy-dashboard>
    @push('styles')<link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">@endpush
    @push('scripts')
        <script>window.STAFF_TRACK_URL = @json(route('staff.track'));</script>
        <script src="{{ asset('assets/js/staff.js') }}?v=2" defer></script>
    @endpush

    <div class="staff-dashboard">
        <section class="card dashboard-attention">
            <div class="card-head"><h2>Needs Attention</h2><a class="link" href="{{ url('/staff_shipment.php?arrival=overdue') }}">View shipments</a></div>
            <div class="attention-list">
                @forelse ($needsAttention as $item)
                    <a class="attention-row" href="{{ route('shipments.show', $item['shipment_id']) }}"><span><strong>{{ $item['reference_no'] }}</strong><small>{{ $item['current_status'] }}</small></span><span class="attention-date {{ $item['is_overdue'] ? 'is-overdue' : '' }}">{{ $item['is_overdue'] ? 'Delivery overdue' : 'ETA '.($item['eta_label'] ?? '—') }}</span></a>
                @empty
                    <p class="muted">No upcoming arrivals or overdue deliveries.</p>
                @endforelse
            </div>
        </section>

        <section class="stats" aria-label="Shipment totals">
            <article class="card stat"><div class="stat-head"><span>Total Shipments</span></div><strong>{{ number_format($totalShipments) }}</strong></article>
            <article class="card stat"><div class="stat-head"><span>Ongoing Shipments</span></div><strong>{{ number_format($ongoingShipments) }}</strong><small>Customs, trucking, or delivery</small></article>
            <article class="card stat"><div class="stat-head"><span>Completed Shipments</span></div><strong>{{ number_format($completedShipments) }}</strong></article>
            <article class="card stat"><div class="stat-head"><span>Outstanding Balances</span></div><strong>PHP {{ number_format($outstandingAmount, floor($outstandingAmount) == $outstandingAmount ? 0 : 2) }}</strong><small>Unpaid billing records</small></article>
        </section>

        <div class="dash">
            <div class="col">
                <article class="card" id="shipments">
                    <div class="card-head"><h2>Recent Shipments</h2><a href="{{ url('/staff_shipment.php') }}" class="link">View all</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Client</th><th>Status</th><th>Updated</th></tr></thead><tbody>
                        @forelse ($shipments as $shipment)
                            <tr><td data-label="Reference" class="strong"><button type="button" class="ref-button" data-ref="{{ $shipment->reference_no }}" aria-label="View details for {{ $shipment->reference_no }}">{{ $shipment->reference_no }}</button></td><td data-label="Client" class="muted-cell">{{ $shipment->client?->client_name ?? '—' }}</td><td data-label="Status"><span class="badge badge-{{ $shipment->dashboard_status_class }}">{{ $shipment->dashboard_status_label }}</span></td><td data-label="Updated" class="muted-cell">{{ $shipment->status_updates_max_updated_at ? \Illuminate\Support\Carbon::parse($shipment->status_updates_max_updated_at)->format('M d') : ($shipment->created_at?->format('M d') ?? '—') }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="empty">{{ $search !== '' ? 'No shipments or clients match “'.$search.'”.' : 'No shipments recorded yet.' }}</td></tr>
                        @endforelse
                    </tbody></table></div>
                    <div class="card-foot"><span class="muted">Showing {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }} of {{ $shipments->total() }} shipments</span>{{ $shipments->onEachSide(1)->links('staff.partials.pager', ['anchor' => 'shipments']) }}</div>
                </article>

                <article class="card" id="billing">
                    <div class="card-head"><h2>Billing &amp; Payments</h2><a href="{{ route('billing.index') }}" class="link">View all</a></div>
                    <div class="table-wrap"><table><thead><tr><th>Bill</th><th class="num">Due</th><th class="num">Paid</th><th>Status</th></tr></thead><tbody>
                        @forelse ($bills as $bill)
                            @php([$paymentLabel, $paymentClass] = match ($bill->payment_status) {'paid' => ['Paid', 'green'], 'partial' => ['Partially Paid', 'amber'], default => ['Unpaid', 'red']})
                            <tr><td data-label="Bill" class="strong">{{ $bill->billing_no }}<small class="cell-sub">{{ $bill->reference_no }}</small></td><td data-label="Amount Due" class="strong num">₱{{ number_format((float) $bill->total_amount, 2) }}</td><td data-label="Amount Paid" class="muted-cell num">₱{{ number_format((float) $bill->amount_paid, 2) }}</td><td data-label="Payment Status"><span class="badge badge-{{ $paymentClass }}">{{ $paymentLabel }}</span></td></tr>
                        @empty<tr><td colspan="4" class="empty">No billing records yet.</td></tr>@endforelse
                    </tbody></table></div>
                    <div class="card-foot"><span class="muted">Showing {{ $bills->firstItem() ?? 0 }}–{{ $bills->lastItem() ?? 0 }} of {{ $billingCount }} records</span>{{ $bills->onEachSide(1)->links('staff.partials.pager', ['anchor' => 'billing']) }}</div>
                </article>
            </div>

            <div class="col">
                <article class="card"><h2>Shipment Tracking</h2><p class="muted track-hint">Check any shipment by its reference number.</p><form class="track-form" id="trackForm" action="{{ route('staff.track') }}" method="get" novalidate><label class="track-input"><span aria-hidden="true">⌕</span><input type="text" id="trackInput" placeholder="Enter shipment reference number" autocomplete="off" maxlength="50"></label><button type="submit" class="btn-track">Track</button></form><p class="track-error" id="trackError" role="alert" hidden></p></article>

                <article class="card compact"><div class="card-head"><h2>Recent Transactions</h2><a href="{{ url('/transactions.php') }}" class="link">View all</a></div><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Shipment</th><th>Status</th></tr></thead><tbody>
                    @forelse ($transactions as $transaction)
                        @php([$transactionLabel, $transactionClass] = match ($transaction->status) {'completed' => ['Completed', 'green'], 'ongoing' => ['In Progress', 'blue'], default => ['Pending', 'amber']})
                        <tr><td data-label="Reference" class="strong"><span>TXN-{{ str_pad((string) $transaction->transaction_id, 4, '0', STR_PAD_LEFT) }}<small>{{ $transaction->created_at?->format('M d') ?? '—' }}</small></span></td><td data-label="Shipment" class="muted-cell">{{ $transaction->shipment?->reference_no ?? '—' }}</td><td data-label="Status"><span class="badge badge-{{ $transactionClass }}">{{ $transactionLabel }}</span></td></tr>
                    @empty<tr><td colspan="3" class="empty">No transactions yet.</td></tr>@endforelse
                </tbody></table></div></article>

                <article class="card"><div class="card-head"><h2>Recent Updates</h2></div>
                    @if ($updates->isEmpty())<p class="empty">No updates yet. New shipment activity will show up here.</p>@else<ol class="updates">
                        @foreach ($updates as $update)
                            <li class="dot-{{ $update->dashboard_status_class }}"><time>{{ $update->updated_at?->format('M d, Y') ?? '—' }}</time><strong>{{ $update->shipment?->reference_no ?? '—' }} · {{ $update->dashboard_status_label }}</strong><p>{{ trim((string) $update->remarks) !== '' ? $update->remarks : $update->status }}</p></li>
                        @endforeach
                    </ol>@endif
                </article>
            </div>
        </div>
    </div>

    <dialog class="modal" id="detailModal" aria-labelledby="modalTitle"><div class="modal-head"><div><h2 id="modalTitle"></h2><span class="badge" id="modalStatus"></span></div><button type="button" class="modal-close" id="modalClose" aria-label="Close">×</button></div><dl class="detail-grid" id="modalDetails"></dl><h3>Progress</h3><ol class="updates" id="modalTimeline"></ol></dialog>
</x-app-layout>
