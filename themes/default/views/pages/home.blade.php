{{--
    Default Theme — homepage.

    Renders the resolved section stack when the controller supplies $sections
    (preset/builder homepage, via HomepageResolver → SectionResolver →
    SectionViewModel). Falls back to a minimal latest-posts list when the
    controller supplies $posts (reading.homepage_display = latest_posts).

    No hardcoded preset/company logic: it loops whatever sections it receives and
    includes the matching theme::sections.{type} view, skipping any without one.
--}}
@extends('theme::layouts.master')

@section('title', $title ?? '')

@section('content')
    @if (isset($sections) && is_iterable($sections))
        <div class="home-sections">
            @foreach ($sections as $section)
                @php($sectionView = 'theme::sections.' . $section->type)

                @if (view()->exists($sectionView))
                    @if (($section->id ?? null) === 's_hero')
                        @include($sectionView, ['section' => $section])
                    @else
                        <div class="container">
                            @include($sectionView, ['section' => $section])
                        </div>
                    @endif
                @endif
            @endforeach
        </div>
    @elseif (isset($posts))
        <div class="container">
            <div class="post-list">
                @forelse ($posts as $post)
                    <article class="post-list__item">
                        <h2 class="post-list__title">
                            <a href="{{ content_url($post) }}">{{ $post->translatedTitle(current_locale()) }}</a>
                        </h2>
                    </article>
                @empty
                    <p class="post-list__empty">{{ theme_trans('No posts yet.') }}</p>
                @endforelse
            </div>
        </div>
    @endif
@endsection
<!-- 
@section('content')
    @if (isset($sections) && is_iterable($sections))
        <div class="home-sections">
            @foreach ($sections as $section)
                @php($sectionView = 'theme::sections.' . $section->type)
                @if (view()->exists($sectionView))
                    @include($sectionView, ['section' => $section])
                @endif
            @endforeach
        </div>
    @elseif (isset($posts))
        <div class="post-list">
            @forelse ($posts as $post)
                <article class="post-list__item">
                    <h2 class="post-list__title">
                        <a href="{{ content_url($post) }}">{{ $post->translatedTitle(current_locale()) }}</a>
                    </h2>
                </article>
            @empty
                <p class="post-list__empty">{{ theme_trans('No posts yet.') }}</p>
            @endforelse
        </div>
    @endif
@endsection
-->