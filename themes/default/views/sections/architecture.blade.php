{{-- Architecture section. Heading + a vertical stack of layers connected as a
     downward flow (theme → … → framework). Each layer: optional glyph, label,
     optional one-line description. Null-safe. --}}
@php($s = $section)
<section class="section section--architecture section--bg-{{ $s->setting('background', 'surface') }} align--{{ $s->setting('align', 'center') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => $s->field('subheading'),
        ])

        @php($layers = $s->field('layers', []))
        @if (is_array($layers) && $layers !== [])
            <ol class="arch-stack" role="list">
                @foreach ($layers as $layer)
                    <li class="arch-layer">
                        <div class="arch-layer__node">
                            @if (! empty($layer['icon']))
                                <span class="arch-layer__icon" aria-hidden="true">{{ $layer['icon'] }}</span>
                            @endif
                            @if (! empty($layer['label']))
                                <span class="arch-layer__label">{{ $layer['label'] }}</span>
                            @endif
                        </div>
                        @if (! empty($layer['description']))
                            <p class="arch-layer__desc">{{ $layer['description'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
