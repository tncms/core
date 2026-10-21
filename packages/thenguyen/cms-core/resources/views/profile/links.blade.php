{{--
    Core-owned safe DISPLAY of a target user's profile URL links (P4). Renders
    only url-type fields the viewer is authorized to read, whose scheme passed
    the server-side allow-list (http/https or the field's declared schemes). No
    hard-coded social schema — whatever url fields are registered and readable.
    Escaped; external links carry rel="noopener noreferrer nofollow".

    Usage: @include('cms::profile.links', ['target' => $author])
    Optional: $viewer (defaults to current auth user, never the target), $heading.
--}}
@php($plTarget = $target ?? null)
@php($plLinks = $plTarget ? cms_profile_links($plTarget, $viewer ?? null) : [])

@if ($plLinks !== [])
    <nav class="tn-profile-links" aria-label="{{ $heading ?? __('Profile links') }}">
        <ul class="tn-profile-links__list">
            @foreach ($plLinks as $plLink)
                <li class="tn-profile-links__item">
                    <a href="{{ $plLink['url'] }}" rel="noopener noreferrer nofollow" target="_blank">{{ $plLink['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
