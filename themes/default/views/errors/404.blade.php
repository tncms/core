{{--
    Default Theme — specialized 404 page (CORE-FRONTEND-1).

    Theme specialized tier of the error hierarchy: a Not Found page that leads
    with the canonical search action so a visitor who hit a dead link can find
    what they were after. Rendered inside the theme layout. Values are
    pre-sanitized and localized by the FrontendErrorResponder.

    Variables: $status, $title, $message, $homeUrl, $searchUrl, $homeLabel,
               $searchLabel
--}}
@extends('theme::layouts.master')

@section('content')
<div class="container tn-error tn-error-404">
    <p class="tn-error-status">{{ $status }}</p>
    <h1 class="tn-error-title">{{ $title }}</h1>
    <p class="tn-error-message">{{ $message }}</p>

    @if (! empty($searchUrl))
        <form method="GET" action="{{ $searchUrl }}" role="search" class="tn-error-search">
            <label class="tn-visually-hidden" for="tn-404-search-input">{{ $searchLabel }}</label>
            <input type="search" id="tn-404-search-input" name="q" placeholder="{{ $searchLabel }}" autocomplete="off">
            <button type="submit">{{ __('Search') }}</button>
        </form>
    @endif

    <p class="tn-error-actions">
        <a href="{{ $homeUrl }}" class="tn-error-home">{{ $homeLabel }}</a>
    </p>
</div>
@endsection
