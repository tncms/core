{{-- Stats band section. Optional heading + a row of number/label stats. --}}
@php($s = $section)
<section class="section section--stats-band section--bg-{{ $s->setting('background', 'tint') }} align--{{ $s->setting('align', 'center') }}"
         data-variant="{{ $s->setting('variant', 'plain') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @php($stats = $s->field('stats', []))
        @if (is_array($stats) && $stats !== [])
            <div class="stats-band__items stats-band--cols-{{ $s->setting('columns', 4) }}">
                @foreach ($stats as $stat)
                    <div class="stat">
                        @if (! empty($stat['icon']))<span class="stat__icon" aria-hidden="true">{{ $stat['icon'] }}</span>@endif
                        @if (! empty($stat['number']))<span class="stat__number">{{ $stat['number'] }}</span>@endif
                        @if (! empty($stat['label']))<span class="stat__label">{{ $stat['label'] }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
