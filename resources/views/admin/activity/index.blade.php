{{--
    FILE PURPOSE
    File   : resources/views/admin/activity/index.blade.php
    Feature: Admin - Activity log
    Purpose: Admin "Activity" page: "All activity" and "Shipment updates" tabs, each searchable and paged.
--}}

@php
    $isShipments = $tab === \App\Http\Controllers\Admin\ActivityController::TAB_SHIPMENTS;
    $tabs = [
        'all' => ['All activity', 'Everything people did: sign-ins, accounts, documents, billing and status changes.'],
        'shipments' => ['Shipment updates', 'The shipment status timeline: stage, update, remarks and who made it.'],
    ];
    // Switching tabs keeps the search; paging restarts on the new tab.
    $tabUrl = fn(string $key): string => route(
        'admin.activity',
        array_filter(['tab' => $key === 'all' ? null : $key, 'q' => $search !== '' ? $search : null]),
    );
    $noun = $isShipments ? ['update', 'updates'] : ['event', 'events'];
@endphp

<x-app-layout title="Activity" active="activity" :breadcrumbs="[['Dashboard', route('admin.dashboard')], ['Activity', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=11" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/activity.css') }}?v=10" rel="stylesheet">
    @endpush
    <div class="page report-page activity-page">
        <header class="page-head">
            <div>
                <h1>Activity</h1>
            </div>
            <a class="btn btn-secondary" href="{{ route('admin.dashboard') }}">Back to Dashboard</a>
        </header>

        <nav class="report-tabs activity-tabs" aria-label="Activity lists">
            @foreach ($tabs as $key => [$label])
                <a class="{{ $tab === $key ? 'active' : '' }}" href="{{ $tabUrl($key) }}"
                    @if ($tab === $key) aria-current="page" @endif>{{ $label }} <span
                        class="activity-tab-count">{{ number_format($counts[$key] ?? 0) }}</span></a>
            @endforeach
        </nav>

        <section class="fcard report-records">
            <header>
                <div>
                    <h2>{{ $tabs[$tab][0] }}</h2>
                    <p>{{ $tabs[$tab][1] }} {{ number_format($items->total()) }} {{ $search !== '' ? 'matching' : 'recorded' }}
                        {{ $items->total() === 1 ? $noun[0] : $noun[1] }}.</p>
                </div>
            </header>
            <form class="activity-search" method="get" action="{{ route('admin.activity') }}" role="search">
                @if ($isShipments)
                    <input type="hidden" name="tab" value="shipments">
                @endif
                <label for="activity-search-input">Search {{ mb_strtolower($tabs[$tab][0]) }}</label>
                <div class="activity-search-controls">
                    <input id="activity-search-input" type="search" name="q" value="{{ $search }}"
                        placeholder="{{ $isShipments ? 'Shipment, stage, update, staff, or remarks' : 'Client, staff, admin, action, or description' }}">
                    <button class="btn btn-primary" type="submit">Search</button>
                    @if ($search !== '')
                        <a class="btn btn-secondary"
                            href="{{ route('admin.activity', $isShipments ? ['tab' => 'shipments'] : []) }}">Clear</a>
                    @endif
                </div>
            </form>
            <div class="report-table-wrap">
                <table class="report-table activity-table {{ $isShipments ? 'activity-table-shipments' : '' }}">
                    @if ($isShipments)
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Shipment</th>
                                <th>Stage</th>
                                <th>Update</th>
                                <th>Remarks</th>
                                <th>Updated By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>{{ $item->updated_at?->format('M d, Y · g:i A') ?? '—' }}</td>
                                    <td>
                                        @if ($item->shipment)
                                            <a
                                            href="{{ route('shipments.show', $item->shipment->shipment_id) }}">{{ $item->shipment->reference_no }}</a>@else—
                                        @endif
                                    </td>
                                    <td>{{ $item->stage }}</td>
                                    <td>{{ $item->status }}</td>
                                    <td>{{ $item->remarks ?: '—' }}</td>
                                    <td>{{ $item->updatedBy?->full_name ?: 'System' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="report-empty">
                                        {{ $search !== '' ? 'No shipment updates match your search.' : 'No shipment updates recorded yet.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    @else
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Activity Type</th>
                                <th>Description</th>
                                <th>Performed By</th>
                                <th>Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>{{ $item->created_at?->format('M d, Y · g:i A') ?? '—' }}</td>
                                    <td><span class="activity-type">{{ $item->activity_type }}</span></td>
                                    <td>{{ $item->description }}</td>
                                    <td>{{ $item->performed_by_name ?: 'System' }}</td>
                                    <td>{{ $item->performed_by_role ? ucfirst($item->performed_by_role) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="report-empty">
                                        {{ $search !== '' ? 'No activity matches your search.' : 'No activity recorded yet.' }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    @endif
                </table>
            </div>
            <footer class="report-pager"><span>Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of
                    {{ number_format($items->total()) }}</span>
                <nav aria-label="{{ $tabs[$tab][0] }} pages">
                    @if ($items->currentPage() > 1)
                        <a href="{{ $items->url(1) }}" aria-label="First page">First</a><a
                            href="{{ $items->previousPageUrl() }}">Previous</a>
                    @endif
                    <span>
                        Page {{ $items->currentPage() }} of {{ $items->lastPage() }}</span>
                    @if ($items->hasMorePages())
                        <a href="{{ $items->nextPageUrl() }}">Next</a><a href="{{ $items->url($items->lastPage()) }}"
                            aria-label="Last page">Last</a>
                    @endif
                </nav>
            </footer>
        </section>
    </div>
</x-app-layout>
