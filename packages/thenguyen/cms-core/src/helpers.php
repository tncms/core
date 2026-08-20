<?php

declare(strict_types=1);

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\ExtensionInstaller;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Services\ExtensionTranslationManager;
use TheNguyen\CMS\Services\HtmlSanitizer;
use TheNguyen\CMS\Services\InstallerManager;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\MaintenanceManager;
use TheNguyen\CMS\Services\MenuManager;
use TheNguyen\CMS\Services\PermissionManager;
use TheNguyen\CMS\Services\PublicContentCacheManager;
use TheNguyen\CMS\Services\SeoManager;
use TheNguyen\CMS\Services\SettingsManager;
use TheNguyen\CMS\Services\SlugManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Services\ThemeOptionManager;
use TheNguyen\CMS\Services\WidgetManager;

if (! function_exists('settings')) {
    /**
     * Access the CMS settings manager.
     *
     * - settings() returns the SettingsManager instance.
     * - settings('group.key') returns the resolved setting value.
     *
     * @return ($key is null ? SettingsManager : mixed)
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        /** @var SettingsManager $manager */
        $manager = app('cms.settings');

        if ($key === null) {
            return $manager;
        }

        return $manager->get($key, $default);
    }
}

if (! function_exists('public_content_cache')) {
    /**
     * The public content resolution cache manager (v1.0.0-beta.6.3).
     *
     * Example: public_content_cache()->flush() invalidates every cached public
     * URL resolution at once.
     */
    function public_content_cache(): PublicContentCacheManager
    {
        /** @var PublicContentCacheManager $manager */
        $manager = app('cms.public_cache');

        return $manager;
    }
}

if (! function_exists('setting_localized')) {
    /**
     * Resolve a localized setting with graceful fallback (v1.0.0-beta.6.2).
     *
     * Lookup order: requested locale → CMS default locale → global cms_settings
     * value → provided default. Never throws.
     *
     * setting_localized('general.site_name')          => current locale
     * setting_localized('seo.default_meta_title', 'en') => explicit locale
     *
     * @param  string|null  $locale  Defaults to the current request locale.
     */
    function setting_localized(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        /** @var SettingsManager $manager */
        $manager = app('cms.settings');

        return $manager->getLocalized($key, $locale, $default);
    }
}

if (! function_exists('cms_setting_localized')) {
    /**
     * Namespaced alias of {@see setting_localized()} for themes/plugins that
     * prefer a cms_ prefix to avoid helper collisions.
     */
    function cms_setting_localized(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        return setting_localized($key, $locale, $default);
    }
}

if (! function_exists('cms_can')) {
    /**
     * Whether a user is granted a CMS permission.
     *
     * Defaults to the currently authenticated user when none is given. Delegates
     * to the PermissionManager, so the super-admin bypass and the fail-open
     * (pre-initialised) behaviour apply consistently.
     *
     * cms_can('themes.install')          => current user
     * cms_can('plugins.delete', $user)   => a specific user
     */
    function cms_can(string $permission, ?\Illuminate\Contracts\Auth\Authenticatable $user = null): bool
    {
        /** @var PermissionManager $manager */
        $manager = app('cms.permission');

        $user ??= auth()->user();

        return $manager->userCan($user, $permission);
    }
}

if (! function_exists('maintenance')) {
    /**
     * Access the CMS maintenance manager (v1.0.0-beta.4).
     *
     * - maintenance() returns the MaintenanceManager instance.
     *   e.g. maintenance()->isEnabled(), maintenance()->shouldBypass($request).
     */
    function maintenance(): MaintenanceManager
    {
        /** @var MaintenanceManager $manager */
        $manager = app('cms.maintenance');

        return $manager;
    }
}

if (! function_exists('installer')) {
    /**
     * Access the Web Installer manager (v1.0.0-beta.6).
     *
     * - installer() returns the InstallerManager instance.
     *   e.g. installer()->isInstalled(), installer()->canRun(),
     *        installer()->markInstalled().
     */
    function installer(): InstallerManager
    {
        /** @var InstallerManager $manager */
        $manager = app('cms.installer');

        return $manager;
    }
}

if (! function_exists('extension_translation')) {
    /**
     * Access the Extension Translation manager (v1.0.0-beta.5).
     */
    function extension_translation(): ExtensionTranslationManager
    {
        /** @var ExtensionTranslationManager $manager */
        $manager = app('cms.extension_translation');

        return $manager;
    }
}

