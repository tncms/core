{{-- Default Theme — single page template. --}}
@extends('theme::layouts.master')

@section('title', $title)

@section('content')
    <article class="entry entry--page">
        <h1 class="entry-title">{{ $title }}</h1>

        @if (! empty($featuredImage))
            <img class="featured-image" src="{{ $featuredImage }}" alt="{{ $title }}">
        @endif

        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
