{{--
    Post card (molecule). Input: $post = { title, excerpt, url, date, category,
    image(MediaViewModel|null) }, and optionally { views, comments, author } when
    the data source supplies them (the standard Editorial Query source does not —
    those render only when present, so the card stays complete and forward-safe).

    Every field is individually toggleable via the booleans below; category/date
    fall back to the legacy combined $showMeta flag for callers that predate the
    granular toggles. Null-safe; renders nothing without a title, and never
    queries — all data arrives pre-resolved.
--}}
@php($post = is_array($post ?? null) ? $post : [])
@php($showImage = (bool) ($showImage ?? true))
@php($showExcerpt = (bool) ($showExcerpt ?? true))
@php($showCategory = (bool) ($showCategory ?? ($showMeta ?? true)))
@php($showDate = (bool) ($showDate ?? ($showMeta ?? true)))
@php($showViews = (bool) ($showViews ?? false))
@php($showComments = (bool) ($showComments ?? false))
@php($showAuthor = (bool) ($showAuthor ?? false))
@php($url = is_string($post['url'] ?? null) && $post['url'] !== '' ? $post['url'] : null)
@php($title = $post['title'] ?? '')
@if (is_string($title) && $title !== '')
    <article class="post-card">
        @php($image = $post['image'] ?? null)
        @if ($showImage && $image instanceof \TheNguyen\CMS\View\MediaViewModel && $image->url !== '')
            <a class="post-card__media" href="{{ $url ?? '#' }}" tabindex="-1" aria-hidden="true">
                @include('theme::components.media', ['media' => $image])
            </a>
        @endif

        <div class="post-card__body">
            @php($category = $post['category'] ?? '')
            @php($date = $post['date'] ?? '')
            @php($author = $post['author'] ?? '')
            @php($hasCategory = $showCategory && is_string($category) && $category !== '')
            @php($hasDate = $showDate && is_string($date) && $date !== '')
            @php($hasAuthor = $showAuthor && is_string($author) && $author !== '')
            @if ($hasCategory || $hasDate || $hasAuthor)
                <p class="post-card__meta">
                    @if ($hasCategory)
                        <span class="post-card__category">{{ $category }}</span>
                    @endif
                    @if ($hasAuthor)
                        <span class="post-card__author">{{ $author }}</span>
                    @endif
                    @if ($hasDate)
                        <time class="post-card__date">{{ $date }}</time>
                    @endif
                </p>
            @endif

            <h3 class="post-card__title">
                @if ($url)
                    <a href="{{ $url }}">{{ $title }}</a>
                @else
                    {{ $title }}
                @endif
            </h3>

            @php($excerpt = $post['excerpt'] ?? '')
            @if ($showExcerpt && is_string($excerpt) && $excerpt !== '')
                <p class="post-card__excerpt">{{ $excerpt }}</p>
            @endif

            @php($views = $post['views'] ?? null)
            @php($comments = $post['comments'] ?? null)
            @php($hasViews = $showViews && is_numeric($views))
            @php($hasComments = $showComments && is_numeric($comments))
            @if ($hasViews || $hasComments)
                <p class="post-card__stats">
                    @if ($hasViews)
                        <span class="post-card__stat post-card__stat--views">{{ number_format((int) $views) }} {{ theme_trans('views') }}</span>
                    @endif
                    @if ($hasComments)
                        <span class="post-card__stat post-card__stat--comments">{{ number_format((int) $comments) }} {{ theme_trans('comments') }}</span>
                    @endif
                </p>
            @endif

            @if ($url)
                <a class="post-card__link" href="{{ $url }}">{{ theme_trans('Read more') }}</a>
            @endif
        </div>
    </article>
@endif
