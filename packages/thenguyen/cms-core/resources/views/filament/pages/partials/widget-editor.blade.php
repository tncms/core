{{-- Inline widget editor (Widget UI Polish, v1.0.0-beta.7.1.2). Rendered inside an
     expanded widget row (`.tn-widget-editor`) — no modal. Reads $editSchema /
     $editFields / $editTitle / $editLocale and the WidgetsPage Livewire actions.
     Layout is driven by the scoped `.tn-widget-*` CSS defined on the widgets page. --}}
<form wire:submit="save">
    {{-- General --}}
    <div class="tn-widget-editor-section">
        <div class="tn-widget-editor-section-title">{{ tn_trans('General') }}</div>

        {{-- Locale switcher (only when more than one language is active) --}}
        @if (count($this->locales) > 1)
            <div class="tn-widget-editor-locales">
                @foreach ($this->locales as $code => $label)
                    <x-filament::button
                        size="xs"
                        :color="$editLocale === $code ? 'primary' : 'gray'"
                        wire:click.prevent="setEditLocale('{{ $code }}')"
                    >
                        {{ $label }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif

        {{-- Universal localized title --}}
        <div class="tn-widget-field">
            <label for="tn-edit-title" class="tn-widget-label">{{ tn_trans('Title') }}</label>
            <input id="tn-edit-title" type="text" wire:model="editTitle" class="tn-widget-input" />
            <p class="tn-widget-hint">{{ tn_trans('Localized · :locale', ['locale' => $editLocale]) }}</p>
        </div>
    </div>

    {{-- Settings --}}
    @if (count($this->editSchema) > 0)
        <div class="tn-widget-editor-section">
            <div class="tn-widget-editor-section-title">{{ tn_trans('Settings') }}</div>

            @foreach ($this->editSchema as $field)
                @php($key = $field['key'])
                @php($localized = ($field['localized'] ?? false) === true)
                <div class="tn-widget-field">
                    @switch($field['type'] ?? 'text')
                        @case('textarea')
                        @case('richeditor')
                        @case('html')
                            <label for="tn-edit-{{ $key }}" class="tn-widget-label">{{ tn_trans($field['label'] ?? $key) }}</label>
                            <textarea id="tn-edit-{{ $key }}" rows="{{ $field['rows'] ?? 4 }}" wire:model="editFields.{{ $key }}" class="tn-widget-textarea" style="font-family: inherit;"></textarea>
                            @break

                        @case('toggle')
                            <div class="tn-widget-editor-check">
                                <input id="tn-edit-{{ $key }}" type="checkbox" wire:model="editFields.{{ $key }}" />
                                <label for="tn-edit-{{ $key }}" class="tn-widget-label" style="margin-bottom: 0;">{{ tn_trans($field['label'] ?? $key) }}</label>
                            </div>
                            @break

                        @case('number')
                            <label for="tn-edit-{{ $key }}" class="tn-widget-label">{{ tn_trans($field['label'] ?? $key) }}</label>
                            <input id="tn-edit-{{ $key }}" type="number"
                                @isset($field['min']) min="{{ $field['min'] }}" @endisset
                                @isset($field['max']) max="{{ $field['max'] }}" @endisset
                                wire:model="editFields.{{ $key }}" class="tn-widget-input" />
                            @break

                        @case('select')
                            <label for="tn-edit-{{ $key }}" class="tn-widget-label">{{ tn_trans($field['label'] ?? $key) }}</label>
                            <select id="tn-edit-{{ $key }}" wire:model="editFields.{{ $key }}" class="tn-widget-select">
                                @foreach (($field['options'] ?? []) as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                            @break

                        @case('repeater')
                            <label class="tn-widget-label">{{ tn_trans($field['label'] ?? $key) }}</label>
                            <p class="tn-widget-hint">{{ tn_trans('Repeater fields are not editable here yet.') }}</p>
                            @break

                        @default
                            {{-- text, media (path/id) --}}
                            <label for="tn-edit-{{ $key }}" class="tn-widget-label">{{ tn_trans($field['label'] ?? $key) }}</label>
                            <input id="tn-edit-{{ $key }}" type="text" wire:model="editFields.{{ $key }}" class="tn-widget-input" />
                    @endswitch

                    @if (($field['helper'] ?? null))
                        <p class="tn-widget-hint">{{ $field['helper'] }}</p>
                    @endif

                    <p class="tn-widget-hint">
                        {{ $localized ? tn_trans('Localized · :locale', ['locale' => $editLocale]) : tn_trans('Global (all languages)') }}
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="tn-widget-editor-actions">
        <x-filament::button type="button" size="sm" color="gray" wire:click="closeEditor">{{ tn_trans('Cancel') }}</x-filament::button>
        <x-filament::button type="submit" size="sm" icon="heroicon-m-check">{{ tn_trans('Save changes') }}</x-filament::button>
    </div>
</form>
