{{--
    FILE PURPOSE
    File   : resources/views/billing/index.blade.php
    Feature: Billing
    Purpose: Billing pages: list, create bill and bill details.
--}}

@php
    $isClientTitle = auth()->user()->role === 'client';
    $pageTitle = match ($view) {
        'detail' => $bill->billing_no,
        'create' => 'Create Bill',
        'review' => 'Payment Review',
        'record' => 'Record Payment',
        'submit' => 'Submit Payment Evidence',
        default => $isClientTitle ? 'My Billing & Payments' : 'Billing Overview',
    };
@endphp
<x-app-layout :title="$pageTitle" active="billing">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/billing.css') }}?v=16" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/billing.js') }}?v=3" defer></script>
    @endpush
    @php($isClient = auth()->user()->role === 'client')
    <div class="page bill-page">
        @if (session('success'))
            <div class="bill-flash bill-flash-success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('warnings'))
            <div class="bill-flash bill-flash-warning" role="status"><strong>Check this bill against Customs Details:</strong>
                <ul>
                    @foreach ((array) session('warnings') as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (session('error'))
            <div class="bill-flash bill-flash-error" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bill-flash bill-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($view === 'list')
            <header class="page-head bill-head">
                <div>
                    <h1>{{ $isClient ? 'My Billing & Payments' : (auth()->user()->role === 'admin' ? 'Billing Overview' : 'Billing Worklist') }}
                    </h1>
                    @if (auth()->user()->role === 'staff')
                        <p class="sub">Bills for shipments assigned to you.</p>
                    @endif
                </div>
                @unless ($isClient)
                    <div class="bill-head-actions"><a class="btn btn-secondary"
                            href="{{ route('billing.index', ['view' => 'review']) }}">Payment
                            Review{{ $stats->pending_count ? ' · ' . $stats->pending_count : '' }}</a><a class="btn btn-primary"
                            href="{{ route('billing.index', ['view' => 'create']) }}">+ Create Bill</a></div>
                @endunless
            </header>
            <section class="bill-stats">
                <article class="fcard"><span>Total Billed</span><strong>₱{{ number_format((float) $stats->billed, 2) }}</strong></article>
                <article class="fcard"><span>Verified
                        Payments</span><strong>₱{{ number_format((float) $stats->paid, 2) }}</strong><small>Verified entries only</small>
                </article>
                <article class="fcard">
                    <span>{{ $isClient ? 'Balance Due' : 'Outstanding Balance' }}</span><strong>₱{{ number_format((float) $stats->balance, 2) }}</strong><small>Pending
                        and rejected payments do not reduce this balance</small></article>
                <article class="fcard">
                    <span>{{ $isClient ? 'Unpaid Bills' : 'Payments Pending Verification' }}</span><strong>{{ $isClient ? number_format((int) ($stats->unpaid_bills ?? 0)) . ' bills' : number_format((int) $stats->pending_count) . ' · ₱' . number_format((float) $stats->pending_amount, 2) }}</strong>
                    @unless ($isClient)
                        <small>Awaiting review</small>
                    @endunless
                </article>
            </section>
            <section class="fcard bill-list-card">
                <form class="bill-filters" method="get" action="{{ route('billing.index') }}"><label class="bill-search"><span
                            class="sr-only">Search billing records</span><input name="q" value="{{ $filters['q'] }}"
                            placeholder="{{ $isClient ? 'Search Bill No. or Shipment Reference' : 'Search Bill No., Client, Shipment or Payment Reference' }}"></label>
                    @if (auth()->user()->role === 'admin')<label><span>Client</span><select name="client_id">
                                <option value="">All clients</option>
                                @foreach ($clientsForFilter as $client)
                                    <option value="{{ $client->client_id }}" @selected($filters['client_id'] === $client->client_id)>{{ $client->client_name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>@endif
                    <label><span>Bill Status</span><select name="bill_status">
                            <option value="">All statuses</option>
                            @foreach (['unpaid' => 'Unpaid', 'partial' => 'Partially Paid', 'paid' => 'Paid'] as $key => $label)
                                <option value="{{ $key }}" @selected($filters['bill_status'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @unless ($isClient)
                        <label><span>Payment Status</span><select name="payment_status">
                                <option value="">All payment statuses</option>
                                @foreach (['pending' => 'Pending Verification', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $key => $label)
                                    <option value="{{ $key }}" @selected($filters['payment_status'] === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endunless
                    <label><span>Date From</span><input type="date" name="start"
                            value="{{ $filters['start'] }}"></label><label><span>Date To</span><input type="date" name="end"
                            value="{{ $filters['end'] }}"></label><button class="btn btn-secondary" type="submit">Apply Filters</button>
                </form>
                <div class="bill-table-title">
                    <h2>{{ $isClient ? 'Your bills' : (auth()->user()->role === 'admin' ? 'All bills' : 'Your permitted bills') }}</h2>
                    @if (auth()->user()->role === 'staff')
                        <span>Assigned shipments only</span>
                    @endif
                </div>
                @if ($bills->isEmpty())
                    <div class="bill-empty bill-carousel-empty"><strong>No bills match your filters.</strong><span>Clear filters to see all
                            available bills.</span></div>
                @else
                    @php($statusLabels = ['unpaid' => 'Unpaid', 'partial' => 'Partially Paid', 'paid' => 'Paid'])
                    <div class="bill-carousel" data-bill-carousel>
                        <div class="bill-carousel-controls">
                            <span class="bill-carousel-position" aria-live="polite">Bill 1 of {{ $bills->count() }}</span>
                            <div><button class="bill-carousel-button" type="button" data-bill-prev aria-label="Previous bill"
                                    disabled>‹</button><button class="bill-carousel-button" type="button" data-bill-next
                                    aria-label="Next bill" @disabled($bills->count() < 2)>›</button></div>
                        </div>
                        <div class="bill-carousel-track" data-bill-track tabindex="0" role="region" aria-label="Bills on this page"
                            aria-roledescription="carousel">
                            @foreach ($bills as $index => $row)
                                @php($overdue = $row->due_date && $row->due_date < now()->toDateString() && $row->balance > 0)
                                <article class="bill-record-card bill-carousel-slide{{ $overdue ? ' is-overdue' : '' }}" data-bill-slide
                                    role="group" aria-roledescription="slide"
                                    aria-label="Bill {{ $index + 1 }} of {{ $bills->count() }}">
                                    <header class="bill-record-card-head">
                                        <div><a class="bill-link"
                                                href="{{ route('billing.index', ['view' => 'detail', 'id' => $row->billing_id]) }}">{{ $row->billing_no }}</a>
                                            @if ((int) $row->pending_count > 0)
                                                <small class="bill-pending-note">{{ (int) $row->pending_count }}
                                                    payment{{ (int) $row->pending_count === 1 ? '' : 's' }} pending</small>
                                            @endif
                                        </div>
                                        <span
                                            class="bill-status bill-status-{{ $row->payment_status }}">{{ $statusLabels[$row->payment_status] ?? ucfirst($row->payment_status) }}</span>
                                    </header>
                                    @unless ($isClient)
                                        <div class="bill-record-client"><span>Client</span><strong>{{ $row->client_name }}</strong></div>
                                    @endunless
                                    <div class="bill-record-meta">
                                        <div><span>Shipment</span><a class="bill-shipment"
                                                href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $row->shipment_id) }}">{{ $row->reference_no }}</a>
                                        </div>
                                        <div><span>Bill
                                                date</span><strong>{{ $row->billing_date ? \Illuminate\Support\Carbon::parse($row->billing_date)->format('M d, Y') : '—' }}</strong>
                                        </div>
                                        <div><span>Due date</span><strong
                                                class="{{ $overdue ? 'bill-overdue' : '' }}">{{ $row->due_date ? \Illuminate\Support\Carbon::parse($row->due_date)->format('M d, Y') : '—' }}{{ $overdue ? ' · Overdue' : '' }}</strong>
                                        </div>
                                    </div>
                                    <div class="bill-record-amounts">
                                        <div><span>Total</span><strong>₱{{ number_format((float) $row->total_amount, 2) }}</strong></div>
                                        <div><span>Verified paid</span><strong>₱{{ number_format((float) $row->amount_paid, 2) }}</strong>
                                        </div>
                                        <div><span>Balance</span><strong>₱{{ number_format(max(0, (float) $row->balance), 2) }}</strong>
                                        </div>
                                    </div>
                                    <footer class="bill-record-actions"><a class="bill-record-action"
                                            href="{{ route('billing.index', ['view' => 'detail', 'id' => $row->billing_id]) }}">View
                                            bill</a>
                                        @if ((float) $row->balance > 0 && !$row->archived_at)
                                            <a class="bill-record-action bill-record-action-primary"
                                                href="{{ route('billing.index', ['view' => $isClient ? 'submit' : 'record', 'id' => $row->billing_id]) }}">{{ $isClient ? 'Submit payment' : 'Record payment' }}</a>
                                        @endif
                                    </footer>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif
                <footer class="bill-table-foot"><span>Showing {{ $bills->count() }} of {{ $totalBills }}
                        bill{{ $totalBills === 1 ? '' : 's' }}</span></footer>
            </section>
        @elseif ($view === 'create')
            <header class="page-head">
                <div>
                    <h1>Create Bill</h1>
                    <p class="sub">Create an itemized bill for an active client shipment.</p>
                </div>
            </header>
            @if ($prefill && !old('items'))
                <p class="bill-flash bill-flash-info" role="status">Pre-filled from the Customs Details of {{ $prefill['reference'] }}{{ $prefill['entry_no'] ? ' (Entry No. ' . $prefill['entry_no'] . ')' : '' }}.
                    Check the amount and due date, then save. The client is notified when the bill is saved.</p>
            @endif
            <form method="post" action="{{ route('billing.store') }}" class="fcard bill-form" id="createBillForm">@csrf
                <section>
                    <h2>01 · Client &amp; Shipment</h2>
                    <div class="bill-form-grid"><label>Client *<select id="billClient" name="client_id" required>
                                <option value="">Choose a client</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->client_id }}" @selected((int) request('client_id') === (int) $client->client_id)>{{ $client->client_name }}
                                    </option>
                                @endforeach
                            </select>
                        </label><label>Shipment *<select name="shipment_id" id="billShipment" required>
                                <option value="">{{ request('client_id') ? 'Choose a shipment' : 'Choose a client first' }}</option>
                                @foreach ($shipments as $shipment)
                                    <option value="{{ $shipment->shipment_id }}" @selected((string) old('shipment_id', $prefill['shipment_id'] ?? request('shipment_id')) === (string) $shipment->shipment_id)>
                                        {{ $shipment->reference_no }} · {{ ucfirst($shipment->transaction_type) }} /
                                        {{ ucfirst($shipment->mode) }}</option>
                                @endforeach
                            </select>
                        </label><label>Billing Date *<input type="date" name="billing_date"
                                value="{{ old('billing_date', now()->toDateString()) }}" required></label><label>Due Date *<input
                                type="date" name="due_date" value="{{ old('due_date', now()->addDays(14)->toDateString()) }}"
                                required></label></div>
                    <p class="bill-hint">Shipment choices are limited to the selected client.
                        {{ auth()->user()->role === 'staff' ? 'Only shipments assigned to you are available.' : '' }}</p>
                </section>
                <section>
                    <h2>02 · Itemized Charges</h2>
                    <div class="bill-item-head"><span>Charge Type</span><span>Description</span><span>Amount (PHP)</span><span></span>
                    </div>
                    <div id="billItems">
                        @foreach (old('items', $prefill['items'] ?? [[]]) as $index => $oldItem)
                            <div class="bill-item-row"><select name="items[{{ $index }}][charge_type]" required>
                                    <option value="">Select type</option>
                                    @foreach (['Freight', 'Handling', 'Documentation', 'Duties and taxes', 'Customs-related charge', 'Other expense', 'Orange lane', 'Red lane', 'DEA examination', 'BOC Task Force'] as $type)
                                        <option @selected(($oldItem['charge_type'] ?? '') === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                                <input name="items[{{ $index }}][description]" maxlength="255" placeholder="Description"
                                    value="{{ $oldItem['description'] ?? '' }}"><input name="items[{{ $index }}][amount]"
                                    type="number" min="0.01" step="0.01" required placeholder="0.00"
                                    value="{{ $oldItem['amount'] ?? '' }}"><button type="button" class="bill-remove"
                                    data-remove-bill-item aria-label="Remove charge" @disabled(count(old('items', $prefill['items'] ?? [[]])) <= 1)>×</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-secondary" id="addBillItem">+ Add Charge</button>
                    <div class="bill-total">Total amount <strong>₱<span id="billTotal">0.00</span></strong></div>
                    <p class="bill-hint">The total is calculated from charges. New bills start as Unpaid.</p>
                </section>
                <footer><a class="btn btn-secondary" href="{{ route('billing.index') }}">Cancel</a><button class="btn btn-primary"
                        type="submit">Save Bill</button></footer>
            </form>
        @elseif ($view === 'review')
            <header class="page-head">
                <div>
                    <h1>Payment Review</h1>
                    <p class="sub">
                        {{ auth()->user()->role === 'admin' ? 'Review external payment evidence before updating a bill balance.' : 'Pending payments for your assigned shipments. Check the evidence, then verify or reject.' }}
                    </p>
                </div>
                <div class="bill-head-actions">
                    <a class="btn btn-secondary bill-back" href="{{ route('billing.index') }}"><svg viewBox="0 0 24 24" width="18"
                            height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to Billing &amp; Payments</a>
                </div>
            </header>
            @if ($reviewRows->isEmpty())
                <section class="fcard bill-review-empty" role="status">
                    <div class="bill-review-empty-icon" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none">
                            <path
                                d="M12 8.5h24a3.5 3.5 0 0 1 3.5 3.5v24a3.5 3.5 0 0 1-3.5 3.5H12A3.5 3.5 0 0 1 8.5 36V12A3.5 3.5 0 0 1 12 8.5Z" />
                            <path d="M16 18h16M16 25h9M28 31l3 3 6-7" />
                        </svg></div>
                    <div class="bill-review-empty-copy">
                        <h2>You're all caught up</h2>
                        <p>No payments are waiting for review. New payment submissions will appear here.</p><a class="btn btn-secondary"
                            href="{{ route('billing.index') }}">Back to billing</a>
                    </div>
                </section>
            @else
                <div class="bill-review-layout">
                    <section class="fcard bill-review-list" aria-labelledby="reviewListTitle">
                        <h2 id="reviewListTitle">Pending Review · {{ $reviewRows->count() }}</h2>
                        @foreach ($reviewRows as $payment)
                            @php($isSelected = $reviewSelected && $reviewSelected->payment_id === $payment->payment_id)
                            <a class="bill-review-item{{ $isSelected ? ' is-selected' : '' }}" @if ($isSelected) aria-current="true" @endif
                                href="{{ route('billing.index', ['view' => 'review', 'payment' => $payment->payment_id]) }}#reviewPanel"><strong>{{ $payment->billing_no }}</strong><span>{{ $payment->client_name }}
                                    ·
                                    ₱{{ number_format((float) $payment->amount, 2) }}</span><small>{{ \Illuminate\Support\Carbon::parse($payment->payment_date)->format('M d, Y') }}
                                    · {{ $payment->payment_method }}</small>@if ($payment->staff_checked_by)<span
                                        class="bill-review-chip">Checked by staff · awaiting admin</span>@endif</a>
                        @endforeach
                    </section>
                    @php($p = $reviewSelected)
                    @php($exceeds = round((float) $p->amount * 100) > round((float) $p->balance * 100))
                    @php($role = auth()->user()->role)
                    @php($large = \App\Models\Payment::needsAdminApproval($p->amount))
                    @php($threshold = '₱' . number_format((float) config('billing.admin_approval_threshold'), 2))
                    <section class="fcard bill-review-detail" id="reviewPanel" aria-labelledby="reviewPanelTitle">
                        <header class="bill-review-detail-head">
                            <div>
                                <h2 id="reviewPanelTitle">{{ $p->billing_no }}</h2>
                                <p>{{ $p->client_name }} · {{ $p->shipment_reference }}</p>
                            </div>
                            <span class="payment-state payment-state-pending">{{ $p->staff_checked_by ? 'Awaiting admin approval' : 'Pending' }}</span>
                        </header>
                        <div class="bill-review-amount">
                            <span>Payment amount</span>
                            <strong>₱{{ number_format((float) $p->amount, 2) }}</strong>
                        </div>
                        <dl class="bill-review-facts">
                            <div><dt>Payment date</dt><dd>{{ \Illuminate\Support\Carbon::parse($p->payment_date)->format('M d, Y') }}</dd></div>
                            <div><dt>Method</dt><dd>{{ $p->payment_method ?: '—' }}</dd></div>
                            <div><dt>Reference number</dt><dd>{{ $p->reference_no ?: '—' }}</dd></div>
                            <div><dt>Recorded by</dt><dd>{{ $p->recorded_by_name ?: '—' }}</dd></div>
                            <div><dt>Bill total</dt><dd>₱{{ number_format((float) $p->total_amount, 2) }}</dd></div>
                            <div><dt>Outstanding now</dt><dd>₱{{ number_format(max(0, (float) $p->balance), 2) }}</dd></div>
                            @if ($p->staff_checked_by)
                                <div><dt>Staff check</dt><dd>{{ $p->staff_checked_name ?: 'Staff' }} ·
                                        {{ \Illuminate\Support\Carbon::parse($p->staff_checked_at)->format('M d, Y · g:i A') }}</dd></div>
                            @endif
                        </dl>
                        @if ($large && ! $p->staff_checked_by && $role === 'staff')
                            <p class="bill-review-note">This payment is {{ $threshold }} or more. Your check sends it to an administrator,
                                who gives the final approval before the bill balance changes.</p>
                        @endif
                        <div class="bill-review-evidence">
                            <span>Evidence</span>
                            @if ($p->proof_file_path)
                                <a href="{{ route('billing.evidence', $p->payment_id) }}">Download evidence</a>
                            @else
                                <em>No file attached</em>
                            @endif
                        </div>
                        @if ($exceeds)
                            <p class="bill-review-warning" role="note">This amount is more than the bill's outstanding balance, so it
                                cannot be verified. Reject it and ask the submitter to correct the amount.</p>
                        @endif
                        <footer class="bill-review-foot">
                            <a class="btn btn-secondary" href="{{ route('billing.index', ['view' => 'detail', 'id' => $p->billing_id]) }}#payment-{{ $p->payment_id }}">Open bill</a>
                            @php($ownPayment = auth()->user()->role === 'staff' && (int) $p->recorded_by === (int) auth()->user()->user_id)
                            @if ($ownPayment)
                                <span class="bill-muted">You recorded this payment, so another person must verify it.</span>
                            @elseif ($role === 'staff' && $p->staff_checked_by)
                                <span class="bill-muted">Checked{{ (int) $p->staff_checked_by === (int) auth()->user()->user_id ? ' by you' : '' }} ·
                                    waiting for an administrator's final approval.</span>
                            @elseif (in_array($role, ['admin', 'staff'], true))
                                <form method="post" action="{{ route('billing.payments.review') }}" class="bill-review-decision">@csrf
                                    <input type="hidden" name="payment_id" value="{{ $p->payment_id }}"><input type="hidden" name="return" value="review">
                                    <label class="bill-review-reason">Reason if rejecting <small>(sent to the client)</small>
                                        <input type="text" name="reason" maxlength="150" value="{{ old('reason') }}"
                                            placeholder="e.g. Reference number not found in our bank account"></label>
                                    <button class="btn btn-secondary bill-reject" name="decision" value="rejected"
                                        data-confirm="Reject this payment? The bill balance will remain unchanged.">Reject</button>
                                    @if ($role === 'staff' && $large)
                                        <button class="btn btn-primary bill-verify-primary" name="decision" value="verified" @disabled($exceeds)
                                            data-confirm="Send ₱{{ number_format((float) $p->amount, 2) }} to an administrator for final approval? The bill balance does not change yet.">Check &amp; send to admin</button>
                                    @else
                                        <button class="btn btn-primary bill-verify-primary" name="decision" value="verified" @disabled($exceeds)
                                            data-confirm="Verify ₱{{ number_format((float) $p->amount, 2) }}? This reduces the bill balance.">{{ $p->staff_checked_by ? 'Approve payment' : 'Verify payment' }}</button>
                                    @endif
                                </form>
                            @else
                                <span class="bill-muted">Only an administrator can verify or reject this payment.</span>
                            @endif
                        </footer>
                    </section>
                </div>
            @endif
            @if (auth()->user()->role === 'admin')
                <section class="fcard bill-spotcheck" aria-labelledby="spotcheckTitle">
                    <header>
                        <div>
                            <h2 id="spotcheckTitle">Verified by staff · last 7 days</h2>
                            <p>Spot-check these. If one is wrong, open the bill and reverse the payment.</p>
                        </div>
                        <span class="bill-muted">{{ $staffVerified->count() }} {{ $staffVerified->count() === 1 ? 'payment' : 'payments' }}</span>
                    </header>
                    @if ($staffVerified->isEmpty())
                        <p class="bill-spotcheck-empty">No payments were verified by staff in the last 7 days.</p>
                    @else
                        <div class="table-wrap">
                            <table class="bill-spotcheck-table">
                                <thead>
                                    <tr>
                                        <th>Bill No.</th>
                                        <th>Client</th>
                                        <th class="num">Amount</th>
                                        <th>Method</th>
                                        <th>Verified by</th>
                                        <th>Verified</th>
                                        <th class="bill-action">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($staffVerified as $row)
                                        <tr>
                                            <td data-label="Bill No.">{{ $row->billing_no }}</td>
                                            <td data-label="Client">{{ $row->client_name }}</td>
                                            <td data-label="Amount" class="num">₱{{ number_format((float) $row->amount, 2) }}</td>
                                            <td data-label="Method">{{ $row->payment_method ?: '—' }}</td>
                                            <td data-label="Verified by">{{ $row->verified_by_name }}</td>
                                            <td data-label="Verified">{{ \Illuminate\Support\Carbon::parse($row->verified_at)->format('M d, Y · g:i A') }}</td>
                                            <td data-label="Action" class="bill-action"><a class="bill-open"
                                                    href="{{ route('billing.index', ['view' => 'detail', 'id' => $row->billing_id]) }}#payment-{{ $row->payment_id }}">Open bill</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        @elseif ($bill)
            @if ($view === 'detail')
                <header class="page-head bill-detail-head">
                    <div>
                        <h1>{{ $bill->billing_no }}</h1>
                        <p class="sub"><a
                                href="{{ route('billing.index') }}">{{ $isClient ? 'My Billing & Payments' : 'Billing & Payments' }}</a>
                            · Bill details</p>
                    </div>
                    <div class="bill-head-actions">
                        <a class="btn btn-secondary bill-back" href="{{ route('billing.index') }}"><svg viewBox="0 0 24 24" width="18"
                                height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>Back to
                            {{ $isClient ? 'My Billing & Payments' : 'Billing & Payments' }}</a>
                        @if ($bill->balance > 0 && !$bill->archived_at)
                            <a class="btn btn-primary"
                                href="{{ route('billing.index', ['view' => $isClient ? 'submit' : 'record', 'id' => $bill->billing_id]) }}">{{ $isClient ? 'Submit Payment Evidence' : 'Record Payment' }}</a>
                        @endif
                    </div>
                </header>
                <section class="fcard bill-detail-card">
                    <div class="bill-detail-top">
                        <div>
                            <h2>{{ $bill->client_name }}</h2><span
                                class="bill-status bill-status-{{ $bill->payment_status }}">{{ ucfirst($bill->payment_status) }}</span>
                        </div>
                    </div>
                    <dl class="bill-meta">
                        @unless ($isClient)
                            <div>
                                <dt>Client</dt>
                                <dd>{{ $bill->client_name }}</dd>
                            </div>
                        @endunless
                        <div>
                            <dt>Shipment</dt>
                            <dd><a
                                    href="{{ route($isClient ? 'client.shipments.show' : 'shipments.show', $bill->shipment_id) }}">{{ $bill->reference_no }}</a>
                            </dd>
                        </div>
                        <div>
                            <dt>Billing Date</dt>
                            <dd>{{ \Illuminate\Support\Carbon::parse($bill->billing_date)->format('M d, Y') }}</dd>
                        </div>
                        <div>
                            <dt>Due Date</dt>
                            <dd>{{ $bill->due_date ? \Illuminate\Support\Carbon::parse($bill->due_date)->format('M d, Y') : '—' }}
                                @if ($bill->due_date && $bill->due_date < now()->toDateString() && $bill->balance > 0)
                                    · Overdue
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <div class="table-wrap">
                        <table class="bill-table bill-items-table">
                            <thead>
                                <tr>
                                    <th>Charge Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($items as $item)
                                    <tr>
                                        <td>{{ $item->charge_type }}</td>
                                        <td>{{ $item->description ?: '—' }}</td>
                                        <td>₱{{ number_format((float) $item->amount, 2) }}</td>
                                </tr>@empty<tr>
                                        <td colspan="3">No line items recorded.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="bill-totals"><span>Total
                            <strong>₱{{ number_format((float) $bill->total_amount, 2) }}</strong></span><span>Verified Paid
                            <strong>₱{{ number_format((float) $bill->amount_paid, 2) }}</strong></span><span>Outstanding
                            <strong>₱{{ number_format(max(0, (float) $bill->balance), 2) }}</strong></span></div>
                </section>
                <section class="fcard bill-payments-card">
                    <header>
                        <div>
                            <h2>Payment History</h2>
                            <p>Only verified payments reduce the bill balance.</p>
                        </div>
                        @if (auth()->user()->role === 'staff')
                            <a href="{{ route('billing.index', ['view' => 'review']) }}">Review pending payments</a>
                        @endif
                    </header>
                    <div class="table-wrap">
                        <table class="bill-table">
                            <thead>
                                <tr>
                                    <th>Payment Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference Number</th>
                                    <th>Evidence</th>
                                    <th>Verification Status</th>
                                    @unless ($isClient)
                                        <th>Recorded By</th>
                                        <th>Review</th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($payments as $payment)
                                    <tr id="payment-{{ $payment->payment_id }}">
                                        <td>{{ $payment->payment_date?->format('M d, Y') }}</td>
                                        <td>₱{{ number_format((float) $payment->amount, 2) }}</td>
                                        <td>{{ $payment->payment_method ?: '—' }}</td>
                                        <td>{{ $payment->reference_no ?: '—' }}</td>
                                        <td>
                                            @if ($payment->proof_file_path)
                                                <a href="{{ route('billing.evidence', $payment->payment_id) }}">Download
                                                evidence</a>@else<span class="bill-muted">No file attached</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($payment->isReversed())
                                                <span class="payment-state payment-state-rejected">Reversed</span>
                                            @elseif ($payment->verification_status === 'pending' && $payment->staff_checked_by)
                                                <span class="payment-state payment-state-pending">Awaiting admin</span>
                                            @else
                                                <span class="payment-state payment-state-{{ $payment->verification_status }}">{{ ucfirst($payment->verification_status) }}</span>
                                            @endif
                                        </td>
                                        @unless ($isClient)
                                            <td>{{ $payment->recordedBy?->full_name ?: '—' }}</td>
                                            <td>
                                                @if (auth()->user()->role === 'staff' && $payment->verification_status === 'pending' && (int) $payment->recorded_by === (int) auth()->user()->user_id)
                                                    <span class="bill-muted">Recorded by you · needs another reviewer</span>
                                                @elseif (auth()->user()->role === 'staff' && $payment->verification_status === 'pending' && $payment->staff_checked_by)
                                                    <span class="bill-muted">Checked by {{ $payment->staffCheckedBy?->full_name ?: 'staff' }} · awaiting admin</span>
                                                @elseif (in_array(auth()->user()->role, ['admin', 'staff'], true) && $payment->verification_status === 'pending')
                                                    <form method="post" action="{{ route('billing.payments.review') }}"
                                                        class="bill-review-actions">@csrf<input type="hidden" name="payment_id"
                                                            value="{{ $payment->payment_id }}"><button name="decision" value="verified"
                                                            class="bill-verify"
                                                            data-confirm="Verify ₱{{ number_format((float) $payment->amount, 2) }}? This reduces the bill balance.">Verify</button><button
                                                            name="decision" value="rejected" class="bill-reject"
                                                            data-confirm="Reject this payment? The bill balance will remain unchanged.">Reject</button>
                                                    </form>
                                                @elseif ($payment->verification_status === 'pending')
                                                    <span class="bill-muted">Awaiting
                                                        review</span>
                                                @elseif ($payment->isReversed())
                                                    <span class="bill-muted" title="{{ $payment->reversal_reason }}">Reversed by
                                                        {{ $payment->reversedBy?->full_name ?: 'admin' }}: {{ \Illuminate\Support\Str::limit($payment->reversal_reason, 60) }}</span>
                                                @else
                                                    {{ $payment->verifiedBy?->full_name ?: 'Reviewed' }}
                                                    @if ($payment->staff_checked_by && $payment->verification_status === 'verified')
                                                        <small class="bill-muted">Checked by {{ $payment->staffCheckedBy?->full_name ?: 'staff' }}</small>
                                                    @endif
                                                    @if (auth()->user()->role === 'admin' && $payment->verification_status === 'verified')
                                                        <a class="bill-reverse-link"
                                                            href="{{ route('billing.index', ['view' => 'detail', 'id' => $bill->billing_id, 'reverse' => $payment->payment_id]) }}#reversePayment">Reverse</a>
                                                    @endif
                                                @endif
                                            </td>
                                        @endunless
                                    </tr>
                                @empty<tr>
                                        <td colspan="{{ $isClient ? 6 : 8 }}" class="bill-empty">No payments recorded.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
                @php($verifiedPayments = $payments->where('verification_status', 'verified'))
                @if (auth()->user()->role === 'admin' && $verifiedPayments->isNotEmpty())
                    @php($reverseId = (int) request()->query('reverse'))
                    <details class="fcard bill-reverse-panel" id="reversePayment" @if ($reverseId || $errors->has('reason') || $errors->has('confirm')) open @endif>
                        <summary>Reverse a verified payment</summary>
                        <p>Use this when a verified payment turns out to be wrong, for example a bounced check or a wrong amount. The
                            payment stops counting toward this bill and the reason is saved in its history.</p>
                        <form method="post" action="{{ route('billing.payments.reverse') }}">@csrf
                            <label>Payment
                                <select name="payment_id" required>
                                    @foreach ($verifiedPayments as $vp)
                                        <option value="{{ $vp->payment_id }}" @selected((int) old('payment_id', $reverseId) === (int) $vp->payment_id)>
                                            {{ $vp->payment_date?->format('M d, Y') }} · ₱{{ number_format((float) $vp->amount, 2) }} ·
                                            {{ $vp->payment_method ?: 'Payment' }}{{ $vp->reference_no ? ' · Ref ' . $vp->reference_no : '' }}</option>
                                    @endforeach
                                </select></label>
                            <label>Reason <small>(saved in history)</small>
                                <textarea name="reason" minlength="5" maxlength="255" required
                                    placeholder="e.g. Check bounced, amount entered wrong">{{ old('reason') }}</textarea></label>
                            <label class="bill-reverse-confirm"><input type="checkbox" name="confirm" value="1" required>
                                <span>I understand the bill's outstanding balance goes back up by this payment's amount.</span></label>
                            <div><button class="btn btn-secondary bill-reject" type="submit">Reverse payment</button></div>
                        </form>
                    </details>
                @endif
                <aside class="bill-note">Pending and rejected payments remain in the history but do not reduce the outstanding balance.
                </aside>
            @else
                <header class="page-head">
                    <div>
                        <h1>{{ $view === 'submit' ? 'Submit Payment Evidence' : 'Record Payment' }}</h1>
                        <p class="sub">{{ $bill->billing_no }} · {{ $bill->reference_no }}</p>
                    </div>
                </header>
                <section class="fcard bill-summary-strip">
                    <div><span>Client</span><strong>{{ $bill->client_name }}</strong></div>
                    <div><span>Shipment</span><strong>{{ $bill->reference_no }}</strong></div>
                    <div><span>Outstanding Balance</span><strong>₱{{ number_format(max(0, (float) $bill->balance), 2) }}</strong></div>
                </section>
                <form method="post" action="{{ route('billing.payments.store') }}" enctype="multipart/form-data"
                    class="fcard bill-form">@csrf<input type="hidden" name="billing_id" value="{{ $bill->billing_id }}">
                    <h2>Payment Information</h2>
                    <div class="bill-form-grid"><label>Amount (PHP) *<input name="amount" type="number" min="0.01"
                                max="{{ number_format(max(0, (float) $bill->balance), 2, '.', '') }}" step="0.01"
                                value="{{ old('amount') }}" required></label><label>Payment Date *<input name="payment_date"
                                type="date" max="{{ now()->toDateString() }}"
                                value="{{ old('payment_date', now()->toDateString()) }}" required></label><label>Payment Method *<select
                                name="payment_method" required>
                                <option value="">Choose method</option>
                                @foreach (['Bank transfer', 'Cash', 'Check', 'E-wallet', 'Other'] as $method)
                                    <option @selected(old('payment_method') === $method)>{{ $method }}</option>
                                @endforeach
                            </select></label><label>Reference Number *<input name="reference_no" maxlength="60"
                                value="{{ old('reference_no') }}" required></label><label class="bill-file-field">Receipt or scanned
                            check *<input name="evidence" type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required><small>PDF, JPG, or PNG ·
                                maximum 10 MB. Evidence is required for review.</small></label></div>
                    <aside class="bill-note">External payments only. New entries are Pending Verification and do not reduce the balance
                        until an admin verifies them.</aside>
                    <footer><a class="btn btn-secondary"
                            href="{{ route('billing.index', ['view' => 'detail', 'id' => $bill->billing_id]) }}">Cancel</a><button
                            class="btn btn-primary" type="submit">{{ $isClient ? 'Submit Payment Evidence' : 'Save Payment' }}</button>
                    </footer>
                </form>
            @endif
        @endif
    </div>
</x-app-layout>
