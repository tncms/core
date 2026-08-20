<x-filament-panels::page>
    {{--
        Scoped, local styles. The Filament admin panel ships only its own `fi-*`
        component classes — generic Tailwind utilities (flex/gap/rounded/…) are
        NOT present in the panel stylesheet, so layout must be driven by these
        scoped rules. Filament's button/badge components stay (they are styled);
        we only own the surrounding layout. Colours track Filament tokens and
        flip with the `.dark` class Filament sets on <html>.
    --}}
    <style>
        .tnl { --tnl-border:#e5e7eb; --tnl-surface:#fff; --tnl-text:#111827; --tnl-muted:#6b7280; --tnl-chip:#f3f4f6; }
        .dark .tnl { --tnl-border:rgba(255,255,255,.1); --tnl-surface:rgba(255,255,255,.05); --tnl-text:#fff; --tnl-muted:#9ca3af; --tnl-chip:rgba(255,255,255,.1); }

        /* Toolbar */
        .tnl-toolbar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:1rem; }
        .tnl-toolbar__add { flex:1 1 22rem; min-width:0; }
        .tnl-toolbar__add-row { display:flex; align-items:center; gap:.5rem; }
        .tnl-toolbar__add-row .tnl-select { flex:1 1 auto; }
        .tnl-toolbar__lang { flex:0 1 14rem; min-width:11rem; }
        .tnl-toolbar__actions { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; margin-inline-start:auto; }

        .tnl-label { display:block; margin-bottom:.375rem; font-size:.6875rem; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:var(--tnl-muted); }
        .tnl-select { width:100%; padding:.5rem .625rem; font-size:.875rem; line-height:1.4; border:1px solid var(--tnl-border); border-radius:.5rem; background:var(--tnl-surface); color:var(--tnl-text); }
        .tnl-select:focus { outline:2px solid rgb(var(--primary-500, 245 158 11)); outline-offset:1px; border-color:transparent; }

        /* Section cards */
        .tnl-cards { display:flex; flex-direction:column; gap:.75rem; }
        .tnl-card { display:flex; flex-direction:column; gap:.625rem; padding:1rem 1.25rem; border:1px solid var(--tnl-border); border-radius:.75rem; background:var(--tnl-surface); transition:box-shadow .15s ease; }
        .tnl-card--disabled { border-style:dashed; opacity:.6; }
        .tnl-card--editing { box-shadow:0 0 0 2px rgb(var(--primary-500, 245 158 11)); border-color:transparent; }

        .tnl-meta { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; min-width:0; }
        .tnl-num { display:inline-flex; align-items:center; justify-content:center; height:1.5rem; min-width:1.5rem; padding:0 .4rem; border-radius:999px; background:var(--tnl-chip); color:var(--tnl-muted); font-size:.75rem; font-weight:700; }
        .tnl-title { font-weight:600; color:var(--tnl-text); }
        .tnl-preview { margin:0; max-width:100%; font-size:.875rem; line-height:1.45; color:var(--tnl-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        .tnl-actions { display:flex; flex-flow:row wrap; align-items:center; gap:.375rem; }

        /* Empty state */
        .tnl-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:.25rem; padding:2.5rem 1rem; }
        .tnl-empty__title { margin:0; font-weight:600; color:var(--tnl-text); }
        .tnl-empty__text { margin:0; font-size:.875rem; color:var(--tnl-muted); }

        @media (max-width: 640px) {
            .tnl-toolbar__actions { margin-inline-start:0; width:100%; }
        }
    </style>

    @php($rows = $this->sectionRows())
    @php($multiLocale = $this->hasMultipleLocales())

    {{-- Toolbar: add section · language · save/reset --}}
    <x-filament::section>
        <div class="tnl tnl-toolbar">
            <div class="tnl-toolbar__add">
                <label class="tnl-label" for="tnl-add-type">{{ tn_trans('Add section') }}</label>
                <div class="tnl-toolbar__add-row">
                    <select id="tnl-add-type" wire:model="addType" class="tnl-select">
                        <option value="">{{ tn_trans('Choose a section type…') }}</option>
                        @foreach ($this->sectionTypeGroups() as $category => $types)
                            <optgroup label="{{ \Illuminate\Support\Str::headline($category) }}">
                                @foreach ($types as $type => $label)
                                    <option value="{{ $type }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <x-filament::button wire:click="addSection" icon="heroicon-m-plus">
                        {{ tn_trans('Add') }}
                    </x-filament::button>
                </div>
            </div>

            @if ($multiLocale)
                <div class="tnl-toolbar__lang">
                    <label class="tnl-label" for="tnl-edit-locale">{{ tn_trans('Editing language') }}</label>
                    <select id="tnl-edit-locale" wire:model.live="editLocale" class="tnl-select">
                        @foreach ($this->localeOptions() as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="tnl-toolbar__actions">
                @if ($dirty)
                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                        {{ tn_trans('Unsaved changes') }}
                    </x-filament::badge>
                @endif

                <x-filament::button wire:click="save" color="primary" icon="heroicon-m-check">
                    {{ tn_trans('Save layout') }}
                </x-filament::button>
                <x-filament::button wire:click="resetToPreset" color="danger" outlined icon="heroicon-m-arrow-uturn-left"
                                    wire:confirm="{{ tn_trans('Reset the homepage layout to the active preset blueprint? Your edits will be discarded.') }}">
                    {{ tn_trans('Reset to preset') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- Section cards --}}
    <x-filament::section>
        <x-slot name="heading">{{ tn_trans('Sections') }}</x-slot>
        @if ($rows !== [])
            <x-slot name="description">{{ trans_choice('{1}:count section|[2,*]:count sections', count($rows), ['count' => count($rows)]) }}</x-slot>
        @endif

        @if ($rows === [])
            <div class="tnl tnl-empty">
                <x-filament::icon icon="heroicon-o-squares-2x2" class="fi-icon fi-size-lg" style="color:var(--tnl-muted);width:2rem;height:2rem;" />
                <p class="tnl-empty__title">{{ tn_trans('No sections yet') }}</p>
                <p class="tnl-empty__text">{{ tn_trans('Add your first section to build the homepage.') }}</p>
            </div>
        @else
            <div class="tnl tnl-cards">
                @foreach ($rows as $i => $row)
                    <div @class([
                            'tnl-card',
                            'tnl-card--disabled' => ! $row['enabled'],
                            'tnl-card--editing' => $editingId === $row['id'],
                        ])>
                        {{-- Identity --}}
                        <div class="tnl-meta">
                            <span class="tnl-num">{{ $i + 1 }}</span>
                            <span class="tnl-title">{{ $row['title'] }}</span>
                            <x-filament::badge color="gray" size="sm">{{ $row['type'] }}</x-filament::badge>
                            @if ($row['enabled'])
                                <x-filament::badge color="success" size="sm">{{ tn_trans('Enabled') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="gray" size="sm">{{ tn_trans('Hidden') }}</x-filament::badge>
                            @endif
                        </div>

                        {{-- Preview --}}
                        <p class="tnl-preview">{{ $row['preview'] }}</p>

                        {{-- Horizontal action group --}}
                        <div class="tnl-actions">
                            <x-filament::button size="sm" color="gray" icon="heroicon-m-pencil-square"
                                                wire:click="editSection('{{ $row['id'] }}')">
                                {{ tn_trans('Edit') }}
                            </x-filament::button>
                            <x-filament::icon-button wire:click="moveUp('{{ $row['id'] }}')" icon="heroicon-m-arrow-up"
                                                     :tooltip="tn_trans('Move up')" :label="tn_trans('Move up')" :disabled="$i === 0" />
                            <x-filament::icon-button wire:click="moveDown('{{ $row['id'] }}')" icon="heroicon-m-arrow-down"
                                                     :tooltip="tn_trans('Move down')" :label="tn_trans('Move down')" :disabled="$i === count($rows) - 1" />
                            <x-filament::icon-button wire:click="duplicateSection('{{ $row['id'] }}')" icon="heroicon-m-document-duplicate"
                                                     :tooltip="tn_trans('Duplicate')" :label="tn_trans('Duplicate')" />
                            <x-filament::icon-button wire:click="toggleEnabled('{{ $row['id'] }}')"
                                                     icon="{{ $row['enabled'] ? 'heroicon-m-eye-slash' : 'heroicon-m-eye' }}"
                                                     color="{{ $row['enabled'] ? 'warning' : 'success' }}"
                                                     :tooltip="$row['enabled'] ? tn_trans('Hide') : tn_trans('Show')"
                                                     :label="$row['enabled'] ? tn_trans('Hide') : tn_trans('Show')" />
                            <x-filament::icon-button wire:click="removeSection('{{ $row['id'] }}')" icon="heroicon-m-trash" color="danger"
                                                     :tooltip="tn_trans('Delete')" :label="tn_trans('Delete')"
                                                     wire:confirm="{{ tn_trans('Delete this section?') }}" />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- Schema-driven section editor --}}
    @if ($this->editingId !== null)
        <x-filament::section>
            <x-slot name="heading">{{ tn_trans('Edit section') }}: {{ \Illuminate\Support\Str::headline($this->editingType() ?? '') }}</x-slot>
            @if ($multiLocale)
                <x-slot name="description">
                    {{ tn_trans('Editing content for language:') }} <strong>{{ $this->localeOptions()[$editLocale] ?? $editLocale }}</strong>
                </x-slot>
            @endif

            <form wire:submit="applyEdit" class="tnl tnl-cards">
                {{ $this->form }}

                <div class="tnl-actions" style="justify-content:flex-end;">
                    <x-filament::button type="button" color="gray" wire:click="cancelEdit">
                        {{ tn_trans('Cancel') }}
                    </x-filament::button>
                    <x-filament::button type="submit" icon="heroicon-m-check">
                        {{ tn_trans('Apply changes') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
