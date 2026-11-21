{{--
    FILE PURPOSE
    File   : resources/views/components/notification-menu.blade.php
    Feature: Notifications
    Purpose: Bell menu component in the page header.
--}}

@props(['user', 'unreadCount' => 0, 'notificationItems' => collect()])

<div class="notification-menu" data-notification-menu data-csrf="{{ csrf_token() }}" data-read-url="/operations/notifications_action.php">
    <button class="icon-btn notification-link notification-toggle" type="button"
        aria-label="Notifications{{ $unreadCount > 0 ? ', ' . $unreadCount . ' unread' : '' }}" aria-expanded="false"
        aria-controls="notificationPreview" data-notification-toggle>
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
            stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
        @if ($unreadCount > 0)
            <span class="notification-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>
    <section class="notification-preview" id="notificationPreview" data-notification-preview hidden>
        <header><strong>Notifications</strong><a href="/operations/notifications.php">View all</a>
        </header>
        @if ($notificationItems->isNotEmpty())
            <ul>
                @foreach ($notificationItems as $notice)
                    @php($noticeHref = $notice->shipment_id ? ($user->role === 'client' ? '/client-management/clint_shipment.php?id=' . $notice->shipment_id : '/shipment-management/shipment_view.php?id=' . $notice->shipment_id) : '/operations/notifications.php')
                    <li class="{{ $notice->read_at ? '' : 'is-unread' }}"><a href="{{ $noticeHref }}"
                            data-notification-read="{{ $notice->notification_id }}"><strong>{{ $notice->title }}</strong><span>{{ $notice->message }}</span><small>{{ $notice->created_at?->format('M d, Y · g:i A') }}</small></a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="notification-preview-empty">You are all caught up.</p>
        @endif
        <footer><a href="/operations/notifications.php">Open notification center</a></footer>
    </section>
</div>
