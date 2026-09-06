@extends('theme::layouts.master')

@section('content')
    <section class="archive">
        <h1 class="entry-title">{{ $title ?? '' }}</h1>

        {{--
            Archive listing consumes ONLY Core-provided data — the paginated
            $posts collection of Content models with their locale translations
            eager-loaded. Titles/URLs use the Core accessors (translatedTitle +
            content_url); the excerpt reads the loaded translation; the featured
            image wraps the stored URL via MediaViewModel. No model query here.
        --}}
        @foreach ($posts ?? [] as $post)
            @php($locale = current_locale())
            @php($cover = \TheNguyen\CMS\View\MediaViewModel::fromUrl((string) ($post->featured_image ?? '')))
            @php($excerpt = optional($post->translations->firstWhere('locale', $locale))->excerpt)
            <article class="archive-item">
                @if ($cover->url !== '')
                    <img class="archive-item__cover" src="{{ $cover->url }}" alt="{{ $post->translatedTitle($locale) }}" loading="lazy">
                @endif
                <h2><a href="{{ content_url($post) }}">{{ $post->translatedTitle($locale) }}</a></h2>
                @if (is_string($excerpt) && $excerpt !== '')
                    <p class="archive-item__excerpt">{{ $excerpt }}</p>
                @endif
            </article>
        @endforeach
    </section>
@endsection
