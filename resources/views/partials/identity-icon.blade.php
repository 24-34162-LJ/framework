{{-- Stroke icons for ID Verification, same 24px / 1.8 stroke style as the sidebar icons. --}}
<svg class="idv-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    @switch($name)
        @case('pass')<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16.2 9.5"/>@break
        @case('warn')<path d="M12 3.5L21.5 20h-19L12 3.5z"/><path d="M12 10v4.5M12 17.2v.3"/>@break
        @case('fail')<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>@break
        @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.3"/>@break
        @case('back')<path d="M15 5l-7 7 7 7"/>@break
        @case('expand')<path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/>@break
    @endswitch
</svg>
