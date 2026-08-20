{{-- Default Theme — pagination control for archive listings.
     Receives $paginator (a LengthAwarePaginator) and $elements from
     $paginator->links('theme::partials.pagination'). Styled with theme tokens
     (see assets/css/app.css → .tn-pagination). --}}
@if ($paginator->hasPages())
    <nav class="tn-pagination" role="navigation" aria-label="{{ theme_trans('Pagination') }}">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="tn-pagination__link is-disabled" aria-disabled="true" aria-hidden="true">&laquo;</span>
        @else
            <a class="tn-pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ theme_trans('Previous') }}">&laquo;</a>
        @endif

        {{-- Page numbers + separators --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="tn-pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="tn-pagination__link is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="tn-pagination__link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a class="tn-pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ theme_trans('Next') }}">&raquo;</a>
        @else
            <span class="tn-pagination__link is-disabled" aria-disabled="true" aria-hidden="true">&raquo;</span>
        @endif
    </nav>
@endif
