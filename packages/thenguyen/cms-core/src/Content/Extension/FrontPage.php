<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Illuminate\Support\Facades\View;
use TheNguyen\CMS\Models\Content;
use Throwable;

/**
 * The site's front page: the configured static front page or the
 * conventional home page (CORE-CONTENT-EXT-R1 D-8). It can never become
 * plugin-owned — not by creation, adoption or operator transfer — so `/` can
 * never bypass Core (CORE-UNIFIED-OWNERSHIP-R1).
 */
final class FrontPage
{
    public const CONVENTIONAL_HOME_SLUG = 'trang-chu';

    /** A page that would become the conventional home page when created. */
    public static function isConventionalHome(string $type, string $slug, string $locale): bool
    {
        $default = app('cms.language')->defaultCode();

        return $type === 'page' && $slug === self::CONVENTIONAL_HOME_SLUG && ($locale === '' || $locale === $default);
    }

    public static function is(int $contentId, string $type): bool
    {
        if ($type !== 'page') {
            return false;
        }

        if ((int) settings('reading.homepage_page_id', 0) === $contentId) {
            return true;
        }

        $home = Content::query()->where('type', 'page')
            ->whereHas('translations', static fn ($q) => $q->where('slug', self::CONVENTIONAL_HOME_SLUG)->where('locale', app('cms.language')->defaultCode()))
            ->value('id');

        return $home !== null && (int) $home === $contentId;
    }

    /**
     * The Core page FrontendController::home() renders at `/`, or null when
     * `/` renders no single page or the outcome cannot be proven without
     * rendering (no theme, a section-layout homepage). Mirrors home() step by
     * step; the sitemap lists that page only as the home entry (CORE-SITEMAP-S02).
     * Locale-independent: home() picks the same page for every locale.
     */
    public static function renderedAtRootId(): ?int
    {
        try {
            if (app('cms.theme')->active() === null) {
                return null;
            }

            // Section homepage: decided only by rendering; never exclude then.
            if (View::exists('theme::pages.home') && app('cms.homepage')->hasLayout()) {
                return null;
            }

            $pageView = View::exists('theme::pages.page');
            $display = settings('reading.homepage_display');

            if ($display === 'static_page' && $pageView) {
                $id = (int) settings('reading.homepage_page_id', 0);
                $page = $id > 0 ? Content::query()->where('type', 'page')->whereKey($id)->first(['id', 'status', 'published_at']) : null;

                if ($page !== null && $page->isPubliclyPublished() && ! self::owned((int) $page->getKey())) {
                    return (int) $page->getKey();
                }
            }

            if ($display === 'latest_posts' && (View::exists('theme::pages.home') || View::exists('theme::archives.index'))) {
                return null;
            }

            $home = app('cms.content')->findBySlug(self::CONVENTIONAL_HOME_SLUG, app('cms.language')->defaultCode(), 'page');

            if ($pageView && $home !== null && $home->isPubliclyPublished() && ! self::owned((int) $home->getKey())) {
                return (int) $home->getKey();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The public locales in which the Settings-configured static homepage is translated, or null
     * when `/` does not render that page (latest posts, section layout, conventional home, no
     * theme, unpublished or owned page). Owner policy A (CORE-RELEASE-BLOCKERS-R1-CLOSURE): in an
     * enabled locale outside this list, `/` is a localized 404, and is absent from hreflang and the
     * sitemap. Memoized per request (the hreflang resolver asks once per locale).
     *
     * @return list<string>|null
     */
    public static function staticHomeLocales(): ?array
    {
        $memo = 'cms.front_page.static_home_locales';
        $request = request();

        if ($request->attributes->has($memo)) {
            return $request->attributes->get($memo);
        }

        $locales = null;
        $id = (int) settings('reading.homepage_page_id', 0);

        if (settings('reading.homepage_display') === 'static_page' && $id > 0 && self::renderedAtRootId() === $id) {
            // hasTranslation() is the same test the page route and the SEO robots rule use.
            $page = Content::query()->with('translations')->whereKey($id)->first();
            $locales = $page === null ? [] : array_values(array_filter(
                app('cms.language')->getPublicLocales(),
                static fn (string $locale): bool => $page->hasTranslation($locale),
            ));
        }

        $request->attributes->set($memo, $locales);

        return $locales;
    }

    /** Owner policy A: `/` in this enabled locale is the configured static homepage, untranslated. */
    public static function staticHomeUnavailableIn(string $locale): bool
    {
        $locales = self::staticHomeLocales();

        return $locales !== null && ! in_array($locale, $locales, true);
    }

    /**
     * Whether a setting key takes part in choosing the page at `/` (display
     * mode, static page, active theme, the theme's homepage layout or preset).
     */
    public static function isSelectionSetting(string $key): bool
    {
        return in_array($key, ['reading.homepage_display', 'reading.homepage_page_id', 'theme.active'], true)
            || preg_match('/^theme\.[^.]+\.homepage_(?:layout|preset)$/', $key) === 1;
    }

    private static function owned(int $id): bool
    {
        return app('cms.content_ownership')->ownershipOf($id) !== null;
    }
}
