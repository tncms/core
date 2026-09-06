@extends('theme::layouts.master')

@section('content')
    {{--
        Single post. Every value is supplied by Core (FrontendController) — the
        theme never queries a model. $featuredImage is the stored URL, $excerpt /
        $publishedAt / $author / $tags are provided for posts.
    --}}
    @php($cover = \TheNguyen\CMS\View\MediaViewModel::fromUrl((string) ($featuredImage ?? '')))
    <article class="entry entry--post">
        <h1 class="entry-title">{{ $title }}</h1>

        <p class="entry-meta">
            @if (! empty($author))
                <span class="entry-author">{{ $author->name }}</span>
            @endif
            @if (! empty($publishedAt))
                <time class="entry-date" datetime="{{ $publishedAt->toDateString() }}">{{ $publishedAt->format('Y-m-d') }}</time>
            @endif
            @foreach (($tags ?? []) as $tag)
                <span class="entry-tag">{{ $tag->displayName(current_locale()) }}</span>
            @endforeach
        </p>

        @if ($cover->url !== '')
            <img class="entry-cover" src="{{ $cover->url }}" alt="{{ $title }}">
        @endif

        @if (! empty($excerpt))
            <p class="entry-excerpt">{{ $excerpt }}</p>
        @endif

        <div class="entry-content">
            {!! cms_html($body ?? '') !!}
        </div>
    </article>
@endsection