if (! function_exists('core_trans')) {
    /**
     * Translate a CMS core interface string (lang/{locale}.json).
     *
     * core_trans('Search')                 => current locale
     * core_trans('Search', [], 'vi')       => specific locale
     *
     * @param  array<string, mixed>  $replace
     */
    function core_trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return app('cms.extension_translation')->core($key, $replace, $locale);
    }
}

if (! function_exists('theme_trans')) {
    /**
     * Translate an active-theme interface string (theme lang/, falling back to
     * the default locale, then core).
     *
     * @param  array<string, mixed>  $replace
     */
    function theme_trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return app('cms.extension_translation')->theme($key, $replace, $locale);
    }
}

if (! function_exists('plugin_trans')) {
    /**
     * Translate a plugin interface string (plugins/{slug}/lang/ or
     * resources/lang/, falling back to the default locale, then core).
     *
     * plugin_trans('hello-world', 'Hello :name', ['name' => 'TN CMS'])
     *
     * @param  array<string, mixed>  $replace
     */
    function plugin_trans(string $plugin, string $key, array $replace = [], ?string $locale = null): string
    {
        return app('cms.extension_translation')->plugin($plugin, $key, $replace, $locale);
    }
}

if (! function_exists('tn_trans')) {
    /**
     * Alias of core_trans() — translate a CMS core interface string.
     *
     * @param  array<string, mixed>  $replace
     */
    function tn_trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return app('cms.extension_translation')->core($key, $replace, $locale);
    }
}

if (! function_exists('cms_slug')) {
    /**
     * Generate a CMS-aware slug from arbitrary text.
     *
     * The locale resolves to (1) the explicit argument, then (2) the current
     * request locale (which itself falls back to the CMS default language), so
     * it is never hardcoded to a single language.
     */
    function cms_slug(string $text, ?string $locale = null): string
    {
        /** @var SlugManager $manager */
        $manager = app('cms.slug');

        return $manager->generate($text, $locale ?? current_locale());
    }
}

if (! function_exists('menu')) {
    /**
     * Access the CMS menu manager, or resolve a menu by location/slug.
     *
     * - menu() returns the MenuManager instance.
     * - menu('header') resolves a menu by location first, then by slug.
     *
     * @return ($locationOrSlug is null ? MenuManager : \TheNguyen\CMS\Models\Menu|null)
     */
    function menu(?string $locationOrSlug = null, ?string $locale = null): mixed
    {
        /** @var MenuManager $manager */
        $manager = app('cms.menu');

        if ($locationOrSlug === null) {
            return $manager;
        }

        $locale ??= current_locale();

        return $manager->getMenuByLocation($locationOrSlug, $locale)
            ?? $manager->getMenuBySlug($locationOrSlug, $locale);
    }
}

if (! function_exists('theme')) {
    /**
     * Access the CMS theme manager, or resolve a theme by slug.
     *
     * - theme() returns the ThemeManager instance.
     * - theme('default') returns the Theme object (or null if not found).
     * - theme()->active() returns the active Theme object.
     *
     * @return ($slug is null ? ThemeManager : \TheNguyen\CMS\Support\Theme|null)
     */
    function theme(?string $slug = null): mixed
    {
        /** @var ThemeManager $manager */
        $manager = app('cms.theme');

        if ($slug === null) {
            return $manager;
        }

        return $manager->find($slug);
    }
}

if (! function_exists('theme_option')) {
    /**
     * Resolve a theme option value for the active theme (or a given theme).
     *
     * Falls back to the schema-declared default, then to $default, when no value
     * has been saved. Reads from cms_settings via the ThemeOptionManager.
     *
     * theme_option('footer_text')           => stored value or schema default
     * theme_option('primary_color', '#000') => '#000' when unset and no default
     */
    function theme_option(string $key, mixed $default = null, ?string $theme = null): mixed
    {
        /** @var ThemeOptionManager $manager */
        $manager = app('cms.theme_option');

        return $manager->get($key, $default, $theme);
    }
}

if (! function_exists('tn_content_layout')) {
    /**
     * Normalise a content-layout option value to a safe, known layout.
     *
     * The shared content-layout contract (Phase 8A): only full-width,
     * left-sidebar and right-sidebar are valid. Any unknown/invalid value falls
     * back to $fallback so raw option values never reach Blade or class names.
     *
     * Reusable across archive, post detail, and future page types (Docs,
     * Ecommerce, …) so they all share the same .tn-content-layout primitives.
     */
    function tn_content_layout(mixed $value, string $fallback = 'right-sidebar'): string
    {
        $allowed = ['full-width', 'left-sidebar', 'right-sidebar'];

        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        return in_array($fallback, $allowed, true) ? $fallback : 'right-sidebar';
    }
}

