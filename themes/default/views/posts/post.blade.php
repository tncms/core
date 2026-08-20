{{-- Default Theme — single post template. --}}
@extends('theme::layouts.master')

@section('title', $title)

@section('content')
    @php($layout = tn_content_layout(theme_option('post_layout', 'right-sidebar')))
    @php($showAuthor = theme_option('show_post_author', true) && ! empty($author))
    @php($showTags = theme_option('show_post_tags', true) && ! empty($tags) && count($tags) > 0)
    @php($showRelated = theme_option('show_related_posts', true) && ! empty($relatedPosts) && count($relatedPosts) > 0)
    <div class="container">
        <div class="tn-content-layout {{ tn_content_layout_class($layout) }}">
            @if ($layout === 'left-sidebar')
                @include('theme::partials.sidebar', ['region' => 'post.sidebar'])
            @endif

            <div class="tn-content-main">
                <article class="entry entry--post">
                    <h1 class="entry-title">{{ $title }}</h1>

                    @if ($publishedAt || $showAuthor)
                        <p class="entry-meta">
                            @if ($publishedAt)
                                <time datetime="{{ $publishedAt->toDateString() }}">{{ $publishedAt->format('d/m/Y') }}</time>
                            @endif
                            @if ($showAuthor)
                                <span class="entry-author">{{ theme_trans('By') }} {{ $author->name }}</span>
                            @endif
                        </p>
                    @endif

                    @if (! empty($featuredImage))
                        <img class="featured-image" src="{{ $featuredImage }}" alt="{{ $title }}">
                    @endif

                    @if (! empty($excerpt))
                        <p class="entry-excerpt">{{ $excerpt }}</p>
                    @endif

                    <div class="entry-content">
                        {!! cms_html($body ?? '') !!}
                    </div>

                    @if ($showTags)
                        <ul class="entry-tags">
                            @foreach ($tags as $tag)
                                <li class="entry-tags__item">
                                    <a class="entry-tags__link" href="{{ term_url($tag) }}">{{ $tag->displayName() }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($showRelated)
                        @php($relatedCount = max(1, (int) theme_option('related_posts_count', 3)))
                        <section class="entry-related" aria-label="{{ theme_trans('Related posts') }}">
                            <h2 class="entry-related__title">{{ theme_trans('Related posts') }}</h2>
                            <ul class="post-list">
                                @foreach (collect($relatedPosts)->take($relatedCount) as $related)
                                    <li class="post-list__item">
                                        <a class="post-list__link" href="{{ content_url($related) }}">{{ $related->translatedTitle() }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </article>
            </div>

            @if ($layout === 'right-sidebar')
                @include('theme::partials.sidebar', ['region' => 'post.sidebar'])
            @endif
        </div>
    </div>
@endsection
