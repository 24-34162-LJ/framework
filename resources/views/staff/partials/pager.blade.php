@php($anchorSuffix = filled($anchor ?? null) ? '#'.$anchor : '')
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        @if ($paginator->onFirstPage())<span class="page-btn disabled" aria-hidden="true">‹</span>@else<a class="page-btn" href="{{ $paginator->previousPageUrl() }}{{ $anchorSuffix }}" aria-label="Previous page">‹</a>@endif
        @foreach ($elements as $element)
            @if (is_string($element))<span class="page-gap">{{ $element }}</span>@endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page === $paginator->currentPage())<span class="page-btn active" aria-current="page">{{ $page }}</span>@else<a class="page-btn" href="{{ $url }}{{ $anchorSuffix }}">{{ $page }}</a>@endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())<a class="page-btn" href="{{ $paginator->nextPageUrl() }}{{ $anchorSuffix }}" aria-label="Next page">›</a>@else<span class="page-btn disabled" aria-hidden="true">›</span>@endif
    </nav>
@endif
