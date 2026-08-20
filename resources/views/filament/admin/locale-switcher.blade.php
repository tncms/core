{{--
    Admin UI language switcher (v1.0.0-beta.7.1.10.1).

    A dependency-free GET form: choosing a language reloads the current page with
    ?lang=xx, which SetCmsAdminLocale resolves + persists to the user's
    admin_locale. This controls the INTERFACE language only — it preserves the
    current ?locale= content editing param (hidden field below) so switching the
    UI language never changes which translation is being edited. Independent of
    the public-site language. Renders nothing when the site has a single active
    language.
--}}
@php
    $tnLanguages = app('cms.language')->active();
    $tnCurrent = current_locale();
    $tnEditingLocale = request()->query('locale');
@endphp

@if ($tnLanguages->count() > 1)
    <form method="GET"
          action="{{ request()->url() }}"
          class="tn-admin-locale-switcher fi-dropdown"
          style="display:flex;align-items:center;margin-inline:0.5rem;">
        @if (is_string($tnEditingLocale) && $tnEditingLocale !== '')
            <input type="hidden" name="locale" value="{{ $tnEditingLocale }}">
        @endif
        <label for="tn-admin-locale" class="sr-only">{{ tn_trans('Language') }}</label>
        <select id="tn-admin-locale"
                name="lang"
                onchange="this.form.submit()"
                aria-label="{{ tn_trans('Language') }}"
                class="fi-input fi-select-input"
                style="border:1px solid rgba(127,127,127,.35);border-radius:0.5rem;padding:0.25rem 1.75rem 0.25rem 0.5rem;font-size:.875rem;background-color:transparent;color:inherit;cursor:pointer;">
            @foreach ($tnLanguages as $tnLanguage)
                <option value="{{ $tnLanguage->code }}" @selected($tnLanguage->code === $tnCurrent)>
                    {{ $tnLanguage->label() }}
                </option>
            @endforeach
        </select>
    </form>
@endif
