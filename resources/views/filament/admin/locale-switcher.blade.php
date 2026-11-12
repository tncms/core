{{--
    Admin UI language switcher (v1.0.0-beta.7.1.10.1).

    A dependency-free GET form: choosing a language reloads the current page with
    ?lang=xx, which SetCmsAdminLocale resolves + persists to the user's
    admin_locale. This controls the INTERFACE language only — it preserves the
    current ?locale= content editing param (hidden field below) so switching the
    UI language never changes which translation is being edited. Independent of
    the public-site language. Renders nothing when the site has a single active
    language.

    CORE-H-Z1-REMEDIATION-R3:
    ER-4 (WCAG 3.2.2): choosing an option no longer submits the form. The page reloads in the
        new language only when the user activates the visible "Switch language" button (or
        presses Enter in the select, the browser's implicit submission of a form with a submit
        button), so arrowing through the options never changes the context.
    ER-5 (WCAG 3.1.2): each option is a language name in its own language (native name), so it
        carries lang="<code>". A language without a native name falls back to the stored name,
        whose language is not known, so no lang is set for it.
    ER-1 (WCAG 1.4.11): the select border was gray at 35% alpha; it uses the same gray-500 / gray-400
        boundary as the other Core form controls.
--}}
@php
    $tnLanguages = app('cms.language')->active();
    $tnCurrent = current_locale();
    $tnEditingLocale = request()->query('locale');
@endphp

@if ($tnLanguages->count() > 1)
    {{-- The inline field style below drops the theme's focus ring; restore a visible keyboard focus indicator (H-Z1, WCAG 2.4.7). --}}
    <style>.tn-admin-locale-switcher select{border:1px solid var(--gray-500);}.dark .tn-admin-locale-switcher select{border-color:var(--gray-400);}.tn-admin-locale-switcher select:focus-visible{outline:2px solid currentColor;outline-offset:2px;}.tn-admin-locale-switcher button:focus-visible{outline:2px solid currentColor;outline-offset:2px;}</style>
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
                aria-label="{{ tn_trans('Language') }}"
                class="fi-input fi-select-input"
                style="border-radius:0.5rem;padding:0.25rem 1.75rem 0.25rem 0.5rem;font-size:.875rem;background-color:transparent;color:inherit;cursor:pointer;">
            @foreach ($tnLanguages as $tnLanguage)
                <option value="{{ $tnLanguage->code }}" @if (filled($tnLanguage->native_name)) lang="{{ $tnLanguage->code }}" @endif @selected($tnLanguage->code === $tnCurrent)>
                    {{ $tnLanguage->label() }}
                </option>
            @endforeach
        </select>
        <button type="submit"
                class="tn-admin-locale-apply"
                style="margin-inline-start:0.375rem;border:1px solid currentColor;border-radius:0.5rem;padding:0.25rem 0.5rem;font-size:.875rem;line-height:1.25rem;background-color:transparent;color:inherit;cursor:pointer;white-space:nowrap;">
            {{ tn_trans('Switch language') }}
        </button>
    </form>
@endif
