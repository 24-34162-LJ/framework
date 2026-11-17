{{--
    FILE PURPOSE
    File   : resources/views/admin/activity/index.blade.php
    Feature: Admin - Activity log
    Purpose: Admin "Recent Activity" page.
--}}

<x-app-layout title="Recent Activity" active="activity">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/activity.css') }}?v=9" rel="stylesheet">
    @endpush
    <div class="page report-page activity-page">
        <header class="page-head">
            <div>
                <h1>Recent Activity</h1>
            </div>
            <div class="head-buttons">
                <a class="btn btn-secondary" href="{{ route('admin.activity.shipments') }}">Shipment updates</a>
                <a class="btn btn-secondary" href="{{ route('admin.dashboard') }}">Back to Dashboard</a>
            </div>
        </header>
        <section class="fcard report-records">
            <header>
                <div>
                    <h2>Activity log</h2>
                    <p>{{ number_format($items->total()) }} recorded {{ $items->total() === 1 ? 'event' : 'events' }}</p>
                </div>
            </header>
            <form class="activity-search" method="get" action="{{ route('admin.activity') }}">
                <label for="activity-search-input">Search activity</label>
                <div class="activity-search-controls">
                    <input id="activity-search-input" type="search" name="q" value="{{ $search }}" placeholder="Search client, staff, admin, action, or description">
                    <button class="btn btn-primary" type="submit">Search</button>
                    @if ($search !== '')
                        <a class="btn btn-secondary" href="{{ route('admin.activity') }}">Clear</a>
                    @endif
                </div>
            </form>
            <div class="report-table-wrap">
                <table class="report-table activity-table">
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
                                    {{ $search !== '' ? 'No activity matches your search.' : 'No activity recorded yet.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <footer class="report-pager"><span>Showing {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} of {{ number_format($items->total()) }}</span><nav aria-label="Activity pages">@if ($items->currentPage() > 1)<a href="{{ $items->url(1) }}" aria-label="First page">First</a><a href="{{ $items->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $items->currentPage() }} of {{ $items->lastPage() }}</span>@if ($items->hasMorePages())<a href="{{ $items->nextPageUrl() }}">Next</a><a href="{{ $items->url($items->lastPage()) }}" aria-label="Last page">Last</a>@endif</nav></footer>
        </section>
    </div>
</x-app-layout>