if (! function_exists('tn_content_layout_class')) {
    /**
     * Map a content-layout option value to its (validated) CSS class.
     *
     * tn_content_layout_class('left-sidebar')  => tn-content-layout--left-sidebar
     * tn_content_layout_class('bogus')         => tn-content-layout--right-sidebar
     */
    function tn_content_layout_class(mixed $value, string $fallback = 'right-sidebar'): string
    {
        return 'tn-content-layout--'.tn_content_layout($value, $fallback);
    }
}

if (! function_exists('tn_sidebar_width')) {
    /**
     * Normalise a sidebar-width option value to a safe pixel width.
     *
     * Only 280, 320 and 360 are valid; anything else falls back to 320.
     */
    function tn_sidebar_width(mixed $value, int $fallback = 320): int
    {
        $allowed = [280, 320, 360];
        $width = is_numeric($value) ? (int) $value : 0;

        if (in_array($width, $allowed, true)) {
            return $width;
        }

        return in_array($fallback, $allowed, true) ? $fallback : 320;
    }
}

if (! function_exists('extension')) {
    /**
     * Access the CMS Extension Framework (themes + plugins orchestration).
     *
     * - extension() returns the ExtensionManager instance.
     *   e.g. extension()->plugins(), extension()->activePlugins(),
     *        extension()->isPluginActive('hello-world').
     */
    function extension(): ExtensionManager
    {
        /** @var ExtensionManager $manager */
        $manager = app('cms.extension');

        return $manager;
    }
}

if (! function_exists('extension_installer')) {
    /**
     * Access the Theme/Plugin ZIP Installer (v1.0.0-beta.2).
     *
     * - extension_installer() returns the ExtensionInstaller instance.
     *   e.g. extension_installer()->installPluginFromZip($path),
     *        extension_installer()->installThemeFromZip($path, overwrite: true).
     */
    function extension_installer(): ExtensionInstaller
    {
        /** @var ExtensionInstaller $installer */
        $installer = app('cms.extension_installer');

        return $installer;
    }
}

if (! function_exists('cms_html')) {
    /**
     * Render a content body safely for output with {!! !!}.
     *
     * - Rich HTML (TinyMCE output) is sanitized through the HtmlSanitizer.
     * - Legacy plain-text content (no HTML tags) is escaped and line-broken
     *   so existing records still display acceptably without migration.
     */
    function cms_html(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $trimmed = trim($html);

        if ($trimmed === '') {
            return '';
        }

        // No tags → treat as legacy plain text: escape, then preserve breaks.
        if (! preg_match('/<[a-z!\/][^>]*>/i', $trimmed)) {
            return nl2br(e($trimmed));
        }

        /** @var HtmlSanitizer $sanitizer */
        $sanitizer = app('cms.html');

        return $sanitizer->sanitize($trimmed);
    }
}

if (! function_exists('seo')) {
    /**
     * Access the CMS SEO manager for the current page context.
     *
     * - seo()->title() / seo()->current() return resolved meta values.
     */
    function seo(): SeoManager
    {
        /** @var SeoManager $manager */
        $manager = app('cms.seo');

        return $manager;
    }
}

if (! function_exists('theme_asset')) {
    /**
     * Public URL for an asset of the active theme.
     *
     * theme_asset('css/app.css') => /themes/{active-theme}/css/app.css
     */
    function theme_asset(string $path): string
    {
        /** @var ThemeManager $manager */
        $manager = app('cms.theme');

        $slug = $manager->active()?->slug
            ?? (string) config('cms.theme.active', 'default');

        return '/themes/'.trim($slug, '/').'/'.ltrim($path, '/');
    }
}

if (! function_exists('theme_view')) {
    /**
     * Map a theme-relative view name to the "theme::" namespace.
     *
     * theme_view('pages.page') => theme::pages.page
     */
    function theme_view(string $view): string
    {
        return 'theme::'.$view;
    }
}

if (! function_exists('frontend_menu')) {
    /**
     * Resolve a location's menu into a render-ready tree.
     *
     * @return array<int, array{item: \TheNguyen\CMS\Models\MenuItem, title: string, url: string, children: array}>
     */
    function frontend_menu(string $location, ?string $locale = null): array
    {
        $locale ??= current_locale();

        /** @var MenuManager $manager */
        $manager = app('cms.menu');

        $menu = $manager->getMenuByLocation($location, $locale)
            ?? $manager->getMenuBySlug($location, $locale);

        if ($menu === null) {
            // Extension point (cms.menu.resolve): a location with no local menu
            // lets a plugin supply a render-ready tree for it — e.g. WP Remote
            // Contents injecting a remote menu. The filter receives null plus the
            // location and locale; a non-null array return is used as-is, so a
            // local menu always wins and an unhandled location renders empty.
            $resolved = apply_filters('cms.menu.resolve', null, $location, $locale);

            return is_array($resolved) ? $resolved : [];
        }

        return $manager->tree($menu, $locale);
    }
}

