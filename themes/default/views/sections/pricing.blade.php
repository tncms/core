{{-- Pricing section. Heading + plan cards (name/price/period/features/cta). --}}
@php($s = $section)
<section class="section section--pricing section--bg-{{ $s->setting('background', 'surface') }}"
         data-variant="{{ $s->setting('variant', 'standard') }}"
         id="{{ $s->id }}">
    <div class="section__inner">
        @include('theme::components.section-header', [
            'heading' => $s->field('heading'),
            'subheading' => null,
        ])

        @php($plans = $s->field('plans', []))
        @if (is_array($plans) && $plans !== [])
            <div class="pricing__plans pricing--cols-{{ $s->setting('columns', 3) }}">
                @foreach ($plans as $plan)
                    <div class="pricing-card @if (! empty($plan['featured'])) is-featured @endif">
                        @if (! empty($plan['featured']))
                            <span class="pricing-card__badge">{{ theme_trans('Recommended') }}</span>
                        @endif
                        @if (! empty($plan['name']))<h3 class="pricing-card__name">{{ $plan['name'] }}</h3>@endif
                        @if (! empty($plan['price']))
                            <p class="pricing-card__price">{{ $plan['price'] }}<span class="pricing-card__period">{{ $plan['period'] ?? '' }}</span></p>
                        @endif

                        @php($features = $plan['features'] ?? [])
                        @if (is_array($features) && $features !== [])
                            <ul class="pricing-card__features">
                                @foreach ($features as $feature)
                                    @if (! empty($feature['text']))<li>{{ $feature['text'] }}</li>@endif
                                @endforeach
                            </ul>
                        @endif

                        @include('theme::components.button', ['link' => $plan['cta'] ?? null])
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
