{{--
    FILE PURPOSE
    File   : resources/views/transactions/index.blade.php
    Feature: Transactions
    Purpose: Transactions pages: list, link and correct a transaction.
--}}

<x-app-layout :title="$view === 'create' ? 'Link Transaction' : ($view === 'correct' ? 'Correct Transaction' : ($view === 'detail' ? 'Transaction Details' : 'Transactions'))" active="transactions">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/transactions.css') }}?v=14" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/transactions.js') }}" defer></script>
    @endpush
    @php($isClient = auth()->user()->role === 'client')
    <div class="page txn-page">
        @if (session('success'))
            <div class="txn-flash txn-flash-success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="txn-flash txn-flash-error" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="txn-flash txn-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif
        @if ($view === 'list')
            <header class="page-head">
                <div>
                    <h1>{{ $isClient ? 'My Transactions' : (auth()->user()->role === 'admin' ? 'Transaction Management' : 'Transaction Worklist') }}
                    </h1>
                    @if (auth()->user()->role === 'staff')
                        <p class="sub">Transactions for shipments assigned to you.</p>
                    @endif
                </div>
                @unless ($isClient)
                    <a class="btn btn-primary" href="{{ route('transactions.index', ['view' => 'create']) }}">+ Link Existing Shipment</a>
                @endunless
            </header>
            <section class="txn-stats four">
                <article class="fcard"><span>Total Transactions</span><strong>{{ number_format($stats['total']) }}</strong></article>
                <article class="fcard"><span>Pending</span><strong>{{ number_format($stats['pending']) }}</strong></article>
                <article class="fcard"><span>Ongoing</span><strong>{{ number_format($stats['ongoing']) }}</strong></article>
                <article class="fcard"><span>Completed</span><strong>{{ number_format($stats['completed']) }}</strong></article>
            </section>
            <section class="fcard txn-list">
                <form class="txn-filters" method="get" action="{{ route('transactions.index') }}"><label class="txn-search"><span
                            class="sr-only">Search transactions</span><input name="q" value="{{ $filters['q'] }}"
                            placeholder="Search transaction, client, shipment or bill"></label>
                    @if (auth()->user()->role === 'admin')
                        <label class="sr-only" for="clientFilter">Client</label><select id="clientFilter" name="client_id">
                            <option value="">All clients</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->client_id }}" @selected($filters['client_id'] === $client->client_id)>{{ $client->client_name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <label class="sr-only" for="statusFilter">
                        Status</label><select id="statusFilter" name="status">
                        <option value="">All statuses</option>
                        @foreach (['pending' => 'Pending', 'ongoing' => 'Ongoing', 'completed' => 'Completed'] as $key => $label)
                            <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="sr-only" for="txnStart">Created from</label><input id="txnStart" type="date" name="start"
                        value="{{ $filters['start'] }}"><label class="sr-only" for="txnEnd">Created to</label><input id="txnEnd"
                        type="date" name="end" value="{{ $filters['end'] }}"><button class="btn btn-secondary"
                        type="submit">Apply</button>
                </form>
                <div class="txn-table-scroll">
                    <table class="txn-table">
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                @unless ($isClient)
                                    <th>Client</th>
                                @endunless
                                <th>
                                    Shipment</th>
                                <th>Linked Bills</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Created</th>
                                <th>Completed</th>
                                <th class="txn-action">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transactions as $row)
                                <tr>
                                    <td data-label="Transaction ID"><a class="txn-code"
                                            href="{{ route('transactions.index', ['view' => 'detail', 'id' => $row->transaction_id]) }}">TXN-{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y') }}-{{ str_pad((string) $row->transaction_id, 4, '0', STR_PAD_LEFT) }}</a>
                                    </td>
                                    @unless ($isClient)
                                        <td data-label="Client">{{ $row->client_name }}</td>
                                    @endunless
                                    <td data-label="Shipment">
                                        <a
                                            href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $row->shipment_id) }}">{{ $row->reference_no }}</a>
                                    </td>
                                    <td data-label="Linked Bills">
                                        @php($mainBillId = $row->billing_id ?: $row->first_billing_id)
                                        @php($otherBills = max(0, (int) $row->bill_count - ($mainBillId ? 1 : 0)))
                                        @if ($mainBillId)
                                            {{-- One clear action: the bill numbers are a summary, "View" opens every bill on the transaction --}}
                                            <span class="txn-bill-cell">{{ ($row->billing_id ? $row->billing_no : $row->first_billing_no) ?: 'Bill' }}@if ($otherBills > 0)
                                                    <span class="txn-more-bills" title="{{ $row->bill_count }} bills for this shipment">+{{ $otherBills }}</span>
                                                @endif</span>
                                        @else—
                                        @endif
                                    </td>
                                    <td data-label="Status"><span
                                            class="txn-badge txn-{{ $row->status }}">{{ ucfirst($row->status) }}</span></td>
                                    @php($rowPayment = \App\Models\Transaction::paymentState((int) $row->bill_count, $row->bill_total, $row->bill_paid))
                                    <td data-label="Payment"><span
                                            class="txn-pay txn-pay-{{ $rowPayment['key'] }}">{{ $rowPayment['label'] }}</span></td>
                                    <td data-label="Created">{{ \Illuminate\Support\Carbon::parse($row->created_at)->format('M d, Y') }}
                                    </td>
                                    <td data-label="Completed">
                                        {{ $row->completed_at ? \Illuminate\Support\Carbon::parse($row->completed_at)->format('M d, Y') : '—' }}
                                    </td>
                                    <td data-label="Action" class="txn-action"><a class="txn-view-bills"
                                            href="{{ route('transactions.index', ['view' => 'detail', 'id' => $row->transaction_id]) }}{{ $row->bill_count > 0 ? '#bills' : '' }}"
                                            aria-label="View transaction for {{ $row->reference_no }}">View</a></td>
                            </tr>@empty<tr>
                                    <td colspan="{{ $isClient ? 8 : 9 }}" class="txn-empty">No transactions match your filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="txn-pager"><span>Showing {{ $transactions->firstItem() ?? 0 }}–{{ $transactions->lastItem() ?? 0 }} of
                        {{ number_format($transactions->total()) }} transactions</span>
                    <nav aria-label="Transaction pages">
                        @if ($transactions->currentPage() > 1)
                            <a href="{{ $transactions->previousPageUrl() }}">Previous</a>
                        @endif
                        <span>
                            Page {{ $transactions->currentPage() }} of {{ $transactions->lastPage() }}</span>
                        @if ($transactions->hasMorePages())
                            <a href="{{ $transactions->nextPageUrl() }}">Next</a>
                        @endif
                    </nav>
                </footer>
            </section>
            @unless ($isClient)
                <aside class="txn-note">New shipments get a transaction automatically. Use “Link Existing Shipment” only for older shipments
                    without one.</aside>
            @endunless
        @elseif ($view === 'create')
            <header class="page-head">
                <div>
                    <h1>Link Existing Shipment</h1>
                    <p class="sub">Create the missing transaction record for a shipment that has no linked transaction.</p>
                </div>
            </header>
            <aside class="txn-note">One shipment has one transaction, and every bill for that shipment appears on it. Shipment progress
                determines the initial transaction status. New shipments already get a transaction, so this list is usually empty.</aside>
            <form method="post" action="{{ route('transactions.store') }}" class="fcard txn-form">@csrf<label>Shipment *<select
                        name="shipment_id" id="txnShipment" required>
                        <option value="">Choose an unlinked shipment</option>
                        @foreach ($shipmentsToLink as $shipment)
                            <option value="{{ $shipment->shipment_id }}" @selected((string) old('shipment_id') === (string) $shipment->shipment_id)>{{ $shipment->reference_no }} ·
                                {{ $shipment->client?->client_name }} · {{ $shipment->current_status }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="txn-hint">{{ $shipmentsToLink->count() }} eligible
                    shipment{{ $shipmentsToLink->count() === 1 ? '' : 's' }}{{ auth()->user()->role === 'staff' ? ' in your assignment scope' : '' }}.
                </p><label>Billing record · Optional<select name="billing_id" id="txnBill">
                        <option value="">No linked bill</option>
                        @foreach ($linkBills as $bill)
                            <option data-shipment="{{ $bill->shipment_id }}" value="{{ $bill->billing_id }}" @selected((string) old('billing_id') === (string) $bill->billing_id)>
                                {{ $bill->billing_no }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="txn-hint">Only bills for the selected shipment and client can be linked.</p>
                <footer><a class="btn btn-secondary" href="{{ route('transactions.index') }}">Cancel</a><button class="btn btn-primary"
                        type="submit" @disabled($shipmentsToLink->isEmpty())>Save Transaction</button></footer>
            </form>
        @elseif ($view === 'detail')
            @php($txnCode = 'TXN-' . \Illuminate\Support\Carbon::parse($record->created_at)->format('Y') . '-' . str_pad((string) $record->transaction_id, 4, '0', STR_PAD_LEFT))
            <header class="page-head">
                <div>
                    <h1>{{ $txnCode }}</h1>
                    <p class="sub">
                        {{ $isClient ? 'Your shipment transaction details' : 'Transaction details · ' . $record->client_name }} /
                        {{ $record->reference_no }}</p>
                </div>
                <div class="txn-head-actions"><a class="btn btn-secondary" href="{{ route('transactions.index') }}">← Back to Transactions</a><a
                        class="btn btn-primary"
                        href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $record->shipment_id) }}">View Shipment</a></div>
            </header>
            <section class="fcard txn-overview">
                <div class="txn-overview-head">
                    <h2>Transaction overview</h2>
                    {{-- Two separate facts: shipment progress (status) and whether the shipment's bills are settled --}}
                    <div class="txn-states">
                        <span class="txn-state"><span class="txn-state-label">Status</span><span
                                class="txn-badge txn-{{ $record->status }}">{{ ucfirst($record->status) }}</span></span>
                        <span class="txn-state"><span class="txn-state-label">Payment</span><span
                                class="txn-pay txn-pay-{{ $payment['key'] }}">{{ $payment['label'] }}</span></span>
                    </div>
                </div>
                <dl>
                    <div>
                        <dt>Transaction ID</dt>
                        <dd>{{ $txnCode }}</dd>
                    </div>
                    @unless ($isClient)
                        <div>
                            <dt>Client</dt>
                            <dd>{{ $record->client_name }}</dd>
                        </div>
                    @endunless
                    <div>
                        <dt>Shipment Reference</dt>
                        <dd><a
                                href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $record->shipment_id) }}">{{ $record->reference_no }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt>Linked Bills</dt>
                        <dd>
                            @if ($bills->isNotEmpty())
                                <a href="#bills">{{ $bills->count() }} {{ $bills->count() === 1 ? 'bill' : 'bills' }}</a> ·
                                ₱{{ number_format((float) $bills->sum('balance'), 2) }} balance
                            @else—
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Created Date</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse($record->created_at)->format('M d, Y · g:i A') }}</dd>
                    </div>
                    <div>
                        <dt>Completed Date</dt>
                        <dd>{{ $record->completed_at ? \Illuminate\Support\Carbon::parse($record->completed_at)->format('M d, Y · g:i A') : 'Not completed' }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section class="fcard txn-bills" id="bills">
                <div class="txn-overview-head">
                    <h2>Bills for this shipment</h2>
                    <span class="txn-hint">{{ $bills->count() }} {{ $bills->count() === 1 ? 'bill' : 'bills' }}</span>
                </div>
                @if ($bills->isEmpty())
                    <p class="txn-empty">No bills have been created for {{ $record->reference_no }} yet.</p>
                @else
                    <div class="txn-table-scroll">
                        <table class="txn-table txn-bills-table">
                            <thead>
                                <tr>
                                    <th>Bill No.</th>
                                    <th>Bill date</th>
                                    <th>Due date</th>
                                    <th class="num">Total</th>
                                    <th class="num">Verified paid</th>
                                    <th class="num">Balance</th>
                                    <th>Payment</th>
                                    <th class="txn-action">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($bills as $bill)
                                    <tr>
                                        <td data-label="Bill No."><a
                                                href="{{ route('billing.index', ['view' => 'detail', 'id' => $bill->billing_id]) }}">{{ $bill->billing_no }}</a>
                                        </td>
                                        <td data-label="Bill date">{{ $bill->billing_date ? \Illuminate\Support\Carbon::parse($bill->billing_date)->format('M d, Y') : '—' }}</td>
                                        <td data-label="Due date">{{ $bill->due_date ? \Illuminate\Support\Carbon::parse($bill->due_date)->format('M d, Y') : '—' }}</td>
                                        <td data-label="Total" class="num">₱{{ number_format((float) $bill->total_amount, 2) }}</td>
                                        <td data-label="Verified paid" class="num">₱{{ number_format((float) $bill->amount_paid, 2) }}</td>
                                        <td data-label="Balance" class="num">₱{{ number_format((float) $bill->balance, 2) }}</td>
                                        <td data-label="Payment"><span
                                                class="txn-pay txn-pay-{{ $bill->payment_status }}">{{ ucfirst($bill->payment_status) }}</span></td>
                                        <td data-label="Action" class="txn-action"><a class="txn-view-bills"
                                                href="{{ route('billing.index', ['view' => 'detail', 'id' => $bill->billing_id]) }}"
                                                aria-label="View bill {{ $bill->billing_no }}">View bill</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3">Shipment total</th>
                                    <th class="num">₱{{ number_format((float) $bills->sum('total_amount'), 2) }}</th>
                                    <th class="num">₱{{ number_format((float) $bills->sum('amount_paid'), 2) }}</th>
                                    <th class="num">₱{{ number_format((float) $bills->sum('balance'), 2) }}</th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </section>
            <div class="txn-detail-grid">
                <section class="fcard">
                    <h2>{{ $isClient ? 'Shipment milestones' : 'Status history' }}</h2>
                    <p class="txn-hint">
                        {{ $isClient ? 'Progress recorded by the AstraTrack operations team.' : 'Every transaction event is recorded with its source and time.' }}
                    </p>
                    @if ($history->isEmpty())
                        <p class="txn-empty">No transaction history has been recorded.</p>
                    @endif
                    <ol class="txn-history">
                        @foreach ($history as $entry)
                            <li><span class="txn-history-dot"></span><strong>{{ $entry->event_type }}</strong>
                                <p>{{ $entry->description ?: '—' }}</p>
                                <small>{{ $isClient ? 'Operations team' : ($entry->performedBy?->full_name ?: 'System') }} ·
                                    {{ $entry->created_at?->format('M d, Y · g:i A') }}</small>
                            </li>
                        @endforeach
                    </ol>
                </section>
                <aside class="fcard txn-linked">
                    <h2>Linked shipment</h2><a
                        href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $record->shipment_id) }}">{{ $record->reference_no }}</a>
                    <p>Current shipment stage: <strong>{{ $record->shipment_status }}</strong></p><span
                        class="txn-badge txn-{{ $record->status }}">{{ ucfirst($record->status) }}</span>
                    <p class="txn-hint">Transaction status follows shipment progress: delivered shipments complete the transaction. Payment is
                        tracked separately from the shipment's bills.</p><a
                        class="btn btn-secondary"
                        href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $record->shipment_id) }}">View Shipment</a>
                    @if ($bills->isNotEmpty())
                        <a class="btn btn-secondary" href="#bills">View Bills ({{ $bills->count() }})</a>
                        @endif @if (auth()->user()->role === 'admin')
                            <a class="btn btn-secondary"
                                href="{{ route('transactions.index', ['view' => 'correct', 'id' => $record->transaction_id]) }}">Authorized
                                correction</a>
                        @endif
                </aside>
            </div>
        @elseif ($view === 'correct')
            @php($txnCode = 'TXN-' . \Illuminate\Support\Carbon::parse($record->created_at)->format('Y') . '-' . str_pad((string) $record->transaction_id, 4, '0', STR_PAD_LEFT))
            <header class="page-head">
                <div>
                    <h1>Correct Transaction</h1>
                    <p class="sub">Authorized correction · {{ $txnCode }} / {{ $record->client_name }}</p>
                </div>
            </header>
            <aside class="txn-warning">Transaction status normally follows shipment progress. A manual repair requires administrator
                authority and an audit reason.</aside>
            <form method="post" action="{{ route('transactions.correct') }}" class="fcard txn-form">@csrf<input type="hidden"
                    name="transaction_id" value="{{ $record->transaction_id }}">
                <div class="txn-correction-states">
                    <div><span>Current status</span><strong>{{ ucfirst($record->status) }}</strong></div><b>→</b>
                    <div><span>Shipment stage</span><strong>{{ $record->shipment_status }}</strong></div>
                </div><label>Corrected status<select disabled>
                        <option>{{ ucfirst($suggestedStatus) }}</option>
                    </select></label>
                <p class="txn-hint">The suggested status is derived from the linked shipment stage.</p><label>Correction reason *
                    <textarea name="reason" minlength="5" maxlength="255" required>{{ old('reason') }}</textarea>
                </label><label class="txn-confirm"><input type="checkbox" name="confirm" value="1" required><span>I confirm this
                        correction matches shipment progress and will be recorded in history.</span></label>
                <footer><a class="btn btn-secondary"
                        href="{{ route('transactions.index', ['view' => 'detail', 'id' => $record->transaction_id]) }}">Cancel</a><button
                        class="btn btn-primary" type="submit" @disabled($suggestedStatus === $record->status || $record->archived_at)>Confirm &amp; Save Correction</button>
                </footer>
            </form>
        @endif
    </div>
</x-app-layout>