if (! function_exists('language')) {
    /**
     * Access the CMS language manager, or resolve a Language by code.
     *
     * - language() returns the LanguageManager instance.
     * - language('en') returns the Language model (or null).
     *
     * @return ($code is null ? LanguageManager : \TheNguyen\CMS\Models\Language|null)
     */
    function language(?string $code = null): mixed
    {
        /** @var LanguageManager $manager */
        $manager = app('cms.language');

        if ($code === null) {
            return $manager;
        }

        return $manager->find($code);
    }
}

if (! function_exists('current_locale')) {
    /**
     * The current request locale code (falls back to the default language).
     *
     * In the admin this tracks the admin UI locale (driven by ?lang); for
     * choosing which content TRANSLATION to read/display use
     * {@see editing_locale()} instead (v1.0.0-beta.7.1.10.1).
     */
    function current_locale(): string
    {
        return app('cms.language')->currentCode();
    }
}

if (! function_exists('editing_locale')) {
    /**
     * The content TRANSLATION locale currently being edited/viewed in the admin
     * (`?locale=` -> session(cms.editing_locale) -> default). Independent of the
     * admin UI locale (`?lang` / current_locale()). Use this for translation
     * queries and per-locale display columns so content follows the language the
     * admin is working in, not the interface language (v1.0.0-beta.7.1.10.1).
     */
    function editing_locale(): string
    {
        $request = request();

        return app('cms.locale_preference')->resolveEditingLocale($request, $request->user());
    }
}

if (! function_exists('default_locale')) {
    /**
     * The default language code configured for the site.
     */
    function default_locale(): string
    {
        return app('cms.language')->defaultCode();
    }
}

if (! function_exists('localized_url')) {
    /**
     * Build a locale-aware public path (honours the prefix_default setting).
     *
     * localized_url('en', '/blog/foo') => /en/blog/foo
     * localized_url('vi', '/blog/foo') => /blog/foo   (default, unprefixed)
     */
    function localized_url(string $code, ?string $path = null): string
    {
        return app('cms.language')->localizedUrl($code, $path);
    }
}

if (! function_exists('content_url')) {
    /**
     * Public, locale-aware URL for a content record.
     *
     * - page: /{slug}        (or /{locale}/{slug})
     * - post: /blog/{slug}   (or /{locale}/blog/{slug})
     *
     * Returns '#' when the requested locale has no translation slug.
     */
    function content_url(Content $content, ?string $locale = null): string
    {
        $locale ??= app('cms.language')->currentCode();

        // Compatibility facade over the ONE canonical resource→public-URL service
        // (CORE-L10N.1B P3.2). No slug/route/prefix policy lives here anymore; the
        // legacy '#' sentinel is preserved for existing callers/themes.
        return app('cms.localization.content_url')->forResource($content, $locale) ?? '#';
    }
}

if (! function_exists('term_url')) {
    /**
     * Public, locale-aware URL for a taxonomy term.
     *
     * - category: /category/{slug}  (or /{locale}/category/{slug})
     * - tag:      /tag/{slug}        (or /{locale}/tag/{slug})
     *
     * Returns '#' when the requested locale has no translation slug.
     */
    function term_url(Term $term, ?string $locale = null): string
    {
        $locale ??= app('cms.language')->currentCode();

        // Compatibility facade over the ONE canonical resource→public-URL service
        // (CORE-L10N.1B P3.2). The taxonomy type is derived inside the service via
        // the term's registered resolver; legacy '#' sentinel preserved.
        return app('cms.localization.content_url')->forResource($term, $locale) ?? '#';
    }
}

