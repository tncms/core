{{--
    Story card (molecule). Input: $story = { title, excerpt?, category, url,
    image(MediaViewModel|null), date }, plus $variant ('feature'|'grid'),
    $showMeta, $showExcerpt. Editorial card with an image and an overlaid meta +
    title. Null-safe; renders nothing without a title.
--}}
@php($story = is_array($story ?? null) ? $story : [])
@php($variant = ($variant ?? 'grid') === 'feature' ? 'feature' : 'grid')
@php($showMeta = (bool) ($showMeta ?? true))
@php($showExcerpt = (bool) ($showExcerpt ?? false))
@php($title = $story['title'] ?? '')
@php($url = is_string($story['url'] ?? null) && $story['url'] !== '' ? $story['url'] : null)
@php($image = $story['image'] ?? null)
@if (is_string($title) && $title !== '')
    <article class="story-card story-card--{{ $variant }}">
        <a class="story-card__link" href="{{ $url ?? '#' }}">
            <div class="story-card__media">
                @if ($image instanceof \TheNguyen\CMS\View\MediaViewModel && $image->url !== '')
                    @include('theme::components.media', ['media' => $image])
                @endif
            </div>
            <div class="story-card__overlay">
                @if ($showMeta)
                    @php($category = $story['category'] ?? '')
                    @if (is_string($category) && $category !== '')
                        <span class="story-card__category">{{ $category }}</span>
                    @endif
                @endif
                <h3 class="story-card__title">{{ $title }}</h3>
                @php($excerpt = $story['excerpt'] ?? '')
                @if ($showExcerpt && is_string($excerpt) && $excerpt !== '')
                    <p class="story-card__excerpt">{{ $excerpt }}</p>
                @endif
                @if ($showMeta)
                    @php($date = $story['date'] ?? '')
                    @if (is_string($date) && $date !== '')
                        <time class="story-card__date">{{ $date }}</time>
                    @endif
                @endif
            </div>
        </a>
    </article>
@endif
