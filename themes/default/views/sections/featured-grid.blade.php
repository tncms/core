{{-- Featured grid section. A lead story + a grid of secondary stories. --}}
@php($s = $section)
@php($layout = $s->setting('layout', 'editorial'))
@php($showMeta = (bool) $s->setting('show_meta', true))
@php($mainList = $s->field('main', []))
@php($main = is_array($mainList) && isset($mainList[0]) && is_array($mainList[0]) ? $mainList[0] : null)
@php($items = $s->field('items', []))
<section class="section section--featured-grid section--bg-{{ $s->setting('background', 'none') }} featured-grid--{{ $layout }}"
         id="{{ $s->id }}"
         data-variant="{{ $s->setting('variant', 'standard') }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @if ($main !== null || (is_array($items) && $items !== []))
            <div class="featured-grid">
                @if ($main !== null)
                    <div class="featured-grid__lead">
                        @include('theme::components.story-card', [
                            'story' => $main,
                            'variant' => 'feature',
                            'showMeta' => $showMeta,
                            'showExcerpt' => true,
                        ])
                    </div>
                @endif

                @if (is_array($items) && $items !== [])
                    <div class="featured-grid__items">
                        @foreach ($items as $item)
                            @include('theme::components.story-card', [
                                'story' => $item,
                                'variant' => 'grid',
                                'showMeta' => $showMeta,
                                'showExcerpt' => false,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