if (! function_exists('language_switcher')) {
    /**
     * Render-ready data for a frontend language switcher.
     *
     * For the current content/term page it links to the matching localized URL
     * when a translation exists; otherwise it links to that language's home.
     *
     * @return array<int, array{code: string, label: string, url: string, active: bool, direction: string, flag: ?string}>
     */
    function language_switcher(): array
    {
        // CORE-L10N.1B: a thin facade over the Localization Platform. All resource knowledge
        // and URL construction live in the resolver registry + active strategy behind
        // LocaleSwitchTargetService; this function only projects the normalized targets into the
        // legacy switcher entry shape and applies the presentation filter.
        //
        // P3.3: the current resource comes from the ONE request-scoped authority via the context
        // factory — NOT from SeoManager — so switching works for any resource (incl. plugin
        // resources) even when SEO was never initialized.
        $context = app('cms.localization.context_factory')->current();

        // Preserve the current query string (?page=3&sort=price&filter=…) on the
        // switcher links so only the locale changes. This is a switcher-presentation
        // concern only — SEO (canonical/hreflang) consumes the query-free targets.
        $query = request()->getQueryString();

        $entries = array_map(
            static function (\TheNguyen\CMS\Localization\SwitchTarget $target) use ($query): array {
                $url = $target->url;

                if (is_string($query) && $query !== '' && ! str_contains($url, '?')) {
                    $url .= '?' . $query;
                }

                return [
                    'code' => $target->locale,
                    'label' => $target->label,
                    'url' => $url,
                    'active' => $target->active,
                    'direction' => $target->direction,
                    'flag' => $target->flag,
                ];
            },
            app('cms.localization.switch_targets')->targets($context),
        );

        // Presentation seam: a surface that switches locale by a mechanism other than a URL
        // prefix (e.g. a session storefront locale) can rewrite these entries for its own
        // routes. With no registered filter the entries are returned unchanged.
        return apply_filters('cms.language_switcher', $entries);
    }
}

if (! function_exists('widget')) {
    /**
     * Access the CMS widget manager (Widget Foundation, v1.0.0-beta.7).
     *
     * widget()->register(MyWidget::class);
     * widget()->renderArea('footer-1');
     */
    function widget(): WidgetManager
    {
        /** @var WidgetManager $manager */
        $manager = app('cms.widget');

        return $manager;
    }
}

if (! function_exists('widget_area')) {
    /**
     * Render a widget area's assigned widgets to safe HTML for the given locale
     * (defaults to the current locale). Returns '' when the area is empty,
     * inactive, or unknown — and never throws, so a broken widget cannot 500
     * the page. Output is pre-escaped/sanitized; echo with {!! !!}.
     */
    function widget_area(string $slug, ?string $locale = null): string
    {
        return app('cms.widget')->renderArea($slug, $locale);
    }
}

/*
|--------------------------------------------------------------------------
| Hooks & Shortcodes (v1.0.0-beta.7.1.11)
|--------------------------------------------------------------------------
|
| WordPress-inspired actions, filters and shortcodes. All helpers are guarded
| with function_exists so they never clash if a host app already defines them
| (TN CMS normally runs alone). Namespaced tn_* aliases are always available.
*/

if (! function_exists('hooks')) {
    /** The HookManager (actions & filters registry). */
    function hooks(): \TheNguyen\CMS\Services\HookManager
    {
        return app('cms.hooks');
    }
}

if (! function_exists('shortcodes')) {
    /** The ShortcodeManager. */
    function shortcodes(): \TheNguyen\CMS\Services\ShortcodeManager
    {
        return app('cms.shortcodes');
    }
}

if (! function_exists('tn_add_action')) {
    /** @param array<string, mixed> $meta */
    function tn_add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addAction($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('tn_do_action')) {
    function tn_do_action(string $hook, mixed ...$args): void
    {
        app('cms.hooks')->doAction($hook, ...$args);
    }
}

if (! function_exists('tn_add_filter')) {
    /** @param array<string, mixed> $meta */
    function tn_add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addFilter($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('tn_apply_filters')) {
    function tn_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return app('cms.hooks')->applyFilters($hook, $value, ...$args);
    }
}

if (! function_exists('tn_add_shortcode')) {
    function tn_add_shortcode(string $tag, callable|string $callback): void
    {
        app('cms.shortcodes')->register($tag, $callback);
    }
}

if (! function_exists('tn_do_shortcode')) {
    function tn_do_shortcode(?string $content, array $context = []): string
    {
        return app('cms.shortcodes')->render($content, $context);
    }
}

if (! function_exists('render_hook')) {
    /**
     * Run an action hook and return everything its callbacks echo, as a string.
     * Safe to echo in Blade with {!! render_hook('cms.theme.header') !!}.
     */
    function render_hook(string $hook, mixed ...$args): string
    {
        return app('cms.hooks')->captureAction($hook, ...$args);
    }
}

if (! function_exists('add_action')) {
    /** @param array<string, mixed> $meta */
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addAction($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        app('cms.hooks')->doAction($hook, ...$args);
    }
}

if (! function_exists('add_filter')) {
    /** @param array<string, mixed> $meta */
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addFilter($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return app('cms.hooks')->applyFilters($hook, $value, ...$args);
    }
}

// WordPress-style cms_* aliases. Thin, collision-safe wrappers over the same
// hook manager (cms.hooks) for plugins that prefer an explicitly namespaced API.
if (! function_exists('cms_add_action')) {
    /** @param array<string, mixed> $meta */
    function cms_add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addAction($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('cms_do_action')) {
    function cms_do_action(string $hook, mixed ...$args): void
    {
        app('cms.hooks')->doAction($hook, ...$args);
    }
}

if (! function_exists('cms_add_filter')) {
    /** @param array<string, mixed> $meta */
    function cms_add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        app('cms.hooks')->addFilter($hook, $callback, $priority, $acceptedArgs, $meta);
    }
}

if (! function_exists('cms_apply_filters')) {
    function cms_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return app('cms.hooks')->applyFilters($hook, $value, ...$args);
    }
}

