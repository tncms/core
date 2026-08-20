{{--
    Newsletter section. Heading + subtitle + a simple subscribe form. The form is
    presentational in v1 (no provider wiring yet); `provider` is reserved for a
    future integration and is not localized.
--}}
@php($s = $section)
<section class="section section--newsletter section--bg-{{ $s->setting('background', 'tint') }} align--{{ $s->setting('align', 'center') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => $s->field('subtitle'),
        ])

        @php($buttonLabel = $s->field('button_label', theme_trans('Subscribe')))
        @php($buttonLabel = is_string($buttonLabel) && $buttonLabel !== '' ? $buttonLabel : theme_trans('Subscribe'))
        <form class="newsletter__form" method="post" action="#" novalidate>
            <label class="newsletter__label" for="newsletter-{{ $s->id }}">{{ theme_trans('Email address') }}</label>
            <div class="newsletter__controls">
                <input class="newsletter__input"
                       id="newsletter-{{ $s->id }}"
                       type="email"
                       name="email"
                       autocomplete="email"
                       placeholder="{{ theme_trans('you@example.com') }}"
                       required>
                <button class="btn btn--solid newsletter__button" type="submit">{{ $buttonLabel }}</button>
            </div>
        </form>
    </div>
</section>
