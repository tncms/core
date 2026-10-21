{{--
    Dynamic custom profile fields (P3). Self-resolving Core seam: a theme that
    overrides theme::account.profile can render the exact same section with
        @include('cms::account.partials.profile-fields')
    without duplicating any authorization/validation logic. The schema is built
    by AccountProfileFieldsPresenter via UserProfileManager (authorization-filtered);
    encrypted values are never echoed and media is display-only. All output is
    escaped by Blade ({{ }}); no field type emits raw HTML.
--}}
@php($pfSchema = cms_account_profile_fields())

@if ($pfSchema['hasFields'])
    <div class="tn-acc-card tn-profile-fields">
        <h2>{{ __('Additional details') }}</h2>

        <form method="POST" action="{{ $pfSchema['action'] }}" class="tn-profile-fields-form">
            @csrf

            @foreach ($pfSchema['groups'] as $group)
                <fieldset class="tn-field-group">
                    @if (($group['label'] ?? '') !== '')
                        <legend>{{ $group['label'] }}</legend>
                    @endif

                    @foreach ($group['fields'] as $f)
                        @php($name = 'profile['.$f['key'].']')
                        @php($id = $f['dom_id'])
                        @php($errorKey = 'profile.'.$f['key'])
                        @php($describedBy = trim(($f['help'] ? $id.'_help ' : '').($errors->has($errorKey) ? $id.'_err' : '')))

                        <div class="tn-field @error($errorKey) tn-field--error @enderror">
                            <label for="{{ $id }}">{{ $f['label'] }}</label>

                            @if ($f['editable'])
                                {{-- mark the field as rendered so the controller only touches shown fields --}}
                                <input type="hidden" name="profile_present[]" value="{{ $f['key'] }}">

                                @switch($f['type'])
                                    @case('textarea')
                                        <textarea id="{{ $id }}" name="{{ $name }}" rows="3"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                        >{{ old($errorKey, $f['value']) }}</textarea>
                                        @break

                                    @case('boolean')
                                        @php($checked = old($errorKey, $f['value'] ? '1' : null))
                                        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1"
                                            @checked($checked)
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @case('select')
                                        <select id="{{ $id }}" name="{{ $name }}"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                            <option value="">{{ __('— Select —') }}</option>
                                            @foreach ($f['choices'] as $val => $label)
                                                <option value="{{ $val }}" @selected((string) old($errorKey, $f['value']) === (string) $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('radio')
                                        <div class="tn-radio-group" role="radiogroup"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                            @foreach ($f['choices'] as $val => $label)
                                                <label class="tn-radio">
                                                    <input type="radio" name="{{ $name }}" value="{{ $val }}"
                                                        @checked((string) old($errorKey, $f['value']) === (string) $val)>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @break

                                    @case('checkbox')
                                        @php($current = (array) old($errorKey, $f['value'] ?? []))
                                        <div class="tn-checkbox-group" role="group"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                            @foreach ($f['choices'] as $val => $label)
                                                <label class="tn-checkbox">
                                                    <input type="checkbox" name="{{ $name }}[]" value="{{ $val }}"
                                                        @checked(in_array((string) $val, array_map('strval', $current), true))>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @break

                                    @case('number')
                                        <input type="number" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @case('date')
                                        <input type="date" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @case('datetime')
                                        <input type="datetime-local" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @case('email')
                                        <input type="email" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @case('url')
                                        <input type="url" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}" placeholder="https://…"
                                            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @break

                                    @default
                                        {{-- text and any other scalar type --}}
                                        @if ($f['encrypted'])
                                            {{-- sensitive: never echo the stored value; blank means "keep unchanged" --}}
                                            <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey) }}" autocomplete="off"
                                                placeholder="{{ $f['hasValue'] ? __('Set — leave blank to keep') : '' }}"
                                                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @else
                                            <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ old($errorKey, $f['value']) }}"
                                                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
                                        @endif
                                @endswitch

                                @if ($f['help'])
                                    <div class="tn-hint" id="{{ $id }}_help">{{ $f['help'] }}</div>
                                @endif
                                @error($errorKey)
                                    <div class="tn-error" id="{{ $id }}_err" role="alert">{{ $message }}</div>
                                @enderror
                            @else
                                {{-- read-only: visible but not owner-editable (or media/encrypted display) --}}
                                <div class="tn-readonly" id="{{ $id }}" aria-readonly="true">
                                    @if ($f['encrypted'])
                                        {{ $f['hasValue'] ? '••••••' : '—' }}
                                    @elseif ($f['type'] === 'checkbox' && is_array($f['value']))
                                        {{ $f['value'] === [] ? '—' : implode(', ', array_map(fn ($v) => $f['choices'][$v] ?? $v, $f['value'])) }}
                                    @elseif (in_array($f['type'], ['select', 'radio'], true))
                                        {{ $f['value'] === null || $f['value'] === '' ? '—' : ($f['choices'][$f['value']] ?? $f['value']) }}
                                    @else
                                        {{ ($f['value'] === null || $f['value'] === '') ? '—' : $f['value'] }}
                                    @endif
                                </div>
                                @if ($f['help'])
                                    <div class="tn-hint">{{ $f['help'] }}</div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </fieldset>
            @endforeach

            <button type="submit" class="tn-btn">{{ __('Save details') }}</button>
        </form>
    </div>
@endif
