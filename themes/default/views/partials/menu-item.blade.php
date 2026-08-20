{{-- Default Theme — single navigation node (recursive).
     Expects: $node (menu tree node), $currentPath (string), $isNodeActive (closure).

     $isNodeActive returns true for the node OR any descendant matching the URL,
     so an ancestor of the current page is highlighted; aria-current="page" is
     reserved for the exact match only.

     Phase 11B — mega: when meta.display=mega AND the node has children, the
     panel renders as a multi-column mega grid; otherwise it falls back to the
     classic 11A dropdown. Missing/invalid metadata → classic dropdown, so
     existing menus render unchanged. All metadata is read from the pre-built
     tree (no DB queries here). --}}
@php($hasChildren = ! empty($node['children']))
@php($active = $isNodeActive($node))
@php($exact = ($node['url'] ?? null) === $currentPath)
@php($meta = is_array($node['meta'] ?? null) ? $node['meta'] : [])
@php($display = in_array(($meta['display'] ?? 'normal'), ['normal', 'dropdown', 'mega'], true) ? ($meta['display'] ?? 'normal') : 'normal')
{{-- Phase 11C — mega source: children (11B) or a widget area. Widget content is
     fetched once here via the core widget_area() renderer (the only DB access
     allowed in this template); an empty area falls back to children, and a mega
     with neither falls back to a plain link. --}}
{{-- Phase 11D adds post-driven sources: their card list is pre-resolved in core
     and arrives on $node['mega']['items'] (no query here). Meta is already
     normalized upstream, so read mega_source directly. Fallback order for a mega
     item: dynamic items -> widget area -> children -> plain link. --}}
@php($megaSource = is_string($meta['mega_source'] ?? null) ? $meta['mega_source'] : 'children')
@php($megaItems = is_array($node['mega']['items'] ?? null) ? $node['mega']['items'] : [])
@php($showDynamic = $display === 'mega' && in_array($megaSource, ['latest_posts', 'categories', 'tags', 'manual_posts'], true) && count($megaItems) > 0)
@php($megaWidgetArea = (is_string($meta['mega_widget_area'] ?? null) && trim($meta['mega_widget_area']) !== '') ? trim($meta['mega_widget_area']) : null)
@php($useWidget = $display === 'mega' && $megaSource === 'widget_area' && $megaWidgetArea !== null)
@php($widgetHtml = $useWidget ? widget_area($megaWidgetArea) : '')
@php($showWidget = $useWidget && trim($widgetHtml) !== '')
@php($isMega = $display === 'mega' && ($showDynamic || $hasChildren || $showWidget))
@php($hasPanel = $hasChildren || $showWidget || $showDynamic)
@php($badge = (is_string($meta['badge'] ?? null) && trim($meta['badge']) !== '') ? trim($meta['badge']) : null)
@php($icon = (is_string($meta['icon'] ?? null) && trim($meta['icon']) !== '') ? trim($meta['icon']) : null)
<li @class([
    'menu__item',
    'has-children' => $hasPanel,
    'menu__item--mega' => $isMega,
    'is-active' => $active,
])>
    <a href="{{ $node['url'] }}"
       class="menu__link"
       @if ($exact) aria-current="page" @endif
       @if (($node['item']->target ?? '_self') === '_blank') target="_blank" rel="noopener" @endif>
        @if ($icon)<span class="menu__icon {{ $icon }}" aria-hidden="true"></span>@endif
        <span class="menu__label">{{ $node['title'] }}</span>
        @if ($badge)<span class="menu__badge">{{ $badge }}</span>@endif
    </a>

    @if ($hasPanel)
        @php($submenuId = 'submenu-' . $node['item']->id)
        <button type="button"
                class="menu__toggle"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="{{ $submenuId }}"
                data-submenu-toggle>
            <span class="menu__toggle-icon" aria-hidden="true"></span>
            <span class="sr-only">{{ $node['title'] }}</span>
        </button>

        @if ($isMega)
            @php($cols = in_array((int) ($meta['mega_columns'] ?? 4), [2, 3, 4, 5, 6], true) ? (int) $meta['mega_columns'] : 4)
            @php($width = in_array(($meta['mega_width'] ?? 'wide'), ['content', 'wide', 'full'], true) ? ($meta['mega_width'] ?? 'wide') : 'wide')
            <div class="menu__sub menu__mega menu__mega--{{ $width }}"
                 id="{{ $submenuId }}"
                 data-submenu
                 style="--mega-cols: {{ $cols }}">
                @if ($showDynamic)
                    @include('theme::partials.menu-mega-dynamic', [
                        'items' => $megaItems,
                        'currentPath' => $currentPath,
                    ])
                @elseif ($showWidget)
                    @include('theme::partials.menu-mega-widget', ['widgetHtml' => $widgetHtml])
                @else
                    <ul class="menu__mega-grid">
                        @foreach ($node['children'] as $column)
                            @include('theme::partials.menu-mega-column', [
                                'column' => $column,
                                'currentPath' => $currentPath,
                            ])
                        @endforeach
                    </ul>
                @endif
            </div>
        @else
            <ul class="menu menu__sub" id="{{ $submenuId }}" data-submenu>
                @foreach ($node['children'] as $child)
                    @include('theme::partials.menu-item', [
                        'node' => $child,
                        'currentPath' => $currentPath,
                        'isNodeActive' => $isNodeActive,
                    ])
                @endforeach
            </ul>
        @endif
    @endif
</li>
