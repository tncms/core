{{-- Feature split section. Media on one side, heading + body + bullets + CTA. --}}
@php($s = $section)
<section class="section section--feature-split section--bg-{{ $s->setting('background', 'none') }} media--{{ $s->setting('media_side', 'right') }}"
         id="{{ $s->id }}">
    <div class="section__inner feature-split__grid">
        <div class="feature-split__media">
            @include('theme::components.media', ['media' => $s->field('media')])
        </div>

        <div class="feature-split__body">
            @php($heading = $s->field('heading'))
            @if (is_string($heading) && $heading !== '')
                <h2 class="feature-split__title">{{ $heading }}</h2>
            @endif

            @php($body = $s->field('body'))
            @if (is_string($body) && $body !== '')
                <div class="feature-split__text">{!! cms_html($body) !!}</div>
            @endif

            @php($bullets = $s->field('bullets', []))
            @if (is_array($bullets) && $bullets !== [])
                <ul class="feature-split__bullets">
                    @foreach ($bullets as $bullet)
                        @if (! empty($bullet['text']))<li>{{ $bullet['text'] }}</li>@endif
                    @endforeach
                </ul>
            @endif

            @include('theme::components.button', ['link' => $s->field('cta')])
        </div>
    </div>
</section>
