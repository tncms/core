{{--
    Platform search page (Phase 3.1.6N-D). Host-owned fallback rendered inside the
    active theme's layout. A theme may override this by shipping "theme::search.index".
    Theme-friendly, no Vue, all output escaped. $page is a SearchPageViewModel.
--}}
@extends('theme::layouts.master')

@section('title', __('Search'))

@section('content')
<div class="container tn-search">
    <h1 class="tn-search-heading">{{ __('Search') }}</h1>

    <form method="GET" action="{{ route('cms.search') }}" role="search" class="tn-search-form">
        <input
            type="search"
            name="q"
            value="{{ $page->keyword }}"
            class="tn-search-input"
            placeholder="{{ __('Search…') }}"
            aria-label="{{ __('Search keyword') }}"
            minlength="{{ $page->minLength }}"
            autocomplete="off"
        >

        <label class="tn-search-scope">
            <span class="tn-visually-hidden">{{ __('Search in') }}</span>
            <select name="scope" class="tn-search-scope-select" aria-label="{{ __('Search in') }}">
                @foreach ($page->scopes as $scope)
                    <option value="{{ $scope->key }}" @selected($page->selectedScope === $scope->key)>{{ $scope->label }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="tn-search-submit">{{ __('Search') }}</button>
    </form>

    @foreach ($page->warnings as $warning)
        <p class="tn-search-warning" role="status">{{ $warning }}</p>
    @endforeach

    @if ($page->tooShort)
        <p class="tn-search-hint">{{ __('Please enter at least :count characters.', ['count' => $page->minLength]) }}</p>
    @elseif ($page->searched)
        @if ($page->hasResults())
            <p class="tn-search-summary">
                {{ __(':total result(s) for ":keyword"', ['total' => $page->total, 'keyword' => $page->keyword]) }}
            </p>

            <ul class="tn-search-results">
                @foreach ($page->results as $result)
                    <li class="tn-search-result">
                        <span class="tn-search-result-type">{{ $result->typeLabel }}</span>

                        @if ($result->url !== null)
                            <a href="{{ $result->url }}" class="tn-search-result-title">{{ $result->title }}</a>
                        @else
                            <span class="tn-search-result-title">{{ $result->title }}</span>
                        @endif

                        @if ($result->excerpt !== '')
                            <p class="tn-search-result-excerpt">{{ $result->excerpt }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($page->hasPagination())
                <nav class="tn-search-pagination" aria-label="{{ __('Search results pages') }}">
                    @if ($page->page > 1)
                        <a rel="prev" class="tn-search-prev" href="{{ request()->fullUrlWithQuery(['page' => $page->page - 1]) }}">{{ __('Previous') }}</a>
                    @endif

                    <span class="tn-search-page-status">{{ __('Page :current of :total', ['current' => $page->page, 'total' => $page->totalPages]) }}</span>

                    @if ($page->page < $page->totalPages)
                        <a rel="next" class="tn-search-next" href="{{ request()->fullUrlWithQuery(['page' => $page->page + 1]) }}">{{ __('Next') }}</a>
                    @endif
                </nav>
            @endif
        @else
            <p class="tn-search-empty">{{ __('No results found for ":keyword".', ['keyword' => $page->keyword]) }}</p>
        @endif
    @else
        <p class="tn-search-prompt">{{ __('Enter a keyword to search.') }}</p>
    @endif
</div>
@endsection
