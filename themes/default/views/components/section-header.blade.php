{{--
    Section header. Inputs: $heading (string|null), $subheading (string|null).
    Renders nothing when both are empty.
--}}
@php($heading = $heading ?? null)
@php($subheading = $subheading ?? null)
@if ((is_string($heading) && $heading !== '') || (is_string($subheading) && $subheading !== ''))
    <header class="section-header">
        @if (is_string($heading) && $heading !== '')
            <h2 class="section-header__title">{{ $heading }}</h2>
        @endif
        @if (is_string($subheading) && $subheading !== '')
            <p class="section-header__subtitle">{{ $subheading }}</p>
        @endif
    </header>
@endif
