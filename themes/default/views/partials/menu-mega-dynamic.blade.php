{{-- Default Theme — dynamic mega panel body (Phase 11D).
     Renders pre-resolved post cards from the menu tree (MenuMegaDataProvider):
     latest posts, by categories, by tags, or a manual selection. NOTHING is
     queried here — items arrive ready to render and every field is null-safe.
     On mobile the whole panel already sits inside the parent accordion, so the
     grid simply collapses to a single column via CSS. --}}
<ul class="menu__mega-cards">
    @foreach ($items as $item)
        @php($url = (is_string($item['url'] ?? null) && $item['url'] !== '') ? $item['url'] : null)
        @continue($url === null)
        @php($image = $item['image'] ?? null)
        @php($imageUrl = (is_object($image) && is_string($image->url ?? null) && $image->url !== '') ? $image->url : null)
        @php($category = (is_string($item['category'] ?? null) && trim($item['category']) !== '') ? trim($item['category']) : null)
        @php($excerpt = (is_string($item['excerpt'] ?? null) && trim($item['excerpt']) !== '') ? trim($item['excerpt']) : null)
        @php($date = (is_string($item['date'] ?? null) && $item['date'] !== '') ? $item['date'] : null)
        <li class="menu__mega-card">
            <a href="{{ $url }}"
               class="menu__mega-card-link"
               @if ($url === $currentPath) aria-current="page" @endif>
                @if ($imageUrl)
                    <span class="menu__mega-card-media">
                        <img src="{{ $imageUrl }}" alt="{{ $image->alt ?? '' }}" loading="lazy">
                    </span>
                @endif
                <span class="menu__mega-card-body">
                    @if ($category)<span class="menu__mega-card-cat">{{ $category }}</span>@endif
                    <span class="menu__mega-card-title">{{ $item['title'] ?? '' }}</span>
                    @if ($excerpt)<span class="menu__mega-card-excerpt">{{ $excerpt }}</span>@endif
                    @if ($date)<time class="menu__mega-card-date" datetime="{{ $date }}">{{ $date }}</time>@endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