if (! function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable|string $callback): void
    {
        app('cms.shortcodes')->register($tag, $callback);
    }
}

if (! function_exists('do_shortcode')) {
    function do_shortcode(?string $content, array $context = []): string
    {
        return app('cms.shortcodes')->render($content, $context);
    }
}

if (! function_exists('strip_shortcodes')) {
    function strip_shortcodes(?string $content): string
    {
        return app('cms.shortcodes')->strip($content);
    }
}

/*
| Hook context & definitions (v1.0.0-beta.7.1.11.1). Discoverability layer over
| the HookManager registry. All guarded; tn_* aliases always available.
*/

if (! function_exists('hook_context')) {
    /**
     * Build a HookContext from a data bag. Passed to hook/filter callbacks as
     * their final argument so extensions can read runtime context safely.
     *
     * @param  array<string, mixed>  $data
     */
    function hook_context(array $data = []): \TheNguyen\CMS\Support\Hooks\HookContext
    {
        return \TheNguyen\CMS\Support\Hooks\HookContext::make($data);
    }
}

if (! function_exists('tn_hook_context')) {
    /** @param array<string, mixed> $data */
    function tn_hook_context(array $data = []): \TheNguyen\CMS\Support\Hooks\HookContext
    {
        return \TheNguyen\CMS\Support\Hooks\HookContext::make($data);
    }
}

if (! function_exists('preview_context')) {
    /**
     * Build a PreviewContext from a data bag (v1.0.0-beta.7.1.12.1). Passed to
     * preview lifecycle hook/filter callbacks as their final argument. Reuses the
     * HookContext architecture for the shared runtime accessors.
     *
     * @param  array<string, mixed>  $data
     */
    function preview_context(array $data = []): \TheNguyen\CMS\Support\Preview\PreviewContext
    {
        return \TheNguyen\CMS\Support\Preview\PreviewContext::make($data);
    }
}

if (! function_exists('tn_preview_context')) {
    /** @param array<string, mixed> $data */
    function tn_preview_context(array $data = []): \TheNguyen\CMS\Support\Preview\PreviewContext
    {
        return \TheNguyen\CMS\Support\Preview\PreviewContext::make($data);
    }
}

/*
| Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2). Reshape TN CMS-owned admin form
| schemas and append regions from plugins. All guarded; tn_* aliases available.
*/

if (! function_exists('form_hooks')) {
    /** The FormHookBridge (admin form schema/region filters). */
    function form_hooks(): \TheNguyen\CMS\Services\FormHookBridge
    {
        return app('cms.form_hooks');
    }
}

if (! function_exists('tn_form_schema')) {
    /**
     * Filter a resource's form component array through cms.form.schema and the
     * per-alias cms.form.schema.{alias} filter.
     *
     * @param  array<int, mixed>  $components
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    function tn_form_schema(array $components, ?string $modelClass = null, ?string $alias = null, array $context = []): array
    {
        return app('cms.form_hooks')->applySchema($components, $modelClass, $alias, $context);
    }
}

if (! function_exists('tn_form_regions')) {
    /**
     * Resolve extra "region" components to append beneath a resource form, via
     * cms.form.regions and the per-alias cms.form.regions.{alias} filter.
     *
     * @param  array<int, mixed>  $regions
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    function tn_form_regions(array $regions, ?string $modelClass = null, ?string $alias = null, array $context = []): array
    {
        return app('cms.form_hooks')->applyRegions($regions, $modelClass, $alias, $context);
    }
}

if (! function_exists('define_action')) {
    /**
     * Document an action hook point (see {@see hook_definitions()}).
     *
     * @param  \TheNguyen\CMS\Support\Hooks\HookDefinition|string  $definition
     * @param  array<string, mixed>  $meta
     */
    function define_action($definition, array $meta = []): void
    {
        app('cms.hooks')->defineAction($definition, $meta);
    }
}

