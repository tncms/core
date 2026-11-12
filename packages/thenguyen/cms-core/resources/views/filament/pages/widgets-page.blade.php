<x-filament-panels::page>
    {{--
        Widgets admin UI (v1.0.0-beta.7.1.2).

        The layout is driven by SCOPED CSS (the `.tn-widget-*` classes below), not by
        arbitrary Tailwind utilities. The admin panel only ships Filament's own CSS
        bundle (`fi-*` classes); app/package Blade Tailwind utilities such as
        `grid-cols-10` or `md:col-span-3` are NOT compiled into that bundle, which is
        why the previous version rendered as raw stacked text. Filament action
        components (buttons / icons) are still used — those rely on `fi-*` classes that
        do exist in the bundle.
    --}}
    @verbatim
        <style>
            [x-cloak] { display: none !important; }

            .tn-widget-page {
                --tnw-panel-bg: #ffffff;
                --tnw-header-bg: #f9fafb;
                --tnw-border: #e5e7eb;
                --tnw-border-strong: #d1d5db;
                --tnw-text: #111827;
                --tnw-muted: #6b7280;
                /* CORE-H-Z1-REMEDIATION-R1 H-C2 (WCAG 1.4.3): --tnw-faint is used for text (group labels,
                   area slugs, empty states, hints); #9ca3af was 2.42-2.53:1. gray-500 is >= 4.63:1 on #fff / #f9fafb. */
                --tnw-faint: #6b7280;
                /* H-Z1-M4 (WCAG 1.4.3): badge text on the #f3f4f6 pill; --tnw-muted is 4.39:1 there, this is 6.87:1. */
                --tnw-badge-text: #4b5563;
                --tnw-icon-bg: #f3f4f6;
                --tnw-hover: #f9fafb;
                --tnw-editor-bg: #f9fafb;
                --tnw-success-bg: #ecfdf5;
                --tnw-success-text: #047857;
                --tnw-radius: 12px;
                color: var(--tnw-text);
            }

            .dark .tn-widget-page {
                --tnw-panel-bg: rgb(17 24 39);
                --tnw-header-bg: rgba(255, 255, 255, 0.04);
                --tnw-border: rgba(255, 255, 255, 0.10);
                --tnw-border-strong: rgba(255, 255, 255, 0.16);
                --tnw-text: #ffffff;
                --tnw-muted: #9ca3af;
                /* H-C2: #6b7280 was 3.32-3.66:1 on the dark panel; gray-400 is >= 6.3:1. */
                --tnw-faint: #9ca3af;
                --tnw-badge-text: #9ca3af;
                --tnw-icon-bg: rgba(255, 255, 255, 0.08);
                --tnw-hover: rgba(255, 255, 255, 0.04);
                --tnw-editor-bg: rgba(255, 255, 255, 0.04);
                --tnw-success-bg: rgba(16, 185, 129, 0.14);
                --tnw-success-text: #34d399;
            }

            /* ---- Layout ---- */
            .tn-widget-layout {
                display: grid;
                grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
                gap: 24px;
                align-items: start;
            }
            @media (max-width: 1280px) {
                .tn-widget-layout { grid-template-columns: minmax(260px, 35%) minmax(0, 1fr); }
            }
            @media (max-width: 1024px) {
                .tn-widget-layout { grid-template-columns: 1fr; }
            }

            .tn-widget-sidebar { display: flex; flex-direction: column; gap: 16px; }
            .tn-widget-areas { display: flex; flex-direction: column; gap: 16px; }

            /* ---- Panel (generic card container) ---- */
            .tn-widget-panel {
                background: var(--tnw-panel-bg);
                border: 1px solid var(--tnw-border);
                border-radius: var(--tnw-radius);
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
                overflow: hidden;
            }
            .tn-widget-panel-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                width: 100%;
                padding: 14px 16px;
                background: transparent;
                border: 0;
                text-align: left;
                cursor: default;
            }
            button.tn-widget-panel-header { cursor: pointer; }
            .tn-widget-panel-title { font-size: 0.875rem; font-weight: 600; color: var(--tnw-text); }
            .tn-widget-panel-subtitle { font-size: 0.75rem; color: var(--tnw-muted); margin-top: 2px; }
            .tn-widget-panel-body { padding: 0 16px 16px; }
            .tn-widget-chevron { color: var(--tnw-faint); flex-shrink: 0; transition: transform 0.15s ease; }

            /* ---- Form controls ---- */
            .tn-widget-input,
            .tn-widget-select,
            .tn-widget-textarea {
                display: block;
                width: 100%;
                padding: 8px 10px;
                font-size: 0.875rem;
                color: var(--tnw-text);
                background: var(--tnw-panel-bg);
                border: 1px solid var(--tnw-border-strong);
                border-radius: 8px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .tn-widget-textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; resize: vertical; }
            .tn-widget-input:focus,
            .tn-widget-select:focus,
            .tn-widget-textarea:focus {
                outline: none;
                border-color: rgb(245 158 11);
                box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25);
            }
            .tn-widget-label { display: block; font-size: 0.75rem; font-weight: 500; color: var(--tnw-muted); margin-bottom: 4px; }
            .tn-widget-field { margin-bottom: 12px; }
            .tn-widget-hint { font-size: 0.72rem; color: var(--tnw-faint); margin-top: 4px; }

            /* ---- Palette (available widgets) ---- */
            .tn-widget-palette-group { margin-top: 14px; }
            .tn-widget-palette-group:first-child { margin-top: 4px; }
            .tn-widget-palette-group-label {
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: var(--tnw-faint);
                margin-bottom: 8px;
            }
            .tn-widget-palette-list { display: flex; flex-direction: column; gap: 8px; }
            .tn-widget-palette-card {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                padding: 10px 12px;
                border: 1px solid var(--tnw-border);
                border-radius: 10px;
                transition: background 0.15s ease, border-color 0.15s ease;
            }
            .tn-widget-palette-card:hover { background: var(--tnw-hover); border-color: var(--tnw-border-strong); }
            .tn-widget-palette-main { display: flex; gap: 10px; min-width: 0; }
            .tn-widget-palette-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                width: 32px;
                height: 32px;
                border-radius: 8px;
                background: var(--tnw-icon-bg);
                color: var(--tnw-muted);
            }
            .tn-widget-palette-icon svg { width: 16px; height: 16px; }
            .tn-widget-palette-text { min-width: 0; }
            .tn-widget-palette-title { display: block; font-size: 0.875rem; font-weight: 600; color: var(--tnw-text); }
            .tn-widget-palette-description { display: block; font-size: 0.75rem; color: var(--tnw-muted); margin-top: 1px; }
            .tn-widget-palette-empty { font-size: 0.875rem; color: var(--tnw-muted); }

            /* ---- Presets ---- */
            .tn-widget-presets { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            @media (max-width: 480px) { .tn-widget-presets { grid-template-columns: 1fr; } }
            .tn-widget-preset {
                display: flex;
                flex-direction: column;
                border: 1px solid var(--tnw-border);
                border-radius: 10px;
                padding: 12px;
            }
            .tn-widget-preset-preview {
                display: flex;
                align-items: center;
                justify-content: center;
                height: 56px;
                border-radius: 8px;
                background: var(--tnw-icon-bg);
                color: var(--tnw-faint);
                margin-bottom: 10px;
            }
            .tn-widget-preset-preview svg { width: 24px; height: 24px; }
            .tn-widget-preset-title { font-size: 0.875rem; font-weight: 600; color: var(--tnw-text); }
            .tn-widget-preset-meta { font-size: 0.72rem; color: var(--tnw-muted); margin: 2px 0 10px; flex: 1; }

            /* ---- Export / import ---- */
            .tn-widget-io-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
            .tn-widget-io-file {
                display: block;
                width: 100%;
                font-size: 0.75rem;
                color: var(--tnw-muted);
            }

            /* ---- Widget areas ---- */
            .tn-widget-area-meta { display: flex; align-items: center; gap: 8px; margin-top: 3px; }
            .tn-widget-area-slug {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 0.7rem;
                color: var(--tnw-faint);
            }
            .tn-widget-area-count { font-size: 0.7rem; color: var(--tnw-muted); }
            .tn-widget-area-body { display: flex; flex-direction: column; gap: 10px; padding: 0 16px 16px; min-height: 48px; }

            /* ---- Widget instance rows ---- */
            .tn-widget-instance {
                border: 1px solid var(--tnw-border);
                border-radius: 10px;
                background: var(--tnw-panel-bg);
                transition: opacity 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
            }
            {{-- H-Z1-M4 (WCAG 1.4.3): dimming the whole row pulled its text below 4.5:1. The "Disabled" badge text and the
                 dashed border carry the state; only the decorative (aria-hidden) type icon is dimmed. --}}
            .tn-widget-instance.is-inactive { border-style: dashed; }
            .tn-widget-instance.is-inactive .tn-widget-instance-icon { opacity: 0.6; }
            .tn-widget-instance.is-dragging { opacity: 0.65; transform: scale(1.01); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12); }
            .tn-widget-instance-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                {{-- R3: the move controls widen the actions; on a narrow viewport they wrap under the title (WCAG 1.4.10 / 1.4.12). --}}
                flex-wrap: wrap;
                gap: 12px;
                padding: 10px 12px;
            }
            .tn-widget-instance-main { display: flex; align-items: center; gap: 10px; min-width: 0; }
            .tn-widget-instance-handle { color: var(--tnw-faint); cursor: grab; flex-shrink: 0; }
            .tn-widget-instance-handle:active { cursor: grabbing; }
            .tn-widget-instance-handle svg { width: 16px; height: 16px; }
            .tn-widget-instance-icon { color: var(--tnw-muted); flex-shrink: 0; }
            .tn-widget-instance-icon svg { width: 16px; height: 16px; }
            .tn-widget-instance-text { min-width: 0; }
            .tn-widget-instance-title {
                display: block;
                font-size: 0.875rem;
                font-weight: 600;
                color: var(--tnw-text);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .tn-widget-instance-description {
                display: block;
                font-size: 0.72rem;
                color: var(--tnw-muted);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .tn-widget-instance-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
            {{-- R3 (2.5.8): Filament's -6px icon-button margin made the row's 32px targets overlap by 8px; the gap now separates them. --}}
            .tn-widget-instance-actions .fi-icon-btn { margin: 0; }
            .tn-widget-move-area { position: relative; display: inline-flex; }
            .tn-widget-move-list {
                position: absolute;
                inset-inline-end: 0;
                top: calc(100% + 4px);
                z-index: 30;
                min-width: 12rem;
                max-width: min(20rem, calc(100vw - 2rem));
                max-height: 16rem;
                overflow-y: auto;
                margin: 0;
                padding: 4px;
                list-style: none;
                background: var(--tnw-panel-bg);
                border: 1px solid var(--tnw-border-strong);
                border-radius: 8px;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            }
            .tn-widget-move-option {
                display: block;
                width: 100%;
                min-height: 32px;
                padding: 6px 10px;
                border-radius: 6px;
                text-align: start;
                font-size: 0.875rem;
                color: var(--tnw-text);
                overflow-wrap: anywhere;
            }
            .tn-widget-move-option:hover { background: var(--tnw-hover); }
            .tn-widget-move-option:focus-visible { outline: 2px solid var(--primary-600); outline-offset: -2px; }
            .dark .tn-widget-move-option:focus-visible { outline-color: var(--primary-400); }
            .tn-widget-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 0.65rem;
                font-weight: 600;
                background: var(--tnw-icon-bg);
                color: var(--tnw-badge-text);
                white-space: nowrap;
            }
            .tn-widget-badge.is-active { background: var(--tnw-success-bg); color: var(--tnw-success-text); }

            /* ---- Empty state ---- */
            .tn-widget-empty {
                border: 1px dashed var(--tnw-border-strong);
                border-radius: 10px;
                padding: 18px 12px;
                text-align: center;
                font-size: 0.875rem;
                color: var(--tnw-faint);
            }

            /* ---- Inline editor ---- */
            .tn-widget-editor {
                border-top: 1px solid var(--tnw-border);
                background: var(--tnw-editor-bg);
                padding: 14px 12px;
                border-radius: 0 0 10px 10px;
            }
            .tn-widget-editor-section { margin-bottom: 14px; }
            .tn-widget-editor-section:last-of-type { margin-bottom: 0; }
            .tn-widget-editor-section-title {
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: var(--tnw-faint);
                margin-bottom: 10px;
            }
            .tn-widget-editor-locales { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
            .tn-widget-editor-check { display: flex; align-items: center; gap: 8px; }
            .tn-widget-editor-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                margin-top: 14px;
                padding-top: 12px;
                border-top: 1px solid var(--tnw-border);
            }
        </style>
    @endverbatim

    <div
        x-data="{
            getAfter(container, y) {
                const els = [...container.querySelectorAll('[data-widget-id]:not(.is-dragging)')];
                let result = { offset: Number.NEGATIVE_INFINITY, el: null };
                for (const child of els) {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    if (offset < 0 && offset > result.offset) {
                        result = { offset, el: child };
                    }
                }
                return result.el;
            },
            dragStart(e) {
                e.target.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            },
            dragOver(e) {
                e.preventDefault();
                const list = e.currentTarget;
                const dragging = document.querySelector('.is-dragging');
                if (! dragging) return;
                const after = this.getAfter(list, e.clientY);
                if (after == null) list.appendChild(dragging);
                else list.insertBefore(dragging, after);
            },
            dragEnd(e) {
                const li = e.target;
                li.classList.remove('is-dragging');
                const list = li.closest('[data-area-id]');
                if (! list) return;
                const areaId = parseInt(list.dataset.areaId);
                const ids = [...list.querySelectorAll('[data-widget-id]')].map(x => parseInt(x.dataset.widgetId));
                this.$wire.moveWidget(parseInt(li.dataset.widgetId), areaId, ids);
            },
        }"
        class="tn-widget-page"
    >
        <div class="tn-widget-layout">
            {{-- ============================ LEFT: Available Widgets ============================ --}}
            <div class="tn-widget-sidebar">
                {{-- Available widgets palette --}}
                <section class="tn-widget-panel">
                    <div class="tn-widget-panel-header">
                        <div>
                            <div class="tn-widget-panel-title">{{ tn_trans('Available Widgets') }}</div>
                            <div class="tn-widget-panel-subtitle">{{ tn_trans('Search, choose a target area, then add widgets.') }}</div>
                        </div>
                    </div>
                    <div class="tn-widget-panel-body">
                        {{-- Search --}}
                        <div class="tn-widget-field">
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="widgetSearch"
                                placeholder="{{ tn_trans('Search widgets…') }}"
                                aria-label="{{ tn_trans('Search widgets') }}"
                                class="tn-widget-input"
                            />
                        </div>

                        {{-- Target area --}}
                        <div class="tn-widget-field">
                            <label for="tn-target-area" class="tn-widget-label">{{ tn_trans('Add new widgets to') }}</label>
                            <select id="tn-target-area" wire:model="targetAreaId" class="tn-widget-select">
                                @foreach ($this->areas as $area)
                                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Grouped widget cards --}}
                        @forelse ($this->groupedWidgets as $group => $widgets)
                            <div class="tn-widget-palette-group">
                                <div class="tn-widget-palette-group-label">{{ tn_trans($group) }}</div>
                                <div class="tn-widget-palette-list">
                                    @foreach ($widgets as $widget)
                                        <div class="tn-widget-palette-card">
                                            <div class="tn-widget-palette-main">
                                                <span class="tn-widget-palette-icon">
                                                    <x-filament::icon :icon="$widget['icon'] ?: 'heroicon-o-squares-2x2'" />
                                                </span>
                                                <span class="tn-widget-palette-text">
                                                    <span class="tn-widget-palette-title">{{ tn_trans($widget['name']) }}</span>
                                                    <span class="tn-widget-palette-description">{{ $widget['description'] }}</span>
                                                </span>
                                            </div>
                                            {{-- CORE-H-Z1-REMEDIATION-R1 H-C4: an explicit method-only wire:target (declared before
                                                 wire:click, so Filament uses it for its loading indicator). Copying the click expression
                                                 made Livewire evaluate `targetAreaId` outside $wire scope: ReferenceError on every load. --}}
                                            <x-filament::button
                                                size="xs"
                                                icon="heroicon-m-plus"
                                                wire:target="addWidget"
                                                wire:click="addWidget(targetAreaId, '{{ $widget['type'] }}')"
                                                aria-label="{{ tn_trans('Add :name', ['name' => tn_trans($widget['name'])]) }}"
                                            >
                                                {{ tn_trans('Add') }}
                                            </x-filament::button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <p class="tn-widget-palette-empty">{{ tn_trans('No widgets match your search.') }}</p>
                        @endforelse
                    </div>
                </section>

                {{-- Presets --}}
                @if (count($this->presets) > 0)
                    <section
                        x-data="{ open: false }"
                        class="tn-widget-panel"
                    >
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="tn-presets-body" class="tn-widget-panel-header">
                            <span class="tn-widget-panel-title">{{ tn_trans('Presets') }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="tn-widget-chevron" x-bind:style="open ? 'transform: rotate(180deg)' : ''" />
                        </button>
                        <div id="tn-presets-body" x-show="open" x-collapse x-cloak class="tn-widget-panel-body">
                            <div class="tn-widget-presets">
                                @foreach ($this->presets as $preset)
                                    <div class="tn-widget-preset">
                                        <span class="tn-widget-preset-preview">
                                            <x-filament::icon icon="heroicon-o-rectangle-stack" />
                                        </span>
                                        <span class="tn-widget-preset-title">{{ $preset['name'] }}</span>
                                        <span class="tn-widget-preset-meta">
                                            {{ trans_choice(':count widget|:count widgets', count($preset['widgets'] ?? []), ['count' => count($preset['widgets'] ?? [])]) }}
                                            ·
                                            {{ collect($preset['widgets'])->map(fn ($w) => $this->widgetTypeName($w['type'] ?? ''))->filter()->join(', ') }}
                                        </span>
                                        <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-down-on-square" wire:click="applyPreset('{{ $preset['slug'] }}')">
                                            {{ tn_trans('Import') }}
                                        </x-filament::button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Export / Import --}}
                <section
                    x-data="{ open: false }"
                    class="tn-widget-panel"
                >
                    <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="tn-io-body" class="tn-widget-panel-header">
                        <span class="tn-widget-panel-title">{{ tn_trans('Export / Import') }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="tn-widget-chevron" x-bind:style="open ? 'transform: rotate(180deg)' : ''" />
                    </button>
                    <div id="tn-io-body" x-show="open" x-collapse x-cloak class="tn-widget-panel-body">
                        {{-- Export. H-C4: Blade directives are not compiled inside component-tag attributes, so the former
                             `@js(...)` reached Alpine verbatim (SyntaxError on every load, nothing copied).
                             CORE-H-Z1-REMEDIATION-R2 (R2-5): the JSON is fetched on activation ($wire.clipboardExport(),
                             renderless) instead of being embedded in every render. One request per activation: a click
                             while one is in flight is ignored. The button is never disabled: Filament's button defaults to
                             wire:loading.attr="disabled" for every request of the page, which drops focus (the O-N11 root
                             cause); an attribute given here wins over that default, so while the export loads the button
                             only gets aria-busy and Filament's spinner. Its label shows the state and the polite status
                             region announces the outcome. The clipboard write is started inside the click (a ClipboardItem
                             whose content is the pending request) where the browser supports it, because WebKit rejects a
                             write made after an awaited request has ended the user activation; elsewhere the text is
                             written when it arrives. No clipboard (insecure origin) or a failed request shows the failure state. --}}
                        <div
                            x-data="{
                                state: 'idle',
                                copy() {
                                    if (this.state === 'busy') {
                                        return;
                                    }
                                    this.state = 'busy';
                                    const request = $wire.clipboardExport();
                                    const clipboard = navigator.clipboard;
                                    const written = clipboard && typeof clipboard.write === 'function' && typeof ClipboardItem === 'function'
                                        ? clipboard.write([new ClipboardItem({ 'text/plain': request.then((json) => new Blob([json], { type: 'text/plain' })) })])
                                        : request.then((json) => clipboard.writeText(json));
                                    written
                                        .then(() => { this.state = 'copied'; })
                                        .catch(() => { this.state = 'failed'; })
                                        .finally(() => { setTimeout(() => { if (this.state !== 'busy') this.state = 'idle'; }, 2500); });
                                },
                            }"
                            class="tn-widget-io-row"
                        >
                            <x-filament::button
                                size="xs"
                                color="gray"
                                icon="heroicon-m-clipboard-document"
                                wire:target="clipboardExport"
                                wire:loading.attr="aria-busy"
                                x-on:click="copy()"
                            >
                                <span x-text="{ busy: @js(tn_trans('Copying…')), copied: @js(tn_trans('Copied!')), failed: @js(tn_trans('Copy failed')) }[state] || @js(tn_trans('Copy JSON'))">{{ tn_trans('Copy JSON') }}</span>
                            </x-filament::button>
                            <span class="fi-sr-only" role="status" x-text="{ copied: @js(tn_trans('Widget JSON copied to the clipboard.')), failed: @js(tn_trans('The widget JSON could not be copied.')) }[state] || ''"></span>
                            <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportWidgets">
                                {{ tn_trans('Download JSON') }}
                            </x-filament::button>
                        </div>

                        {{-- Import --}}
                        <div class="tn-widget-field">
                            <label for="tn-import-mode" class="tn-widget-label">{{ tn_trans('Import mode') }}</label>
                            <select id="tn-import-mode" wire:model="importMode" class="tn-widget-select">
                                <option value="append">{{ tn_trans('Append (keep existing)') }}</option>
                                <option value="replace">{{ tn_trans('Replace (clear area first)') }}</option>
                            </select>
                        </div>

                        <div class="tn-widget-field">
                            <label for="tn-import-json" class="tn-widget-label">{{ tn_trans('Paste JSON') }}</label>
                            <textarea
                                id="tn-import-json"
                                rows="4"
                                wire:model="importJson"
                                placeholder="{{ tn_trans('Paste exported widget JSON here') }}"
                                aria-label="{{ tn_trans('Widget JSON to import') }}"
                                class="tn-widget-textarea"
                            ></textarea>
                        </div>

                        <div class="tn-widget-field">
                            <label for="tn-import-file" class="tn-widget-label">{{ tn_trans('Upload JSON file') }}</label>
                            <input
                                id="tn-import-file"
                                type="file"
                                wire:model="importFile"
                                accept=".json,application/json"
                                aria-label="{{ tn_trans('Upload widget JSON file') }}"
                                class="tn-widget-io-file"
                            />
                        </div>

                        <x-filament::button size="xs" icon="heroicon-m-arrow-up-tray" wire:click="importWidgets" wire:confirm="{{ tn_trans('Import widgets using the selected mode?') }}">
                            {{ tn_trans('Import widgets') }}
                        </x-filament::button>
                    </div>
                </section>
            </div>

            {{-- ============================ RIGHT: Widget Areas ============================ --}}
            <div class="tn-widget-areas">
                @forelse ($this->areas as $area)
                    <section
                        x-data="{
                            open: (localStorage.getItem('tncms.widgets.area.{{ $area->slug }}') ?? '1') === '1',
                            toggle() {
                                this.open = ! this.open;
                                localStorage.setItem('tncms.widgets.area.{{ $area->slug }}', this.open ? '1' : '0');
                            },
                        }"
                        class="tn-widget-panel tn-widget-area"
                    >
                        {{-- Area header (collapsible) --}}
                        <button
                            type="button"
                            id="tn-area-toggle-{{ $area->id }}"
                            @click="toggle()"
                            :aria-expanded="open ? 'true' : 'false'"
                            aria-controls="tn-area-{{ $area->id }}"
                            class="tn-widget-panel-header"
                            style="background: var(--tnw-header-bg);"
                        >
                            <span>
                                <span class="tn-widget-panel-title">{{ $area->name }}</span>
                                <span class="tn-widget-area-meta">
                                    <span class="tn-widget-area-slug">{{ $area->slug }}</span>
                                    <span class="tn-widget-area-count">· {{ trans_choice(':count widget|:count widgets', $area->widgets->count(), ['count' => $area->widgets->count()]) }}</span>
                                </span>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="tn-widget-chevron" x-bind:style="open ? 'transform: rotate(180deg)' : ''" />
                        </button>

                        {{-- Sortable widget list --}}
                        <div
                            x-show="open"
                            x-collapse
                            id="tn-area-{{ $area->id }}"
                            data-area-id="{{ $area->id }}"
                            @dragover="dragOver($event)"
                            class="tn-widget-area-body"
                        >
                            @forelse ($area->widgets as $widget)
                                @php($meta = collect($this->groupedWidgets)->flatten(1)->firstWhere('type', $widget->widget_type))
                                {{-- L-2 (H-Z1 pre-rerun): focus destinations for the call-focus reader. Edit switches its own
                                     call (openEditor <-> closeEditor), so it names itself; after Delete the row is gone, so
                                     the next widget's Edit, else the previous one's, else the area header. --}}
                                @php($areaWidgets = $area->widgets->values())
                                @php($afterWidgetDelete = trim(implode(' ', array_filter([$areaWidgets->get($loop->index + 1) ? 'tn-widget-edit-'.$areaWidgets->get($loop->index + 1)->id : null, $loop->index > 0 ? 'tn-widget-edit-'.$areaWidgets->get($loop->index - 1)->id : null, 'tn-area-toggle-'.$area->id]))))
                                <div
                                    data-widget-id="{{ $widget->id }}"
                                    {{-- The whole row is the drag source, EXCEPT while its inline
                                         editor is open: a `draggable="true"` ancestor stops the
                                         browser from focusing / selecting text in the editor inputs,
                                         which made the editor look "dead" (you couldn't type). --}}
                                    draggable="{{ $editingId === $widget->id ? 'false' : 'true' }}"
                                    @dragstart="dragStart($event)"
                                    @dragend="dragEnd($event)"
                                    wire:key="widget-{{ $widget->id }}"
                                    @class([
                                        'tn-widget-instance',
                                        'is-inactive' => ! $widget->is_active,
                                    ])
                                >
                                    <div class="tn-widget-instance-row">
                                        <span class="tn-widget-instance-main">
                                            <span class="tn-widget-instance-handle" aria-hidden="true">
                                                <x-filament::icon icon="heroicon-m-bars-3" />
                                            </span>
                                            <span class="tn-widget-instance-icon" aria-hidden="true">
                                                <x-filament::icon :icon="($meta['icon'] ?? null) ?: 'heroicon-o-squares-2x2'" />
                                            </span>
                                            <span class="tn-widget-instance-text">
                                                <span class="tn-widget-instance-title">{{ $widget->title ?: $this->widgetTypeName($widget->widget_type) }}</span>
                                                <span class="tn-widget-instance-description">{{ $meta['description'] ?? $widget->widget_type }}</span>
                                            </span>
                                        </span>
                                        <span class="tn-widget-instance-actions">
                                            <span @class(['tn-widget-badge', 'is-active' => $widget->is_active])>
                                                {{ $widget->is_active ? tn_trans('Active') : tn_trans('Disabled') }}
                                            </span>
                                            {{-- ER-3 (WCAG 2.1.1 / 2.5.7, CORE-H-Z1-REMEDIATION-R3): the non-drag way to reorder and to
                                                 change the area. After a move, call-focus keeps focus on the same widget's control (same
                                                 wire:click), or on its other move control when this one became disabled. --}}
                                            <x-filament::icon-button
                                                id="tn-widget-up-{{ $widget->id }}"
                                                data-tn-focus-fallback="tn-widget-down-{{ $widget->id }}"
                                                icon="heroicon-m-arrow-up"
                                                wire:target="moveWidgetBy"
                                                wire:click="moveWidgetBy({{ $widget->id }}, -1)"
                                                label="{{ tn_trans('Move :name up', ['name' => $widget->title ?: $this->widgetTypeName($widget->widget_type)]) }}"
                                                :disabled="$loop->first"
                                                color="gray"
                                                size="sm"
                                            />
                                            <x-filament::icon-button
                                                id="tn-widget-down-{{ $widget->id }}"
                                                data-tn-focus-fallback="tn-widget-up-{{ $widget->id }}"
                                                icon="heroicon-m-arrow-down"
                                                wire:target="moveWidgetBy"
                                                wire:click="moveWidgetBy({{ $widget->id }}, 1)"
                                                label="{{ tn_trans('Move :name down', ['name' => $widget->title ?: $this->widgetTypeName($widget->widget_type)]) }}"
                                                :disabled="$loop->last"
                                                color="gray"
                                                size="sm"
                                            />
                                            @if ($this->areas->count() > 1)
                                                {{-- A button disclosure (WCAG 4.1.2): aria-expanded is on the <button>; Filament's dropdown
                                                     would put it on a wrapper <div>. Escape or leaving the list closes it. --}}
                                                <span
                                                    class="tn-widget-move-area"
                                                    x-data="{ open: false }"
                                                    x-on:keydown.escape="if (open) { $event.stopPropagation(); open = false; $refs.trigger.focus() }"
                                                    x-on:click.outside="open = false"
                                                    x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
                                                >
                                                    <x-filament::icon-button
                                                        x-ref="trigger"
                                                        id="tn-widget-area-{{ $widget->id }}"
                                                        aria-expanded="false"
                                                        x-bind:aria-expanded="open.toString()"
                                                        aria-controls="tn-widget-move-list-{{ $widget->id }}"
                                                        x-on:click="open = ! open"
                                                        icon="heroicon-m-arrows-right-left"
                                                        label="{{ tn_trans('Move :name to another area', ['name' => $widget->title ?: $this->widgetTypeName($widget->widget_type)]) }}"
                                                        color="gray"
                                                        size="sm"
                                                    />
                                                    {{-- Created only while open, so a page with many widgets and areas does not build widgets x areas buttons. --}}
                                                    <template x-if="open">
                                                    <ul id="tn-widget-move-list-{{ $widget->id }}" class="tn-widget-move-list" tabindex="-1">
@foreach ($this->areas as $otherArea)
@continue($otherArea->id === $area->id)
<li><button type="button" class="tn-widget-move-option" wire:loading.attr="disabled" data-tn-focus-fallback="tn-widget-area-{{ $widget->id }}" wire:target="moveWidgetToArea" wire:click="moveWidgetToArea({{ $widget->id }}, {{ $otherArea->id }})">{{ $otherArea->name }}</button></li>
@endforeach
                                                    </ul>
                                                    </template>
                                                </span>
                                            @endif
                                            <x-filament::icon-button
                                                id="tn-widget-edit-{{ $widget->id }}"
                                                data-tn-focus-fallback="tn-widget-edit-{{ $widget->id }}"
                                                icon="{{ $editingId === $widget->id ? 'heroicon-m-chevron-up' : 'heroicon-m-pencil-square' }}"
                                                wire:click="{{ $editingId === $widget->id ? 'closeEditor' : 'openEditor(' . $widget->id . ')' }}"
                                                label="{{ tn_trans('Edit') }}"
                                                color="primary"
                                                size="sm"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-m-document-duplicate"
                                                wire:click="duplicateWidget({{ $widget->id }})"
                                                label="{{ tn_trans('Duplicate') }}"
                                                color="gray"
                                                size="sm"
                                            />
                                            <x-filament::icon-button
                                                icon="{{ $widget->is_active ? 'heroicon-m-eye' : 'heroicon-m-eye-slash' }}"
                                                wire:click="toggleWidget({{ $widget->id }})"
                                                label="{{ $widget->is_active ? tn_trans('Disable') : tn_trans('Enable') }}"
                                                :color="$widget->is_active ? 'success' : 'gray'"
                                                size="sm"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-m-trash"
                                                wire:click="deleteWidget({{ $widget->id }})"
                                                data-tn-focus-fallback="{{ $afterWidgetDelete }}"
                                                wire:confirm="{{ tn_trans('Delete this widget?') }}"
                                                label="{{ tn_trans('Delete') }}"
                                                color="danger"
                                                size="sm"
                                            />
                                        </span>
                                    </div>

                                    {{-- Inline editor (no modal). Rendered/removed by the Blade
                                         @if on $editingId. NOTE: do NOT add a bare `x-collapse`
                                         here — without a paired `x-show`, Alpine's collapse plugin
                                         pins the element to height:0/overflow:hidden, so the editor
                                         mounts but is invisible ("Edit opens nothing"). --}}
                                    @if ($editingId === $widget->id)
                                        <div class="tn-widget-editor">
                                            @include('cms::filament.pages.partials.widget-editor')
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="tn-widget-empty">{{-- ER-10 (WCAG 1.3.3): names the controls, not their position, which changes below 1024px. --}}{{ tn_trans('No widgets in this area. To add one, choose this area in “Add new widgets to”, then press Add on a widget under Available Widgets, or drag a widget here.') }}</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <section class="tn-widget-panel">
                        <div class="tn-widget-panel-body" style="padding-top: 16px;">
                            <p class="tn-widget-palette-empty">{{ tn_trans('No widget areas are registered.') }}</p>
                        </div>
                    </section>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
