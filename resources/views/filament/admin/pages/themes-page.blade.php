<x-filament-panels::page>
    @php($themes = $this->getThemes())
    @php($invalidThemes = $this->getInvalidThemes())
    @php($activeCount = collect($themes)->where('isActive', true)->count())
    {{-- Delete is only ever offered when more than one valid theme exists, so the
         CMS can never be left with zero themes (the installer re-checks too). --}}
    @php($canDelete = count($themes) > 1)

    {{-- Scoped styles: a <style> block always applies, unlike arbitrary Tailwind
         utilities which are not part of the panel's precompiled stylesheet. The
         .dark selectors match Filament's class-based dark mode on <html>. --}}
    <style>
        .tn-themes-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 640px) { .tn-themes-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .tn-themes-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        .tn-theme-card {
            display: flex; flex-direction: column; overflow: hidden;
            border-radius: 0.75rem; border: 1px solid rgba(17, 24, 39, 0.1);
            background: #ffffff; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: box-shadow .15s ease, border-color .15s ease;
        }
        .tn-theme-card:hover { box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08); }
        .dark .tn-theme-card { background: rgba(255, 255, 255, 0.02); border-color: rgba(255, 255, 255, 0.1); }
        .tn-theme-card.is-active { border-width: 2px; border-color: rgb(34, 197, 94); }

        .tn-theme-shot { position: relative; width: 100%; aspect-ratio: 16 / 9; overflow: hidden; background: #f3f4f6; }
        .dark .tn-theme-shot { background: rgba(255, 255, 255, 0.05); }
        .tn-theme-shot img { display: block; width: 100%; height: 100%; object-fit: cover; }

        .tn-theme-placeholder {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: .375rem; width: 100%; height: 100%; color: #9ca3af; font-size: .8125rem; font-weight: 500;
        }

        .tn-theme-check { position: absolute; top: .5rem; right: .5rem; display: flex; }
        .tn-theme-check-dot {
            display: flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem;
            border-radius: 9999px; background: rgb(34, 197, 94); color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25);
        }

        .tn-theme-body { display: flex; flex-direction: column; gap: .625rem; padding: 1rem; flex: 1 1 auto; }
        .tn-theme-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
        .tn-theme-title { margin: 0; font-size: 1rem; font-weight: 600; color: #111827; }
        .dark .tn-theme-title { color: #ffffff; }
        .tn-theme-badges { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; }
        .tn-theme-desc { margin: 0; font-size: .875rem; line-height: 1.4; color: #4b5563; }
        .dark .tn-theme-desc { color: #d1d5db; }
        .tn-theme-meta { font-size: .75rem; color: #6b7280; }
        .dark .tn-theme-meta { color: #9ca3af; }
        .tn-theme-link { color: #2563eb; text-decoration: none; }
        .tn-theme-link:hover { text-decoration: underline; }
        .dark .tn-theme-link { color: #60a5fa; }

        .tn-theme-actions { display: flex; align-items: center; gap: .5rem; margin-top: auto; padding-top: .25rem; }

        .tn-theme-details { margin-top: .25rem; font-size: .75rem; }
        .tn-theme-details summary { cursor: pointer; color: #6b7280; font-weight: 500; list-style: none; }
        .tn-theme-details summary::-webkit-details-marker { display: none; }
        .tn-theme-details summary:hover { color: #374151; text-decoration: underline; }
        .dark .tn-theme-details summary { color: #9ca3af; }
        .dark .tn-theme-details summary:hover { color: #e5e7eb; }
        .tn-theme-dl {
            display: grid; grid-template-columns: 7rem 1fr; gap: .25rem .75rem;
            margin: .625rem 0 0; padding: .625rem; border-radius: .5rem;
            background: rgba(17, 24, 39, 0.03); color: #6b7280;
        }
        .dark .tn-theme-dl { background: rgba(255, 255, 255, 0.04); color: #9ca3af; }
        .tn-theme-dl dt { font-weight: 600; color: #374151; }
        .dark .tn-theme-dl dt { color: #d1d5db; }
        .tn-theme-dl dd { margin: 0; word-break: break-word; }
        .tn-theme-dl code { font-size: .6875rem; }

        .tn-themes-toolbar { display: flex; flex-direction: column; gap: .75rem; }
        @media (min-width: 640px) { .tn-themes-toolbar { flex-direction: row; align-items: center; justify-content: space-between; } }
        .tn-themes-counts { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-size: .8125rem; }
        .tn-themes-search { width: 100%; }
        @media (min-width: 640px) { .tn-themes-search { width: 18rem; } }

        .tn-invalid-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .tn-invalid-table th { text-align: left; padding: .5rem 0; font-weight: 600; }
        .tn-invalid-table td { padding: .625rem 0; border-top: 1px solid rgba(125, 125, 125, 0.2); }
    </style>

    <div x-data="{ search: '' }" class="fi-section-content-ctn" style="display:flex;flex-direction:column;gap:1.5rem;">
        {{-- Toolbar: counts + search --}}
        <div class="tn-themes-toolbar">
            <div class="tn-themes-counts">
                <x-filament::badge color="gray">{{ tn_trans('All themes: :count', ['count' => count($themes)]) }}</x-filament::badge>
                <x-filament::badge color="success">{{ tn_trans('Active: :count', ['count' => $activeCount]) }}</x-filament::badge>
                <x-filament::badge color="warning">{{ tn_trans('Invalid: :count', ['count' => count($invalidThemes)]) }}</x-filament::badge>
            </div>

            @if (count($themes) > 0)
                <div class="tn-themes-search">
                    <x-filament::input.wrapper>
                        <x-slot name="prefix">
                            <x-filament::icon icon="heroicon-m-magnifying-glass" style="width:1rem;height:1rem;color:#9ca3af;" />
                        </x-slot>
                        <x-filament::input type="search" placeholder="{{ tn_trans('Search themes…') }}" x-model="search" />
                    </x-filament::input.wrapper>
                </div>
            @endif
        </div>

        {{-- Theme gallery --}}
        @if (count($themes) === 0)
            <x-filament::section>
                <p style="font-size:.875rem;color:#6b7280;">
                    {!! tn_trans('No valid themes found in the <code>themes/</code> directory. Add a theme folder containing a valid <code>theme.json</code> file (with <code>name</code>, <code>slug</code>, <code>version</code>, <code>author</code>) and refresh this page.') !!}
                </p>
            </x-filament::section>
        @else
            <div class="tn-themes-grid">
                @foreach ($themes as $theme)
                    @php($haystack = strtolower($theme['name'] . ' ' . $theme['author'] . ' ' . $theme['description']))
                    <div
                        x-show="search === '' || @js($haystack).includes(search.toLowerCase().trim())"
                        class="tn-theme-card {{ $theme['isActive'] ? 'is-active' : '' }}"
                    >
                        {{-- Screenshot (16:9) --}}
                        <div class="tn-theme-shot">
                            @if ($theme['screenshot'])
                                <img src="{{ $theme['screenshot'] }}" alt="{{ tn_trans(':name preview', ['name' => $theme['name']]) }}">
                            @else
                                <div class="tn-theme-placeholder">
                                    <x-filament::icon icon="heroicon-o-photo" style="width:2rem;height:2rem;" />
                                    <span>{{ tn_trans('No Preview Available') }}</span>
                                </div>
                            @endif

                            @if ($theme['isActive'])
                                <div class="tn-theme-check" title="{{ tn_trans('Active theme') }}">
                                    <span class="tn-theme-check-dot">
                                        <x-filament::icon icon="heroicon-s-check" style="width:1.125rem;height:1.125rem;" />
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- Body --}}
                        <div class="tn-theme-body">
                            <div class="tn-theme-head">
                                <h3 class="tn-theme-title">{{ $theme['name'] }}</h3>
                                <div class="tn-theme-badges">
                                    <x-filament::badge color="gray" size="sm">v{{ $theme['version'] }}</x-filament::badge>
                                </div>
                            </div>

                            <div class="tn-theme-badges">
                                @if ($theme['isActive'])
                                    <x-filament::badge color="success" size="sm" icon="heroicon-m-check-circle">{{ tn_trans('Active') }}</x-filament::badge>
                                @endif
                                @if ($theme['supportsThemeOptions'])
                                    <x-filament::badge color="info" size="sm" icon="heroicon-m-adjustments-horizontal">{{ tn_trans('Theme options') }}</x-filament::badge>
                                @endif
                            </div>

                            @if ($theme['description'])
                                <p class="tn-theme-desc">{{ $theme['description'] }}</p>
                            @endif

                            <p class="tn-theme-meta">
                                {{ tn_trans('By') }}
                                @if ($theme['authorUri'])
                                    <a href="{{ $theme['authorUri'] }}" target="_blank" rel="noopener noreferrer" class="tn-theme-link">{{ $theme['author'] }}</a>
                                @else
                                    {{ $theme['author'] }}
                                @endif
                            </p>

                            {{-- Optional developer details (native <details>, always works) --}}
                            <details class="tn-theme-details">
                                <summary>{{ tn_trans('View details') }}</summary>
                                <dl class="tn-theme-dl">
                                    <dt>{{ tn_trans('Slug') }}</dt>
                                    <dd><code>{{ $theme['slug'] }}</code></dd>

                                    <dt>{{ tn_trans('Path') }}</dt>
                                    <dd><code>{{ str_replace(base_path() . DIRECTORY_SEPARATOR, '', $theme['path']) }}</code></dd>

                                    <dt>{{ tn_trans('Theme options') }}</dt>
                                    <dd>{{ $theme['supportsThemeOptions'] ? tn_trans('Yes') : tn_trans('No') }}</dd>

                                    @if (! empty($theme['supports']))
                                        <dt>{{ tn_trans('Supports') }}</dt>
                                        <dd><code>{{ json_encode($theme['supports']) }}</code></dd>
                                    @endif

                                    @if (! empty($theme['requires']))
                                        <dt>{{ tn_trans('Requires') }}</dt>
                                        <dd><code>{{ json_encode($theme['requires']) }}</code></dd>
                                    @endif

                                    <dt>{{ tn_trans('Screenshot') }}</dt>
                                    <dd>
                                        @if ($theme['screenshotPath'])
                                            <code>{{ str_replace(base_path() . DIRECTORY_SEPARATOR, '', $theme['screenshotPath']) }}</code>
                                        @else
                                            <span>{{ tn_trans('None') }}</span>
                                        @endif
                                    </dd>
                                </dl>
                            </details>

                            {{-- Actions --}}
                            <div class="tn-theme-actions">
                                @if ($theme['isActive'])
                                    {{-- Active theme: no Activate, no Deactivate — TN CMS always keeps
                                         exactly one active theme; switch by activating another. --}}
                                    <x-filament::button color="success" icon="heroicon-m-check" disabled>
                                        {{ tn_trans('Activated') }}
                                    </x-filament::button>
                                @else
                                    @if (cms_can('themes.activate'))
                                        <x-filament::button
                                            color="primary"
                                            wire:click="activate('{{ $theme['slug'] }}')"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ tn_trans('Activate') }}
                                        </x-filament::button>
                                    @endif

                                    {{-- Delete only for inactive themes, when another valid theme
                                         exists, and the user may delete themes. --}}
                                    @if ($canDelete && cms_can('themes.delete'))
                                        @php($confirmDeleteTheme = tn_trans('Delete theme ":name"? This will permanently delete the theme files from themes/:slug. This cannot be undone.', ['name' => $theme['name'], 'slug' => $theme['slug']]))
                                        <x-filament::button
                                            color="danger"
                                            outlined
                                            icon="heroicon-m-trash"
                                            wire:click="delete('{{ $theme['slug'] }}')"
                                            wire:confirm="{{ $confirmDeleteTheme }}"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ tn_trans('Delete') }}
                                        </x-filament::button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Invalid themes --}}
        @if (count($invalidThemes) > 0)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <x-slot name="heading">{{ tn_trans('Invalid Themes') }}</x-slot>
                <x-slot name="description">
                    {!! tn_trans('These folders were skipped because their <code>theme.json</code> is missing or invalid.') !!}
                </x-slot>

                <table class="tn-invalid-table">
                    <thead>
                        <tr>
                            <th style="width:35%;">{{ tn_trans('Theme / folder') }}</th>
                            <th>{{ tn_trans('Reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invalidThemes as $invalid)
                            <tr>
                                <td style="font-weight:500;">{{ $invalid['slug'] }}</td>
                                <td>{{ $invalid['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
