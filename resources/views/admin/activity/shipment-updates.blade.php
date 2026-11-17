{{--
    FILE PURPOSE
    File   : resources/views/admin/activity/shipment-updates.blade.php
    Feature: Admin - Activity log
    Purpose: Admin "Shipment Updates" page.
--}}

<x-app-layout title="Shipment Updates" active="shipment_activity" :breadcrumbs="[['Dashboard', route('admin.dashboard')], ['Shipment Updates', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/activity.css') }}?v=9" rel="stylesheet">
    @endpush
    <div class="page report-page activity-page">
        <header class="page-head">
            <div>
                <h1>Shipment Updates</h1>
            </div>
            <a class="btn btn-secondary" href="{{ route('admin.dashboard') }}">Back to Dashboard</a>
        </header>
        <section class="fcard report-records">
            <header>
                <div>
                    <h2>Shipment updates</h2>
                    <p>{{ number_format($items->total()) }} recorded {{ $items->total() === 1 ? 'update' : 'updates' }}</p>
                </div>
            </header>
            <form class="activity-search" method="get" action="{{ route('admin.activity.shipments') }}">
                <label for="activity-search-input">Search shipment updates</label>
                <div class="activity-search-controls">
                    <input id="activity-search-input" type="search" name="q" value="{{ $search }}" placeholder="Shipment, status, staff, or remarks">
                    <button class="btn btn-primary" type="submit">Search</button>
                    @if ($search !== '')
                        <a class="btn btn-secondary" href="{{ route('admin.activity.shipments') }}">Clear</a>
                    @endif
                </div>
            </form>
            <div class="report-table-wrap"><table class="report-table activity-table">
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
                            <td>@if ($item->shipment)<a href="{{ route('shipments.show', $item->shipment->shipment_id) }}">{{ $item->shipment->reference_no }}</a>@else—@endif</td>
                            <td>{{ $item->stage }}</td>
                            <td>{{ $item->status }}</td>
                            <td>{{ $item->remarks ?: '—' }}</td>
                            <td>{{ $item->updatedBy?->full_name ?: 'System' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="report-empty">{{ $search !== '' ? 'No shipment activity matches your search.' : 'No shipment activity recorded yet.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
            <footer class="report-pager"><span>Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of {{ number_format($items->total()) }}</span><nav aria-label="Shipment update pages">@if ($items->currentPage() > 1)<a href="{{ $items->url(1) }}" aria-label="First page">First</a><a href="{{ $items->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $items->currentPage() }} of {{ $items->lastPage() }}</span>@if ($items->hasMorePages())<a href="{{ $items->nextPageUrl() }}">Next</a><a href="{{ $items->url($items->lastPage()) }}" aria-label="Last page">Last</a>@endif</nav></footer>
        </section>
    </div>
</x-app-layout>
