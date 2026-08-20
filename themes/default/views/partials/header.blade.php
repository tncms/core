{{-- Default Theme — header partial. --}}
<header class="site-header">
    <div class="container site-header__inner">
        @php($siteName = setting_localized('general.site_name', null, config('cms.name', 'TN CMS')))
        @php($logo = theme_option('logo'))
        {{-- Only render http(s) or root-relative logo URLs (defensive: reject data:/javascript: etc.). --}}
        @php($logoSafe = is_string($logo) && preg_match('#^(https?://|/)#i', $logo) === 1)
        <a class="site-brand" href="/">
            @if ($logoSafe)
                <img class="site-logo" src="{{ $logo }}" alt="{{ $siteName }}">
            @else
                {{ $siteName }}
            @endif
        </a>

        @php($tagline = setting_localized('general.site_tagline'))
        @if (theme_option('show_tagline', true) && is_string($tagline) && $tagline !== '')
            <p class="site-tagline">{{ $tagline }}</p>
        @endif

        <button type="button"
                class="site-nav__toggle"
                aria-controls="site-nav-menu"
                aria-expanded="false"
                aria-label="{{ __('Toggle navigation') }}"
                data-nav-toggle>
            <span class="site-nav__toggle-bars" aria-hidden="true"></span>
        </button>

        <nav class="site-nav" id="site-nav-menu" aria-label="Header" data-nav>
            {{-- currentPath mirrors the resolved menu URLs (locale prefix included),
                 so string equality is enough to detect the active item. --}}
            @php($currentPath = '/' . ltrim(request()->path(), '/'))
            {{-- Recursive: a node is active when it OR any descendant matches the
                 current URL, so parents of the current page are highlighted too. --}}
            @php($isNodeActive = function ($node) use (&$isNodeActive, $currentPath) {
                if (($node['url'] ?? null) === $currentPath) {
                    return true;
                }
                foreach ($node['children'] ?? [] as $child) {
                    if ($isNodeActive($child)) {
                        return true;
                    }
                }
                return false;
            })
            <ul class="menu">
                @foreach (frontend_menu('header') as $node)
                    @include('theme::partials.menu-item', [
                        'node' => $node,
                        'currentPath' => $currentPath,
                        'isNodeActive' => $isNodeActive,
                    ])
                @endforeach
            </ul>
        </nav>

        @php($languages = language_switcher())
        @if (count($languages) > 1)
            <ul class="lang-switcher" aria-label="Language switcher">
                @foreach ($languages as $lang)
                    <li class="lang-switcher__item">
                        @if (($lang['method'] ?? 'get') === 'post')
                            {{-- Session/cookie storefront locale: POST preserves the current
                                 (non-prefixed) route via a validated internal redirect. --}}
                            <form class="lang-switcher__form" method="post" action="{{ $lang['action'] }}">
                                @csrf
                                @foreach ($lang['fields'] ?? [] as $fieldName => $fieldValue)
                                    <input type="hidden" name="{{ $fieldName }}" value="{{ $fieldValue }}">
                                @endforeach
                                <button type="submit"
                                        class="lang-switcher__link @if ($lang['active']) is-active @endif"
                                        lang="{{ $lang['code'] }}"
                                        @if ($lang['active']) aria-current="true" @endif>
                                    {{ strtoupper($lang['code']) }}
                                </button>
                            </form>
                        @else
                            <a class="lang-switcher__link @if ($lang['active']) is-active @endif"
                               href="{{ $lang['url'] }}"
                               hreflang="{{ $lang['code'] }}"
                               @if ($lang['active']) aria-current="true" @endif>
                                {{ strtoupper($lang['code']) }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</header>
