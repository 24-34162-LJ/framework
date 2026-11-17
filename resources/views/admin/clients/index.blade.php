{{--
    FILE PURPOSE
    File   : resources/views/admin/clients/index.blade.php
    Feature: Admin - Client management
    Purpose: Admin client management page (list + add/edit form).
--}}

<x-app-layout :title="$client?->client_name ?? 'Client Management'" active="clients">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/client_management.css') }}?v=10" rel="stylesheet">
    @endpush
    @push('scripts')<script src="{{ asset('assets/js/client_management.js') }}" defer></script>@endpush

    @php($validationErrors = session('errors')?->getBag('default'))
    <div class="page cm-page">
        @if (session('success'))<div class="cm-flash cm-flash-success" role="status">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="cm-flash cm-flash-error" role="alert">{{ session('error') }}</div>@endif
        @if ($validationErrors?->any())<div class="cm-flash cm-flash-error" role="alert">{{ $validationErrors->first() }}</div>@endif

        @if (! $client)
            <header class="page-head cm-head">
                <div><h1>Client Management</h1></div>
                @if (auth()->user()->role === 'admin')<a class="btn btn-primary cm-add" href="{{ route('admin.clients', ['add' => 1]) }}">＋ Add Client</a>@endif
            </header>

            <section class="cm-stats" aria-label="Client totals">
                <article class="fcard cm-stat"><span>Total Clients</span><strong>{{ number_format($stats['total']) }}</strong></article>
                <article class="fcard cm-stat"><span>Active Clients</span><strong>{{ number_format($stats['active']) }}</strong><small>Available for new shipments</small></article>
                <article class="fcard cm-stat"><span>Inactive Clients</span><strong>{{ number_format($stats['inactive']) }}</strong><small>Historical records preserved</small></article>
            </section>

            <section class="fcard cm-list-card">
                <form class="cm-filters" method="get" action="{{ route('admin.clients') }}">
                    <label class="cm-search"><span class="sr-only">Search clients</span><input name="q" value="{{ $search }}" placeholder="Search company, contact, email, or phone"></label>
                    <label class="sr-only" for="clientStatusFilter">Filter by client status</label>
                    <select name="status" id="clientStatusFilter"><option value="">All statuses</option><option value="active" @selected($statusFilter === 'active')>Active</option><option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option></select>
                    <button class="btn btn-secondary" type="submit">Search</button>
                </form>
                <div class="table-wrap cm-table-wrap"><table class="cm-table">
                    <thead><tr><th>Client ID</th><th>Company Name</th><th>Contact Person</th><th>Email</th><th>Phone</th><th>Active Shipments</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($clientList as $row)
                            @php($code = 'CL-'.str_pad((string) $row->client_id, 4, '0', STR_PAD_LEFT))
                            <tr>
                                <td>{{ $code }}</td><td><a class="cm-company" href="{{ route('admin.clients', ['id' => $row->client_id]) }}">{{ $row->client_name }}</a></td>
                                <td>{{ $row->contact_person ?: '—' }}</td><td>{{ $row->email ?: '—' }}</td><td>{{ $row->phone ?: '—' }}</td>
                                <td>{{ number_format($row->active_shipments) }}</td><td><span class="cm-status cm-status-{{ $row->status }}">{{ ucfirst($row->status) }}</span></td>
                                <td><div class="cm-actions"><a href="{{ route('admin.clients', ['id' => $row->client_id]) }}">View</a>@if (auth()->user()->role === 'admin')<a href="{{ route('admin.clients', ['id' => $row->client_id, 'edit' => 1]) }}">Edit</a>
                                    <form method="post" action="{{ route('admin.clients.save') }}">@csrf<input type="hidden" name="action" value="set_status"><input type="hidden" name="client_id" value="{{ $row->client_id }}"><input type="hidden" name="return_tab" value="overview"><input type="hidden" name="status" value="{{ $row->status === 'active' ? 'inactive' : 'active' }}"><button type="submit" data-confirm="{{ $row->status === 'active' ? 'Deactivate' : 'Reactivate' }} {{ $row->client_name }}? Linked records will be retained.">{{ $row->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
                                @endif</div></td>
                            </tr>
                        @empty
                            <tr><td class="cm-empty" colspan="8"><strong>{{ $search || $statusFilter ? 'No matching clients' : 'No client companies yet' }}</strong><span>{{ $search || $statusFilter ? 'Try changing your search or status filter.' : 'Add a company to organize client accounts and shipments.' }}</span></td></tr>
                        @endforelse
                    </tbody>
                </table></div>
                <footer class="cm-table-foot"><span>Showing {{ $clientList->firstItem() ?? 0 }}–{{ $clientList->lastItem() ?? 0 }} of {{ $clientList->total() }} client{{ $clientList->total() === 1 ? '' : 's' }}</span><nav class="cm-pager" aria-label="Client pages">@if ($clientList->onFirstPage() === false)<a href="{{ $clientList->previousPageUrl() }}">Previous</a>@endif @foreach ($clientList->getUrlRange(max(1, $clientList->currentPage() - 2), min($clientList->lastPage(), $clientList->currentPage() + 2)) as $page => $url)<a class="{{ $page === $clientList->currentPage() ? 'current' : '' }}" href="{{ $url }}" @if ($page === $clientList->currentPage()) aria-current="page" @endif>{{ $page }}</a>@endforeach @if ($clientList->hasMorePages())<a href="{{ $clientList->nextPageUrl() }}">Next</a>@endif</nav></footer>
            </section>
            <p class="cm-footnote">Client accounts are managed in User Management.</p>
        @else
            @php($code = 'CL-'.str_pad((string) $client->client_id, 4, '0', STR_PAD_LEFT))
            <header class="cm-profile-head"><div class="cm-avatar">{{ mb_strtoupper(mb_substr($client->client_name, 0, 1)) }}</div><div class="cm-profile-title"><h1>{{ $client->client_name }}</h1><div><span>Client ID: {{ $code }}</span><span class="cm-status cm-status-{{ $client->status }}">{{ ucfirst($client->status) }}</span></div></div>@if (auth()->user()->role === 'admin')<a class="btn btn-primary" href="{{ route('admin.clients', ['id' => $client->client_id, 'tab' => $tab, 'edit' => 1]) }}">Edit Client</a>@endif</header>
            <nav class="cm-tabs" aria-label="Client profile sections"><a class="{{ $tab === 'overview' ? 'active' : '' }}" href="{{ route('admin.clients', ['id' => $client->client_id, 'tab' => 'overview']) }}">Overview</a><a class="{{ $tab === 'accounts' ? 'active' : '' }}" href="{{ route('admin.clients', ['id' => $client->client_id, 'tab' => 'accounts']) }}">Client Accounts</a><a class="{{ $tab === 'shipments' ? 'active' : '' }}" href="{{ route('admin.clients', ['id' => $client->client_id, 'tab' => 'shipments']) }}">Shipments</a></nav>

            @if ($tab === 'overview')
                <section class="cm-stats cm-profile-stats"><article class="fcard cm-stat"><span>Linked Client Accounts</span><strong>{{ number_format($accountCount) }}</strong><small>Company-linked user accounts</small></article><article class="fcard cm-stat"><span>Active Shipments</span><strong>{{ number_format($activeShipments) }}</strong></article><article class="fcard cm-stat"><span>Completed Shipments</span><strong>{{ number_format($completedShipments) }}</strong></article></section>
                <section class="fcard cm-info-card"><h2>Company Information</h2><dl class="cm-info-grid"><div><dt>Contact Person</dt><dd>{{ $client->contact_person ?: '—' }}</dd></div><div><dt>Email</dt><dd>{{ $client->email ?: '—' }}</dd></div><div><dt>Phone</dt><dd>{{ $client->phone ?: '—' }}</dd></div><div><dt>Address</dt><dd>{{ $client->address ?: '—' }}</dd></div><div><dt>TIN</dt><dd>{{ $client->tin ?: '—' }}</dd></div><div><dt>Date Added</dt><dd>{{ $client->created_at ? \Illuminate\Support\Carbon::parse($client->created_at)->format('M d, Y') : '—' }}</dd></div></dl></section>
                <aside class="cm-callout"><strong>One company. Connected records.</strong><p>This profile connects {{ $client->client_name }} to its client accounts and shipment history. Manage accounts in User Management; linked records remain available if this company becomes inactive.</p></aside>
            @elseif ($tab === 'accounts')
                <section class="fcard cm-record-card"><header><div><h2>Linked Client Accounts</h2><p>{{ $accountCount }} account{{ $accountCount === 1 ? '' : 's' }} linked to {{ $client->client_name }}.</p></div>@if (auth()->user()->role === 'admin')<a class="btn btn-secondary" href="{{ route('admin.users') }}">Manage Accounts in User Management</a>@endif</header><div class="table-wrap"><table class="cm-table"><thead><tr><th>Account Name</th><th>Email</th><th>Account Status</th><th>Date Added</th><th>Actions · Admin</th></tr></thead><tbody>
                    @forelse ($accounts as $account)<tr><td>{{ $account->full_name }}</td><td>{{ $account->email }}</td><td><span class="cm-status cm-status-{{ $account->status }}">{{ ucfirst($account->status) }}</span></td><td>{{ $account->created_at ? \Illuminate\Support\Carbon::parse($account->created_at)->format('M d, Y') : '—' }}</td><td>@if (auth()->user()->role === 'admin')<a href="{{ route('admin.users') }}">Manage account</a>@else<span class="cm-muted">Read-only</span>@endif</td></tr>@empty<tr><td colspan="5" class="cm-empty"><strong>No client accounts linked yet</strong><span>An administrator can link accounts in User Management.</span></td></tr>@endforelse
                </tbody></table></div></section><aside class="cm-callout"><strong>Account access stays in User Management</strong><p>Only admins and authorized staff can access Client Management. Client users see only their own company records in the client portal.</p></aside>
            @else
                <section class="fcard cm-record-card"><header><div><h2>Company Shipments</h2><p>{{ $code }} · {{ $client->client_name }} only</p></div></header>
                    <form class="cm-filters cm-shipment-filter" method="get" action="{{ route('admin.clients') }}"><input type="hidden" name="id" value="{{ $client->client_id }}"><input type="hidden" name="tab" value="shipments"><label class="cm-search"><span class="sr-only">Search this company's shipments</span><input name="sq" value="{{ $shipmentSearch }}" placeholder="Search this company's shipments by reference"></label><label class="sr-only" for="shipmentStatus">Filter shipment status</label><select id="shipmentStatus" name="ss"><option value="">All statuses</option>@foreach ($stages as $stage)<option value="{{ $stage->stage_name }}" @selected($shipmentStatus === $stage->stage_name)>{{ $stage->stage_name }}</option>@endforeach</select><button class="btn btn-secondary" type="submit">Search</button></form>
                    <div class="table-wrap"><table class="cm-table"><thead><tr><th>Shipment Reference</th><th>Created Date</th><th>Type</th><th>Mode</th><th>Current Status</th><th>Last Updated</th><th>Action</th></tr></thead><tbody>
                        @forelse ($shipmentRows as $shipment)
                            @php($badgeClass = match (mb_strtolower((string) ($shipment->stage?->stage_group ?? ''))) {'delivered' => 'green', 'customs processing' => 'blue', 'in transit' => 'teal', 'pending documents' => 'amber', default => 'blue'})
                            <tr><td><a class="cm-company" href="{{ route('shipments.show', $shipment->shipment_id) }}">{{ $shipment->reference_no }}</a>@if ($shipment->archived_at)<small class="cm-muted">Archived {{ $shipment->archived_at->format('M d, Y') }}</small>@endif</td><td>{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</td><td>{{ ucfirst((string) $shipment->transaction_type) }}</td><td>{{ ucfirst((string) $shipment->mode) }}</td><td><span class="badge badge-{{ $badgeClass }}">{{ $shipment->current_status }}</span></td><td>{{ $shipment->status_updates_max_updated_at ? \Illuminate\Support\Carbon::parse($shipment->status_updates_max_updated_at)->format('M d, Y · g:i A') : ($shipment->created_at?->format('M d, Y · g:i A') ?? '—') }}</td><td><a href="{{ route('shipments.show', $shipment->shipment_id) }}">View</a></td></tr>
                        @empty<tr><td colspan="7" class="cm-empty"><strong>No shipments linked yet</strong><span>Shipments linked to this company will appear here.</span></td></tr>@endforelse
                    </tbody></table></div>
                    <footer class="cm-table-foot"><span>Showing {{ $shipmentRows->firstItem() ?? 0 }}–{{ $shipmentRows->lastItem() ?? 0 }} of {{ $shipmentRows->total() }} shipment{{ $shipmentRows->total() === 1 ? '' : 's' }}</span><nav class="cm-pager" aria-label="Shipment pages">@if ($shipmentRows->onFirstPage() === false)<a href="{{ $shipmentRows->previousPageUrl() }}">Previous</a>@endif @if ($shipmentRows->hasMorePages())<a href="{{ $shipmentRows->nextPageUrl() }}">Next</a>@endif</nav></footer>
                </section><p class="cm-footnote">Only this company's shipments are shown. Shipment history remains available if the client becomes inactive.</p>
            @endif
        @endif
    </div>

    @if ($showForm)
        <dialog class="cm-dialog" id="clientDialog" open><form method="post" action="{{ route('admin.clients.save') }}" class="cm-dialog-form">@csrf
            <input type="hidden" name="action" value="save_client"><input type="hidden" name="client_id" value="{{ $client?->client_id ?? '' }}"><input type="hidden" name="return_tab" value="{{ $tab }}">
            <header><div><h2>{{ $client ? 'Edit Client' : 'Add Client' }}</h2><p>{{ $client ? 'Update company details for '.$client->client_name.' · '.$code : 'Create a company record to organize contacts and shipments.' }}</p></div><a href="{{ $client ? route('admin.clients', ['id' => $client->client_id, 'tab' => $tab]) : route('admin.clients') }}" aria-label="Close">×</a></header>
            <div class="cm-dialog-grid"><label>Company Name *<input name="client_name" required maxlength="150" value="{{ $formValues['client_name'] ?? '' }}" placeholder="Enter company name">@if ($validationErrors?->has('client_name'))<small class="cm-validation">{{ $validationErrors->first('client_name') }}</small>@endif</label><label>Contact Person<input name="contact_person" maxlength="100" value="{{ $formValues['contact_person'] ?? '' }}"></label><label>Email<input name="email" type="email" maxlength="120" value="{{ $formValues['email'] ?? '' }}">@if ($validationErrors?->has('email'))<small class="cm-validation">{{ $validationErrors->first('email') }}</small>@endif</label><label>Phone<input name="phone" maxlength="30" value="{{ $formValues['phone'] ?? '' }}" aria-describedby="cmPhoneHint"><small id="cmPhoneHint" class="cm-field-hint">Use a mobile number (09XX…) to receive SMS updates.</small></label><label>Address<input name="address" maxlength="255" value="{{ $formValues['address'] ?? '' }}"></label><label>TIN<input name="tin" maxlength="30" value="{{ $formValues['tin'] ?? '' }}"></label>@if ($client)<label>Status<select name="status"><option value="active" @selected(($formValues['status'] ?? $client->status) === 'active')>Active</option><option value="inactive" @selected(($formValues['status'] ?? $client->status) === 'inactive')>Inactive</option></select></label>@else<input type="hidden" name="status" value="active">@endif</div>
            <aside class="cm-callout"><strong>{{ $client ? 'Linked records stay unchanged' : 'Active by default' }}</strong><p>{{ $client ? 'Editing company details does not alter linked accounts, shipments, billing, transactions, or history. Historical records are retained when a client becomes inactive.' : 'Client accounts are managed in User Management and can be linked to this company there.' }}</p></aside>
            <footer><a class="btn btn-secondary" href="{{ $client ? route('admin.clients', ['id' => $client->client_id, 'tab' => $tab]) : route('admin.clients') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $client ? 'Save Changes' : 'Save Client' }}</button></footer>
        </form></dialog>
    @endif
</x-app-layout>
