@extends('cms::account.layout')

@section('title', __('Preferences'))

@section('account_content')
    @php
        $user = auth()->user();
        try { $locales = app('cms.language')->active(); } catch (\Throwable) { $locales = collect(); }
    @endphp
    <div class="tn-acc-card">
        <h1>{{ __('Preferences') }}</h1>
        <p class="tn-acc-muted">{{ __('Each language preference is independent.') }}</p>
        <form method="POST" action="{{ route('cms.account.preferences.update') }}">
            @csrf
            @foreach ([
                'frontend_locale' => __('Site language'),
                'admin_locale' => __('Admin language'),
                'editing_locale' => __('Content editing language'),
            ] as $field => $label)
                <div class="tn-field">
                    <label for="{{ $field }}">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}">
                        <option value="">{{ __('Use default') }}</option>
                        @foreach ($locales as $locale)
                            <option value="{{ $locale->code }}" @selected(old($field, $user?->{$field}) === $locale->code)>
                                {{ $locale->name ?? $locale->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <div class="tn-field">
                <label for="timezone">{{ __('Timezone') }}</label>
                <input id="timezone" type="text" name="timezone" value="{{ old('timezone', $user?->timezone) }}" list="tn-tz-list" placeholder="UTC">
                <datalist id="tn-tz-list">
                    @foreach (['UTC', 'America/New_York', 'Europe/London', 'Europe/Paris', 'Asia/Ho_Chi_Minh', 'Asia/Tokyo', 'Australia/Sydney'] as $tz)
                        <option value="{{ $tz }}"></option>
                    @endforeach
                </datalist>
                <div class="tn-hint">{{ __('A valid timezone identifier, e.g. Asia/Ho_Chi_Minh.') }}</div>
            </div>

            <button type="submit" class="tn-btn">{{ __('Save preferences') }}</button>
        </form>
    </div>

    {!! render_hook('cms.account.preferences.after') !!}
@endsection
