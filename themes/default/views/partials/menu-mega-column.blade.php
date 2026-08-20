{{-- Default Theme — one mega-menu column (Phase 11B).
     A column is a direct child of a mega parent: its own link becomes the column
     heading, and its children render as a link list below. No toggle buttons —
     on mobile the whole mega panel already sits inside the parent accordion, so
     columns simply stack. Nested grandchildren recurse via menu-mega-link. --}}
@php($meta = is_array($column['meta'] ?? null) ? $column['meta'] : [])
@php($exact = ($column['url'] ?? null) === $currentPath)
@php($hasChildren = ! empty($column['children']))
@php($badge = (is_string($meta['badge'] ?? null) && trim($meta['badge']) !== '') ? trim($meta['badge']) : null)
@php($icon = (is_string($meta['icon'] ?? null) && trim($meta['icon']) !== '') ? trim($meta['icon']) : null)
@php($description = (is_string($meta['description'] ?? null) && trim($meta['description']) !== '') ? trim($meta['description']) : null)
<li class="menu__mega-col">
    <a href="{{ $column['url'] }}"
       class="menu__mega-heading"
       @if ($exact) aria-current="page" @endif
       @if (($column['item']->target ?? '_self') === '_blank') target="_blank" rel="noopener" @endif>
        @if ($icon)<span class="menu__icon {{ $icon }}" aria-hidden="true"></span>@endif
        <span class="menu__label">{{ $column['title'] }}</span>
        @if ($badge)<span class="menu__badge">{{ $badge }}</span>@endif
    </a>
    @if ($description)<p class="menu__mega-desc">{{ $description }}</p>@endif

    @if ($hasChildren)
        <ul class="menu__mega-list">
            @foreach ($column['children'] as $child)
                @include('theme::partials.menu-mega-link', [
                    'node' => $child,
                    'currentPath' => $currentPath,
                ])
            @endforeach
        </ul>
    @endif
</li>
