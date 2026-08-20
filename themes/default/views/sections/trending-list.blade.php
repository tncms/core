{{-- Trending list section. A ranked list of headlines. --}}
@php($s = $section)
@php($items = $s->field('items', []))
@php($showRank = (bool) $s->setting('show_rank', true))
<section class="section section--trending-list section--bg-{{ $s->setting('background', 'none') }}"
         id="{{ $s->id }}"
         data-variant="{{ $s->setting('variant', 'standard') }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @if (is_array($items) && $items !== [])
            <ol class="trending-list @if (! $showRank) trending-list--plain @endif">
                @foreach ($items as $i => $item)
                    @include('theme::components.rank-item', [
                        'item' => $item,
                        'rank' => $i + 1,
                        'showRank' => $showRank,
                    ])
                @endforeach
            </ol>
        @endif
    </div>
</section>
