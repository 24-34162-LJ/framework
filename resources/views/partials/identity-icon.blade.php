{{--
    FILE PURPOSE
    File   : resources/views/partials/identity-icon.blade.php
    Feature: ID Recognition
    Purpose: Small SVG icons used on the ID verification pages.
--}}

{{-- Stroke icons for ID Verification, same 24px / 1.8 stroke style as the sidebar icons. --}}
<svg class="idv-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    @switch($name)
        @case('pass')
            <circle cx="12" cy="12" r="9" />
            <path d="M8 12.5l2.7 2.7L16.2 9.5" />
        @break

        @case('warn')
            <path d="M12 3.5L21.5 20h-19L12 3.5z" />
            <path d="M12 10v4.5M12 17.2v.3" />
        @break

        @case('fail')
            <circle cx="12" cy="12" r="9" />
            <path d="M9 9l6 6M15 9l-6 6" />
        @break

        @case('info')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5.5M12 7.8v.3" />
        @break

        @case('back')
            <path d="M15 5l-7 7 7 7" />
        @break

        @case('expand')
            <path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7" />
        @break

        @case('idcard')
            <rect x="3" y="5.5" width="18" height="13" rx="2" />
            <circle cx="8.5" cy="11" r="2" />
            <path d="M5.8 15.5c.5-1.3 1.5-2 2.7-2s2.2.7 2.7 2M14 10h4M14 13.5h3" />
        @break

        @case('face')
            <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
            <circle cx="12" cy="10.5" r="2.8" />
            <path d="M7.8 17c.8-1.8 2.4-2.7 4.2-2.7s3.4.9 4.2 2.7" />
        @break

        @case('shield')
            <path d="M12 3.5l7 2.8v5.3c0 4.3-2.9 7.6-7 8.9-4.1-1.3-7-4.6-7-8.9V6.3l7-2.8z" />
            <path d="M9 12l2.2 2.2L15.3 10" />
        @break

        @case('arrow')
            <path d="M5 12h14M13 6l6 6-6 6" />
        @break

        @case('upload')
            <path d="M12 15V4M7.5 8.5L12 4l4.5 4.5M4.5 15v3a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3" />
        @break

        @case('phone')
            <rect x="6.5" y="2.5" width="11" height="19" rx="2.5" />
            <path d="M10.5 18.5h3" />
        @break

        @case('chevron')
            <path d="M9 6l6 6-6 6" />
        @break
    @endswitch
</svg>
