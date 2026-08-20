{{-- Default Theme — category/tag/author/archive listing template. --}}
@extends('theme::layouts.master')

@section('title', $title)

@section('content')
    @php($layout = tn_content_layout(theme_option('archive_layout', 'right-sidebar')))
    <div class="container">
        <div class="tn-content-layout {{ tn_content_layout_class($layout) }}">
            @if ($layout === 'left-sidebar')
                @include('theme::partials.sidebar', ['region' => 'archive.sidebar'])
            @endif

            <div class="tn-content-main">
                <section class="archive">
                    <h1 class="archive-title">{{ $title }}</h1>

                    {{-- Term featured image + rich description (taxonomy hierarchy
                         phase). Both are optional and only present for hierarchical
                         taxonomies, so the markup is fully guarded and older terms
                         render exactly as before. Description is sanitized on save. --}}
                    @if (isset($term) && $term && $term->featuredImageUrl())
                        <img class="archive-featured-image"
                             src="{{ $term->featuredImageUrl() }}"
                             alt="{{ $title }}"
                             loading="lazy">
                    @endif

                    @php($archiveDescription = (isset($term) && $term) ? $term->translatedDescription(current_locale()) : '')
                    @if ($archiveDescription !== '')
                        <div class="archive-description">{!! $archiveDescription !!}</div>
                    @endif

                    @if ($posts->isEmpty())
                        <p class="archive-empty">{{ theme_trans('No posts yet.') }}</p>
                    @else
                        <ul class="post-list">
                            @foreach ($posts as $post)
                                <li class="post-list__item">
                                    <a class="post-list__link" href="{{ content_url($post) }}">
                                        {{ $post->translatedTitle('vi') }}
                                    </a>
                                    @if ($post->published_at)
                                        <span class="post-list__date">{{ $post->published_at->format('d/m/Y') }}</span>
                                    @endif
                                    <a class="post-list__more" href="{{ content_url($post) }}">{{ theme_trans('Read more') }}</a>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Paginate term archives. Guarded so the homepage
                             "latest posts" path (which passes a plain Collection)
                             is unaffected. The controller already applied
                             withQueryString(), so links preserve filters/page. --}}
                        @if ($posts instanceof \Illuminate\Pagination\AbstractPaginator)
                            {{ $posts->links('theme::partials.pagination') }}
                        @endif
                    @endif
                </section>
            </div>

            @if ($layout === 'right-sidebar')
                @include('theme::partials.sidebar', ['region' => 'archive.sidebar'])
            @endif
        </div>
    </div>
@endsection
