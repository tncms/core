{{--
    Media component. Input: $media = a MediaViewModel (17 §5) or null. Renders a
    CLS-safe <img>; degrades to nothing when absent. Never reads raw URLs/models
    (20 R10) — the resolver produced the MediaViewModel.
--}}
@php($media = $media ?? null)
@if ($media instanceof \TheNguyen\CMS\View\MediaViewModel && $media->url !== '')
    <img class="media"
         src="{{ $media->url }}"
         alt="{{ $media->alt }}"
         @if ($media->width) width="{{ $media->width }}" @endif
         @if ($media->height) height="{{ $media->height }}" @endif
         loading="lazy">
@endif
