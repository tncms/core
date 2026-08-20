{{-- Hero section. Renders eyebrow, title, subtitle, CTAs, media. The optional
     `modern-split` variant places the copy and media side by side; the default
     `standard` variant stacks them centered (its historic layout). --}}
@php($s = $section)
<section class="section section--hero section--bg-{{ $s->setting('background', 'tint') }} align--{{ $s->setting('align', 'center') }}"
         data-variant="{{ $s->setting('variant', 'standard') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        <div class="hero__content">
            @php($eyebrow = $s->field('eyebrow'))
            @if (is_string($eyebrow) && $eyebrow !== '')
                <p class="hero__eyebrow">{{ $eyebrow }}</p>
            @endif

            @php($title = $s->field('title'))
            @if (is_string($title) && $title !== '')
                <h1 class="hero__title">{{ $title }}</h1>
            @endif

            @php($subtitle = $s->field('subtitle'))
            @if (is_string($subtitle) && $subtitle !== '')
                <p class="hero__subtitle">{{ $subtitle }}</p>
            @endif

            @php($ctas = $s->field('ctas', []))
            @if (is_array($ctas) && $ctas !== [])
                <div class="hero__ctas">
                    @foreach ($ctas as $cta)
                        @include('theme::components.button', ['link' => $cta])
                    @endforeach
                </div>
            @endif

            @php($highlights = $s->field('highlights', []))
            @if (is_array($highlights) && $highlights !== [])
                <ul class="hero__highlights">
                    @foreach ($highlights as $highlight)
                        @if (! empty($highlight['text']))
                            <li class="hero__highlight">{{ $highlight['text'] }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>

        @php($media = $s->field('media'))
        @if ($media instanceof \TheNguyen\CMS\View\MediaViewModel && $media->url !== '')
            <div class="hero__media">
                @include('theme::components.media', ['media' => $media])
            </div>
        @endif
    </div>
</section>
