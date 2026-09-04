{{-- Canonical page presentation: rendered whenever a Page selects no template
     (the "Default" choice in the Page editor) or its template is unavailable. --}}
@extends('theme::layouts.master')

@section('content')
    <article class="entry entry--page">
        @if ($showPageTitle ?? true)
            <h1 class="entry-title">{{ $title }}</h1>
        @endif
        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
