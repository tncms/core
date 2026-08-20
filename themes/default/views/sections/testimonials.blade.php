{{-- Testimonials section. Heading + quote cards (quote/author/role/avatar). --}}
@php($s = $section)
<section class="section section--testimonials section--bg-{{ $s->setting('background', 'surface') }} layout--{{ $s->setting('layout', 'grid') }}"
         data-variant="{{ $s->setting('variant', 'standard') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @php($items = $s->field('items', []))
        @if (is_array($items) && $items !== [])
            <div class="testimonials__items testimonials--cols-{{ $s->setting('columns', 3) }}">
                @foreach ($items as $item)
                    <figure class="testimonial">
                        @if (! empty($item['quote']))
                            <blockquote class="testimonial__quote">{{ $item['quote'] }}</blockquote>
                        @endif
                        <figcaption class="testimonial__meta">
                            @include('theme::components.media', ['media' => $item['avatar'] ?? null])
                            @if (! empty($item['author']))<span class="testimonial__author">{{ $item['author'] }}</span>@endif
                            @if (! empty($item['role']))<span class="testimonial__role">{{ $item['role'] }}</span>@endif
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </div>
</section>
