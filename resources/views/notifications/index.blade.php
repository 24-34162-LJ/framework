{{--
    FILE PURPOSE
    File   : resources/views/notifications/index.blade.php
    Feature: Notifications
    Purpose: Notifications list page.
--}}

<x-app-layout title="Notifications" active="notifications">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
    @endpush

    <div class="page notification-page">
        @if (session('success'))<div class="flash" role="status">{{ session('success') }}</div>@endif
        <header class="page-head"><div><h1>Notifications</h1></div>@if ($unreadCount > 0)<form method="post" action="{{ route('notifications.mark-all-read') }}">@csrf<button class="btn btn-secondary" type="submit">Mark all as read</button></form>@endif</header>
        <section class="notification-center-card fcard">
            <nav class="notification-tabs" aria-label="Notification filter"><a class="{{ $filter === 'all' ? 'active' : '' }}" href="{{ route('notifications.index', ['filter' => 'all']) }}">All</a><a class="{{ $filter === 'unread' ? 'active' : '' }}" href="{{ route('notifications.index', ['filter' => 'unread']) }}">Unread <span>{{ $unreadCount }}</span></a></nav>
            @if ($items->isNotEmpty())
                <ul class="notification-list">
                    @foreach ($items as $item)
                        @php($icon = $item->event_type === 'document' ? '▤' : ($item->event_type === 'date' ? '◷' : '↗'))
                        <li class="notification-item {{ $item->read_at ? '' : 'is-unread' }}"><span class="notification-type-icon" aria-hidden="true">{{ $icon }}</span><div class="notification-copy"><div class="notification-item-head"><strong>{{ $item->title }}</strong><time>{{ $item->created_at?->format('M d, Y · g:i A') }}</time></div><p>{{ $item->message }}</p>@if ($item->shipment && auth()->user()->role !== 'client')<a href="{{ route('shipments.show', $item->shipment_id) }}" data-notification-read="{{ $item->notification_id }}">View shipment · {{ $item->shipment->reference_no }}</a>@endif</div>@if (! $item->read_at)<span class="notification-unread-dot" aria-label="Unread"></span>@endif</li>
                    @endforeach
                </ul>
            @else
                <div class="notification-empty"><span aria-hidden="true">◉</span><strong>{{ $filter === 'unread' ? 'You’re all caught up.' : 'No notifications yet.' }}</strong><p>New shipment updates will appear here.</p>@if ($filter === 'unread')<a href="{{ route('notifications.index', ['filter' => 'all']) }}">View all notifications</a>@endif</div>
            @endif
            @if ($items->hasPages())<nav class="notification-pagination" aria-label="Notification pages">@if ($items->currentPage() > 1)<a href="{{ $items->previousPageUrl() }}">Previous</a>@endif<span>Page {{ $items->currentPage() }} of {{ $items->lastPage() }}</span>@if ($items->hasMorePages())<a href="{{ $items->nextPageUrl() }}">Next</a>@endif</nav>@endif
        </section>
    </div>
</x-app-layout>
