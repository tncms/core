{{-- Logo strip section. Optional heading + a row of logos. --}}
@php($s = $section)
<section class="section section--logo-strip section--bg-{{ $s->setting('background', 'none') }} @if ($s->setting('grayscale', true)) is-grayscale @endif"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @php($logos = $s->field('logos', []))
        @if (is_array($logos) && $logos !== [])
            <div class="logo-strip__items">
                @foreach ($logos as $logo)
                    @php($url = $logo['url'] ?? null)
                    @if (! empty($url) && $url !== '#')
                        <a class="logo-strip__item" href="{{ $url }}">
                            @include('theme::components.media', ['media' => $logo['media'] ?? null])
                        </a>
                    @else
                        <span class="logo-strip__item">
                            @include('theme::components.media', ['media' => $logo['media'] ?? null])
                        </span>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</section>
