{{-- FAQ section. Heading + accordion of question/answer items. --}}
@php($s = $section)
<section class="section section--faq section--bg-{{ $s->setting('background', 'none') }} layout--{{ $s->setting('layout', 'single') }}"
         data-variant="{{ $s->setting('variant', 'plain') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @include('theme::components.accordion', [
            'items' => $s->field('items', []),
            'allowMultiple' => (bool) $s->setting('allow_multiple', false),
            'sectionId' => $s->id,
        ])
    </div>
</section>
