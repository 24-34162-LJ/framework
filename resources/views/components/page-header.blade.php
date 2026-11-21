{{--
    FILE PURPOSE
    File   : resources/views/components/page-header.blade.php
    Feature: Core
    Purpose: Top header component: breadcrumbs, user menu, bell.
--}}

@props([
    'title' => 'AstraTrack',
    'breadcrumbs' => [],
    'searchAction' => null,
    'searchPlaceholder' => 'Search',
    'legacyDashboard' => false,
    'profileHeader' => false,
    'showNotifications' => true,
    'description' => '',
])

@php
    $user = auth()->user();
    $nameParts = collect(preg_split('/\s+/', trim($user->full_name)))->filter()->take(2);
    $initials = $nameParts->map(fn(string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $unreadCount = $showNotifications ? $user->notifications()->whereNull('read_at')->count() : 0;
    $notificationItems = $showNotifications
        ? $user->notifications()->with('shipment')->orderByDesc('created_at')->orderByDesc('notification_id')->limit(6)->get()
        : collect();
@endphp

@if ($profileHeader)
    <header class="topbar profile-topbar">
        <div class="profile-heading">
            <button class="menu-toggle" id="menuToggle" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false"
                aria-label="Open navigation">☰</button>
            <div>
                <h1>{{ $title }}</h1>
                @if ($description !== '')
                    <p>{{ $description }}</p>
                @endif
            </div>
        </div>
        @if ($showNotifications)
            <div class="topbar-right"><x-notification-menu :user="$user" :unread-count="$unreadCount" :notification-items="$notificationItems" /></div>
        @endif
    </header>
@elseif ($legacyDashboard)
    <header class="topbar">
        <div>
            <button class="menu-toggle" id="menuToggle" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false"
                aria-label="Open navigation">&#9776;</button>
            <h1>{{ $title }}</h1>
            @if ($description !== '')
                <p>{{ $description }}</p>
            @endif
        </div>

        <div class="topbar-right">
            @if ($searchAction)
                <form class="search" action="{{ $searchAction }}" method="get" role="search">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5" />
                        <path d="m20 20-4.2-4.2" />
                    </svg>
                    <input type="text" name="q" value="{{ request()->query('q', '') }}" placeholder="{{ $searchPlaceholder }}"
                        aria-label="{{ $searchPlaceholder }}">
                </form>
            @endif

            @if ($showNotifications)
                <x-notification-menu :user="$user" :unread-count="$unreadCount" :notification-items="$notificationItems" />
            @endif

            {{-- Same name menu as every other page: My Account / My Profile + Logout --}}
            <x-user-menu :user="$user" :initials="$initials" show-role />
        </div>
    </header>
@else
    <header class="topnav">
        <div class="topnav-left">
            <button class="menu-toggle" id="menuToggle" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false"
                aria-label="Open navigation">&#9776;</button>
            <nav class="crumbs" aria-label="Breadcrumb">
                @foreach ($breadcrumbs ?: [['Dashboard', '/dashboard.php'], [$title, null]] as $index => $crumb)
                    @if ($index > 0)
                        <span class="sep" aria-hidden="true">/</span>
                    @endif
                    @if (!empty($crumb[1]))
                        <a href="{{ $crumb[1] }}">{{ $crumb[0] }}</a>
                    @else
                        <span class="here" aria-current="page">{{ $crumb[0] }}</span>
                    @endif
                @endforeach
            </nav>
        </div>

        <div class="topbar-right">
            @if ($searchAction)
                <form class="header-search" action="{{ $searchAction }}" method="get" role="search">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5" />
                        <path d="m20 20-4.2-4.2" />
                    </svg>
                    <input type="search" name="q" placeholder="{{ $searchPlaceholder }}" aria-label="{{ $searchPlaceholder }}">
                </form>
            @endif
            <x-notification-menu :user="$user" :unread-count="$unreadCount" :notification-items="$notificationItems" />
            <x-user-menu :user="$user" :initials="$initials" />
        </div>
    </header>
@endif