if (! function_exists('tn_define_action')) {
    /**
     * @param  \TheNguyen\CMS\Support\Hooks\HookDefinition|string  $definition
     * @param  array<string, mixed>  $meta
     */
    function tn_define_action($definition, array $meta = []): void
    {
        app('cms.hooks')->defineAction($definition, $meta);
    }
}

if (! function_exists('define_filter')) {
    /**
     * Document a filter hook point.
     *
     * @param  \TheNguyen\CMS\Support\Hooks\HookDefinition|string  $definition
     * @param  array<string, mixed>  $meta
     */
    function define_filter($definition, array $meta = []): void
    {
        app('cms.hooks')->defineFilter($definition, $meta);
    }
}

if (! function_exists('tn_define_filter')) {
    /**
     * @param  \TheNguyen\CMS\Support\Hooks\HookDefinition|string  $definition
     * @param  array<string, mixed>  $meta
     */
    function tn_define_filter($definition, array $meta = []): void
    {
        app('cms.hooks')->defineFilter($definition, $meta);
    }
}

if (! function_exists('hook_definition')) {
    /** The HookDefinition for $hook, or null when undefined. */
    function hook_definition(string $hook): ?\TheNguyen\CMS\Support\Hooks\HookDefinition
    {
        return app('cms.hooks')->definition($hook);
    }
}

if (! function_exists('hook_definitions')) {
    /**
     * All registered hook definitions, keyed by hook name.
     *
     * @return array<string, \TheNguyen\CMS\Support\Hooks\HookDefinition>
     */
    function hook_definitions(): array
    {
        return app('cms.hooks')->definitions();
    }
}
if (! function_exists('asset_registry')) {
    /** The AssetRegistry singleton (cms.assets). */
    function asset_registry(): \TheNguyen\CMS\Services\AssetRegistry
    {
        return app('cms.assets');
    }
}

if (! function_exists('register_style')) {
    /**
     * Register a stylesheet by handle (does not render until enqueued).
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     */
    function register_style(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'head', array $attributes = [], ?string $media = null): bool
    {
        return app('cms.assets')->registerStyle($handle, $src, $deps, $version, $scope, $position, $attributes, $media);
    }
}

if (! function_exists('register_script')) {
    /**
     * Register a classic script by handle (does not render until enqueued).
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     */
    function register_script(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = []): bool
    {
        return app('cms.assets')->registerScript($handle, $src, $deps, $version, $scope, $position, $attributes);
    }
}

if (! function_exists('register_module')) {
    /**
     * Register an ES module (<script type="module">) by handle.
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     */
    function register_module(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = []): bool
    {
        return app('cms.assets')->registerModule($handle, $src, $deps, $version, $scope, $position, $attributes);
    }
}

if (! function_exists('enqueue_style')) {
    /** Enqueue a registered style (and its dependencies). */
    function enqueue_style(string $handle): bool
    {
        return app('cms.assets')->enqueueStyle($handle);
    }
}

if (! function_exists('enqueue_script')) {
    /** Enqueue a registered script (and its dependencies). */
    function enqueue_script(string $handle): bool
    {
        return app('cms.assets')->enqueueScript($handle);
    }
}

if (! function_exists('enqueue_module')) {
    /** Enqueue a registered module (and its dependencies). */
    function enqueue_module(string $handle): bool
    {
        return app('cms.assets')->enqueueModule($handle);
    }
}

if (! function_exists('inline_style')) {
    /** Register inline CSS, optionally attached before/after a handle. */
    function inline_style(string $handle, string $code, ?string $before = null, ?string $after = null, string $scope = 'frontend', string $position = 'head'): bool
    {
        return app('cms.assets')->inlineStyle($handle, $code, $before, $after, $scope, $position);
    }
}

if (! function_exists('inline_script')) {
    /** Register inline JS, optionally attached before/after a handle. */
    function inline_script(string $handle, string $code, ?string $before = null, ?string $after = null, string $scope = 'frontend', string $position = 'footer'): bool
    {
        return app('cms.assets')->inlineScript($handle, $code, $before, $after, $scope, $position);
    }
}

if (! function_exists('render_frontend_styles')) {
    /**
     * Render enqueued frontend stylesheets + head inline styles. Echo in
     * <head> with {!! render_frontend_styles() !!}. Never throws.
     */
    function render_frontend_styles(): string
    {
        return app('cms.assets')->renderFrontendStyles();
    }
}

