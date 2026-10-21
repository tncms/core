{{--
    Core-owned, privacy-first DISPLAY of a target user's profile fields (P4).
    Read-only; authorization is enforced upstream by UserProfileManager via
    cms_profile_present(). Encrypted and media values are never surfaced here.
    All labels/values/URLs are escaped ({{ }}); URL fields render as links only
    when the scheme passed the server-side allow-list (href is null otherwise).

    Usage from any theme (no theme edit required):
        @include('cms::profile.fields', ['target' => $author])
    Optional vars: $viewer (defaults to the current auth user, never the target),
    $only (array of field keys), $heading.
--}}
@php($pfTarget = $target ?? null)
@php($pfPresent = $pfTarget ? cms_profile_present($pfTarget, $viewer ?? null, $only ?? null) : ['hasFields' => false, 'groups' => []])

@if ($pfPresent['hasFields'])
    <section class="tn-profile-display" aria-label="{{ $heading ?? __('Profile details') }}">
        @if (! empty($heading))
            <h2 class="tn-profile-display__title">{{ $heading }}</h2>
        @endif

        @foreach ($pfPresent['groups'] as $pfGroup)
            <div class="tn-profile-display__group">
                @if (($pfGroup['label'] ?? '') !== '')
                    <h3 class="tn-profile-display__group-title">{{ $pfGroup['label'] }}</h3>
                @endif

                <dl class="tn-profile-display__list">
                    @foreach ($pfGroup['fields'] as $pfField)
                        <div class="tn-profile-display__row">
                            <dt class="tn-profile-display__term">{{ $pfField['label'] }}</dt>
                            <dd class="tn-profile-display__desc">
                                @if ($pfField['type'] === 'url' && ! empty($pfField['href']))
                                    <a href="{{ $pfField['href'] }}" rel="noopener noreferrer nofollow" target="_blank">{{ $pfField['display'] }}</a>
                                @else
                                    {{ $pfField['display'] }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </section>
@endif
