{{--
    Default Theme — generic error page (CORE-FRONTEND-1).

    Theme generic tier of the error hierarchy: rendered inside the theme layout
    for any branded status (403/419/429/500/503, and 404 when no specialized
    errors/404 view exists). The FrontendErrorResponder passes pre-sanitized,
    localized values; nothing here exposes exception detail. A theme may add a
    per-status errors/{status}.blade.php to override this for one status.

    Variables: $status, $title, $message, $homeUrl, $searchUrl, $homeLabel,
               $searchLabel
--}}
@extends('theme::layouts.master')

@section('content')
<div class="container tn-error">
    <p class="tn-error-status">{{ $status }}</p>
    <h1 class="tn-error-title">{{ $title }}</h1>
    <p class="tn-error-message">{{ $message }}</p>

    @if (! empty($searchUrl))
        <form method="GET" action="{{ $searchUrl }}" role="search" class="tn-error-search">
            <label class="tn-visually-hidden" for="tn-error-search-input">{{ $searchLabel }}</label>
            <input type="search" id="tn-error-search-input" name="q" placeholder="{{ $searchLabel }}" autocomplete="off">
            <button type="submit">{{ __('Search') }}</button>
        </form>
    @endif

    <p class="tn-error-actions">
        <a href="{{ $homeUrl }}" class="tn-error-home">{{ $homeLabel }}</a>
    </p>
</div>
@endsection
