{{-- Default Theme — a leaf link inside a mega-menu column (Phase 11B).
     Recurses so nested grandchildren still render (no items lost). Carries the
     same icon/badge/description affordances as the rest of the menu. --}}
@php($meta = is_array($node['meta'] ?? null) ? $node['meta'] : [])
@php($exact = ($node['url'] ?? null) === $currentPath)
@php($hasChildren = ! empty($node['children']))
@php($badge = (is_string($meta['badge'] ?? null) && trim($meta['badge']) !== '') ? trim($meta['badge']) : null)
@php($icon = (is_string($meta['icon'] ?? null) && trim($meta['icon']) !== '') ? trim($meta['icon']) : null)
@php($description = (is_string($meta['description'] ?? null) && trim($meta['description']) !== '') ? trim($meta['description']) : null)
<li class="menu__mega-item">
    <a href="{{ $node['url'] }}"
       class="menu__link"
       @if ($exact) aria-current="page" @endif
       @if (($node['item']->target ?? '_self') === '_blank') target="_blank" rel="noopener" @endif>
        @if ($icon)<span class="menu__icon {{ $icon }}" aria-hidden="true"></span>@endif
        <span class="menu__label">{{ $node['title'] }}</span>
        @if ($badge)<span class="menu__badge">{{ $badge }}</span>@endif
    </a>
    @if ($description)<p class="menu__mega-desc">{{ $description }}</p>@endif

    @if ($hasChildren)
        <ul class="menu__mega-sublist">
            @foreach ($node['children'] as $child)
                @include('theme::partials.menu-mega-link', [
                    'node' => $child,
                    'currentPath' => $currentPath,
                ])
            @endforeach
        </ul>
    @endif
</li>
