{{-- CTA section. Title, subtitle, primary + optional secondary button. --}}
@php($s = $section)
<section class="section section--cta section--bg-{{ $s->setting('background', 'accent') }} align--{{ $s->setting('align', 'center') }}"
         data-variant="{{ $s->setting('variant', 'standard') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @php($title = $s->field('title'))
        @if (is_string($title) && $title !== '')
            <h2 class="cta__title">{{ $title }}</h2>
        @endif

        @php($subtitle = $s->field('subtitle'))
        @if (is_string($subtitle) && $subtitle !== '')
            <p class="cta__subtitle">{{ $subtitle }}</p>
        @endif

        <div class="cta__actions">
            @include('theme::components.button', ['link' => $s->field('button')])
            @include('theme::components.button', ['link' => $s->field('secondary')])
        </div>
    </div>
</section>