if (! function_exists('render_frontend_scripts')) {
    /**
     * Render enqueued frontend footer scripts/modules + footer inline scripts.
     * Echo before </body> with {!! render_frontend_scripts() !!}. Never throws.
     */
    function render_frontend_scripts(): string
    {
        return app('cms.assets')->renderFrontendScripts();
    }
}

if (! function_exists('render_admin_styles')) {
    /** Render enqueued admin stylesheets + head inline styles. Never throws. */
    function render_admin_styles(): string
    {
        return app('cms.assets')->renderAdminStyles();
    }
}

if (! function_exists('render_admin_scripts')) {
    /** Render enqueued admin footer scripts/modules + inline scripts. Never throws. */
    function render_admin_scripts(): string
    {
        return app('cms.assets')->renderAdminScripts();
    }
}

if (! function_exists('render_styles')) {
    /** Render the styles bucket (head by default) for a scope. Never throws. */
    function render_styles(string $scope, string $position = 'head'): string
    {
        return app('cms.assets')->renderStyles($scope, $position);
    }
}

if (! function_exists('render_scripts')) {
    /** Render the scripts bucket (footer by default) for a scope. Never throws. */
    function render_scripts(string $scope, string $position = 'footer'): string
    {
        return app('cms.assets')->renderScripts($scope, $position);
    }
}

if (! function_exists('tn_register_style')) {
    /** @param list<string> $deps @param array<string, scalar|bool> $attributes */
    function tn_register_style(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'head', array $attributes = [], ?string $media = null): bool
    {
        return app('cms.assets')->registerStyle($handle, $src, $deps, $version, $scope, $position, $attributes, $media);
    }
}

if (! function_exists('tn_register_script')) {
    /** @param list<string> $deps @param array<string, scalar|bool> $attributes */
    function tn_register_script(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = []): bool
    {
        return app('cms.assets')->registerScript($handle, $src, $deps, $version, $scope, $position, $attributes);
    }
}

if (! function_exists('tn_enqueue_style')) {
    function tn_enqueue_style(string $handle): bool
    {
        return app('cms.assets')->enqueueStyle($handle);
    }
}

if (! function_exists('tn_enqueue_script')) {
    function tn_enqueue_script(string $handle): bool
    {
        return app('cms.assets')->enqueueScript($handle);
    }
}

/*
|--------------------------------------------------------------------------
| Frontend asset rendering (A2 auth/account extraction)
|--------------------------------------------------------------------------
| Thin accessors over the Global Script Manager (cms.scripts, registered in
| CmsServiceProvider). Required by the account/auth layout views.
*/

if (! function_exists('render_head_assets')) {
    /**
     * Render registered head assets (meta, verification, external + inline head
     * scripts, JSON-LD, head embeds). Safe to echo in <head> with
     * {!! render_head_assets() !!}. Never throws.
     */
    function render_head_assets(): string
    {
        return app('cms.scripts')->renderHead();
    }
}

if (! function_exists('render_footer_assets')) {
    /**
     * Render registered footer assets (external + inline footer scripts, footer
     * embeds). Safe to echo before </body> with {!! render_footer_assets() !!}.
     * Never throws.
     */
    function render_footer_assets(): string
    {
        return app('cms.scripts')->renderFooter();
    }
}

/*
|--------------------------------------------------------------------------
| Frontend Authentication (v1.0.0-beta.7.1.14)
|--------------------------------------------------------------------------
| Convenience accessors for the shared web guard from a frontend perspective.
| These never shadow Laravel's auth() — they sit alongside it.
*/

if (! function_exists('frontend_auth')) {
    /** The FrontendAuthManager singleton (cms.frontend_auth). */
    function frontend_auth(): \TheNguyen\CMS\Services\FrontendAuthManager
    {
        return app('cms.frontend_auth');
    }
}

if (! function_exists('frontend_user')) {
    /** The currently authenticated user on the web guard, or null. */
    function frontend_user(): ?\App\Models\User
    {
        $user = app('cms.frontend_auth')->guard()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

if (! function_exists('current_user')) {
    /** Alias of frontend_user(): the current web-guard user, or null. */
    function current_user(): ?\App\Models\User
    {
        return frontend_user();
    }
}

if (! function_exists('is_frontend_authenticated')) {
    /** True when a user is logged in on the web guard. */
    function is_frontend_authenticated(): bool
    {
        return app('cms.frontend_auth')->guard()->check();
    }
}
