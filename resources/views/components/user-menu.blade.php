{{--
    FILE PURPOSE
    File   : resources/views/components/user-menu.blade.php
    Feature: Core
    Purpose: Top-right name button: opens a small menu with My Account (My Profile for clients) and Logout.
             Native <details>, so it works without JavaScript; assets/js/user_menu.js closes it on
             outside click or Escape.
--}}

@props(['user', 'initials' => '', 'showRole' => false])

@php
    $isClient = $user->role === 'client';
    $accountUrl = $isClient ? url('/client-management/client_profile.php') : route('account');
    $accountLabel = $isClient ? 'My Profile' : 'My Account';
@endphp

<details class="user-menu" data-user-menu>
    <summary class="profile" aria-label="Account menu for {{ $user->full_name }}">
        <span class="avatar">{{ $initials !== '' ? $initials : 'U' }}</span>
        @if ($showRole)
            <div><strong>{{ $user->full_name }}</strong><small>{{ ucfirst($user->role) }}</small></div>
        @else
            <strong>{{ $user->full_name }}</strong>
        @endif
        <svg class="icon user-menu-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
            stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </summary>
    <div class="user-menu-panel">
        <div class="user-menu-who"><strong>{{ $user->full_name }}</strong><small>{{ $user->email }}</small></div>
        <a class="user-menu-item" href="{{ $accountUrl }}">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5" />
            </svg>
            {{ $accountLabel }}
        </a>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="user-menu-item">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9" />
                </svg>
                Logout
            </button>
        </form>
    </div>
</details>
