{{--
    FILE PURPOSE
    File   : resources/views/components/app-layout.blade.php
    Feature: Core
    Purpose: Main page layout used by every app page (head, sidebar, header, content).
--}}

@props([
    'title' => 'AstraTrack',
    'active' => '',
    'breadcrumbs' => [],
    'searchAction' => null,
    'searchPlaceholder' => 'Search',
    'legacyDashboard' => false,
    'description' => '',
    'profileHeader' => false,
    'plainLayout' => false,
    'showNotifications' => true,
])

@php
    $breadcrumbs = $breadcrumbs ?: [['Dashboard', '/dashboard.php'], [$title, null]];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="csrf" content="{{ csrf_token() }}">
    <title>{{ $title }} | AstraTrack</title>
    <script>
    // scroll reveal (assets/js/app_reveal.js): set before the first paint so cards below the fold start hidden;
    // if the reveal never starts, everything shows after 3s. Off under reduced motion.
    (function () {
        var html = document.documentElement;
        if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        html.classList.add('reveal-app');
        setTimeout(function () { if (!window.__appReveal) html.classList.remove('reveal-app'); }, 3000);
    })();
    </script>
    <style>.reveal-app .rv:not(.is-in), .reveal-app:not(.reveal-ready) #mainContent :is(.card, .fcard, .stat, .cm-stat, .edit-card, .review-card, .start-card, .profile-card, .user-card, .users-card, .cm-record-card, .empty-state, .track):not(.is-in) { opacity: 0; }</style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400..700&display=swap" rel="stylesheet">
    @stack('styles')
    <link href="{{ asset('assets/css/navbar.css') }}?v=9" rel="stylesheet">
    <link href="{{ asset('assets/css/notifications.css') }}?v=9" rel="stylesheet">
    <link href="{{ asset('assets/css/sidebar.css') }}?v=18" rel="stylesheet">
</head>
<body class="fig">
<a class="skip-link" href="#mainContent">Skip to main content</a>
<div class="layout">
    <x-sidebar :active="$active" />

    @if ($legacyDashboard)
        <main class="main" id="mainContent" tabindex="-1">
            <x-page-header :title="$title" :description="$description" :search-action="$searchAction" :search-placeholder="$searchPlaceholder" :show-notifications="$showNotifications" legacy-dashboard />
            {{ $slot }}
        </main>
    @elseif ($profileHeader)
        <main class="main profile-main" id="mainContent" tabindex="-1">
            <x-page-header :title="$title" :description="$description" :show-notifications="$showNotifications" profile-header />
            {{ $slot }}
        </main>
    @elseif ($plainLayout)
        <main class="main" id="mainContent" tabindex="-1">
            {{ $slot }}
        </main>
    @else
        <div class="fig-main">
            <x-page-header :title="$title" :breadcrumbs="$breadcrumbs" :search-action="$searchAction" :search-placeholder="$searchPlaceholder" />
            <main class="content-area" id="mainContent" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    @endif
</div>
<script src="{{ asset('assets/js/sidebar.js') }}?v=2" defer></script>
<script src="{{ asset('assets/js/notifications.js') }}" defer></script>
<script src="{{ asset('assets/js/app_reveal.js') }}?v=1" defer></script>
@stack('scripts')
</body>
</html>
