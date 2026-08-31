{{-- Default Theme — single page template. --}}
@extends('theme::layouts.master')

@section('title', $title)

@section('content')
    <article class="entry entry--page">
        {{-- Page-title visibility contract (PB-FREE-LIBRARY-DESIGN-1-E-H1). When
             the page opts out, the visible title heading is not rendered at all —
             no empty wrapper, no CSS-hidden element — so a Page Builder layout can
             own the single document <h1>. The SEO <title>, canonical URL and
             navigation are unaffected (set elsewhere). Defaults to Show. --}}
        @if ($showPageTitle ?? true)
            <h1 class="entry-title">{{ $title }}</h1>
        @endif

        @if (! empty($featuredImage))
            <img class="featured-image" src="{{ $featuredImage }}" alt="{{ $title }}">
        @endif

        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
