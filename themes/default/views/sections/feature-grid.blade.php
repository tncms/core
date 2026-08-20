{{-- Feature grid section. Heading + a grid of feature items. --}}
@php($s = $section)
<section class="section section--feature-grid section--bg-{{ $s->setting('background', 'none') }} align--{{ $s->setting('align', 'center') }}"
         data-variant="{{ $s->setting('variant', 'standard') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => $s->field('subheading'),
        ])

        @php($features = $s->field('features', []))
        @if (is_array($features) && $features !== [])
            <div class="feature-grid feature-grid--cols-{{ $s->setting('columns', 3) }}">
                @foreach ($features as $feature)
                    @include('theme::components.feature-item', ['feature' => $feature])
                @endforeach
            </div>
        @endif
    </div>
</section>
