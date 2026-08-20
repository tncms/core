{{--
    Feature item (molecule). Input: $feature = { icon?, title?, text?, url?, image? }.
    Used by the feature-grid section. Null-safe.

    When `image` is a resolved MediaViewModel it renders as a mini screenshot at
    the top of the card (the `screenshot-cards` variant); otherwise the glyph
    `icon` renders as before — both paths stay backward compatible.
--}}
@php($feature = is_array($feature ?? null) ? $feature : [])
@php($image = $feature['image'] ?? null)
@php($hasImage = $image instanceof \TheNguyen\CMS\View\MediaViewModel && $image->url !== '')
<div class="feature-item @if ($hasImage) feature-item--shot @endif">
    @if ($hasImage)
        <div class="feature-item__shot">
            @include('theme::components.media', ['media' => $image])
        </div>
    @elseif (! empty($feature['icon']))
        <span class="feature-item__icon" aria-hidden="true">{{ $feature['icon'] }}</span>
    @endif
    @if (! empty($feature['title']))
        <h3 class="feature-item__title">{{ $feature['title'] }}</h3>
    @endif
    @if (! empty($feature['text']))
        <p class="feature-item__text">{{ $feature['text'] }}</p>
    @endif
    @if (! empty($feature['url']))
        <a class="feature-item__link" href="{{ $feature['url'] }}">{{ theme_trans('Learn more') }}</a>
    @endif
</div>
