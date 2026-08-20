{{--
    Rank item (molecule). Input: $item = { title, url, category, date }, plus
    $rank (int|null, shown when set) and $showRank. Used by the trending-list
    section. Null-safe; renders nothing without a title.
--}}
@php($item = is_array($item ?? null) ? $item : [])
@php($showRank = (bool) ($showRank ?? true))
@php($rank = $rank ?? null)
@php($title = $item['title'] ?? '')
@php($url = is_string($item['url'] ?? null) && $item['url'] !== '' ? $item['url'] : null)
@if (is_string($title) && $title !== '')
    <li class="rank-item">
        @if ($showRank && is_int($rank))
            <span class="rank-item__rank" aria-hidden="true">{{ $rank }}</span>
        @endif
        <div class="rank-item__body">
            <h3 class="rank-item__title">
                @if ($url)
                    <a href="{{ $url }}">{{ $title }}</a>
                @else
                    {{ $title }}
                @endif
            </h3>
            @php($category = $item['category'] ?? '')
            @php($date = $item['date'] ?? '')
            @if ((is_string($category) && $category !== '') || (is_string($date) && $date !== ''))
                <p class="rank-item__meta">
                    @if (is_string($category) && $category !== '')
                        <span class="rank-item__category">{{ $category }}</span>
                    @endif
                    @if (is_string($date) && $date !== '')
                        <time class="rank-item__date">{{ $date }}</time>
                    @endif
                </p>
            @endif
        </div>
    </li>
@endif
