{{-- Default Theme — footer partial. Brand lockup + menu-driven columns + meta.
     All labels come from settings or the menu system — never hardcoded. --}}
<footer class="site-footer">
    <div class="container site-footer__inner">
        @php($siteName = setting_localized('general.site_name', null, config('cms.name', 'TN CMS')))
        @php($tagline = setting_localized('general.site_tagline'))
        @php($footerNodes = frontend_menu('footer'))
        @php($social = theme_option('social_links'))
        @php($socialUrls = is_string($social) ? array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $social))) : [])
        @php($socialUrls = array_filter($socialUrls, fn ($u) => preg_match('#^https?://#i', $u) === 1))

        <div class="site-footer__top">
            <div class="footer-brand">
                <a class="footer-brand__name" href="/">{{ $siteName }}</a>
                @if (is_string($tagline) && trim($tagline) !== '')
                    <p class="footer-brand__desc">{{ $tagline }}</p>
                @endif

                @if (! empty($socialUrls))
                    <ul class="site-social" aria-label="{{ theme_trans('Social links') }}">
                        @foreach ($socialUrls as $url)
                            <li class="site-social__item">
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer">
                                    {{ preg_replace('#^www\.#', '', parse_url($url, PHP_URL_HOST) ?: $url) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if (! empty($footerNodes))
                <nav class="site-nav site-nav--footer" aria-label="Footer">
                    {{-- Root items with children render as labelled columns; root items
                         without children render as a single flat link column. Titles come
                         from the menu system — no hardcoded labels. --}}
                    <ul class="footer-cols">
                        @foreach ($footerNodes as $node)
                            <li class="footer-col">
                                @if (! empty($node['children']))
                                    <h2 class="footer-col__title">{{ $node['title'] }}</h2>
                                    <ul class="footer-col__links menu menu--footer">
                                        @foreach ($node['children'] as $child)
                                            <li class="menu__item">
                                                <a href="{{ $child['url'] }}"
                                                   @if (($child['item']->target ?? '_self') === '_blank') target="_blank" rel="noopener" @endif>
                                                    {{ $child['title'] }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <ul class="footer-col__links menu menu--footer">
                                        <li class="menu__item">
                                            <a href="{{ $node['url'] }}"
                                               @if (($node['item']->target ?? '_self') === '_blank') target="_blank" rel="noopener" @endif>
                                                {{ $node['title'] }}
                                            </a>
                                        </li>
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>

        {{-- Optional footer widget columns (Widget Foundation). Rendered below the
             menu-driven columns and only when at least one region has widgets, so
             the footer stays clean by default. widget_area() is null-safe. --}}
        @php($footerWidgetColumns = array_values(array_filter([
            widget_area('footer.column_1'),
            widget_area('footer.column_2'),
            widget_area('footer.column_3'),
            widget_area('footer.column_4'),
        ], fn ($html) => $html !== '')))
        @if (! empty($footerWidgetColumns))
            <div class="tn-footer-widgets" data-columns="{{ count($footerWidgetColumns) }}">
                @foreach ($footerWidgetColumns as $column)
                    <div class="tn-footer-widgets__col">{!! $column !!}</div>
                @endforeach
            </div>
        @endif

        @php($footerText = theme_option('footer_text'))
        <div class="site-footer__meta">
            <p class="site-footer__copy">
                @if (is_string($footerText) && trim($footerText) !== '')
                    {{ $footerText }}
                @else
                    &copy; {{ date('Y') }} {{ $siteName }}
                @endif
            </p>
        </div>
    </div>
</footer>
