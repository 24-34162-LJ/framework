{{--
    FILE PURPOSE
    File   : resources/views/client/shipments/index.blade.php
    Feature: Client portal
    Purpose: Client My Shipments list.
--}}

<x-app-layout title="My Shipments" active="shipments" :breadcrumbs="[['Dashboard', route('client.dashboard')], ['My Shipments', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/clint_shipment.css') }}?v=9" rel="stylesheet">
    @endpush
    @push('scripts')<script src="{{ asset('assets/js/clint_shipment.js') }}?v=2" defer></script>@endpush

    <div class="page client-shipments-page">
        <header class="page-head"><div><h1>My Shipments</h1><p class="sub">Track the status of every shipment on your account.</p></div><a class="btn btn-primary" href="{{ route('client.shipment-requests') }}">Request a new shipment</a></header>

        <section class="fcard">
            <nav class="tabs" aria-label="Filter by status">
                <a class="tab {{ $statusKey === '' ? 'active' : '' }}" href="{{ route('client.shipments', request()->except('status', 'page')) }}">All <span class="tab-count">{{ $allCount }}</span></a>
                @foreach ($groups as $group)
                    <a class="tab {{ $statusKey === $group['key'] ? 'active' : '' }}" href="{{ route('client.shipments', array_merge(request()->except('page', 'status'), ['status' => $group['key']])) }}">{{ $group['label'] }} <span class="tab-count">{{ $group['count'] }}</span></a>
                @endforeach
            </nav>

            <form class="toolbar" method="get" action="{{ route('client.shipments') }}" role="search">
                @if ($statusKey !== '')<input type="hidden" name="status" value="{{ $statusKey }}">@endif
                <label class="search-field">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                    <input type="search" name="q" value="{{ $search }}" placeholder="Search by shipment reference or B/L number" aria-label="Search by shipment reference or B/L number">
                </label>
                <select class="select" name="type" data-autosubmit aria-label="Filter by type">
                    @foreach ($typeOptions as $value => $label)<option value="{{ $value }}" @selected($typeKey === $value)>{{ $label }}</option>@endforeach
                </select>
                <button type="submit" class="sr-only">Search</button>
            </form>

            <div class="table-wrap">
                <table class="ship-table">
                    <thead><tr><th>Reference</th><th>Type · Mode</th><th>B/L or AWB</th><th>Date</th><th>Status</th><th>Updated</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @forelse ($shipments as $shipment)
                            <tr>
                                <td data-label="Reference"><a class="row-link" href="{{ route('client.shipments.show', $shipment->shipment_id) }}">{{ $shipment->reference_no }}</a></td>
                                <td data-label="Type · Mode">{{ ucfirst((string) $shipment->transaction_type) }} · {{ ucfirst((string) $shipment->mode) }}</td>
                                <td data-label="B/L or AWB">{{ $shipment->bl_awb_no ?: '—' }}</td>
                                <td data-label="Date">{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</td>
                                <td data-label="Status"><span class="badge badge-{{ $shipment->stage ? match (mb_strtolower((string) $shipment->stage->stage_group)) {'pending documents','on hold' => 'amber','in transit' => 'teal','delivered' => 'green','cancelled' => 'red',default => 'blue'} : 'blue' }}">{{ $shipment->dashboard_status_label }}</span></td>
                                <td data-label="Updated">{{ $shipment->status_updates_max_updated_at ? \Illuminate\Support\Carbon::parse($shipment->status_updates_max_updated_at)->format('M d, Y') : ($shipment->created_at?->format('M d, Y') ?? '—') }}</td>
                                <td data-label="Action"><a class="btn btn-secondary" href="{{ route('client.shipments.show', $shipment->shipment_id) }}">View Details</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="empty">{{ $search !== '' || $statusKey !== '' || $typeKey !== '' ? 'No shipments match these filters.' : 'No shipments have been linked to your account yet.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-foot">
                <span class="soft-text">Showing {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }} of {{ $shipments->total() }} shipments</span>
                {{ $shipments->onEachSide(1)->links('staff.partials.pager', ['anchor' => null]) }}
            </div>
        </section>
    </div>
</x-app-layout>
