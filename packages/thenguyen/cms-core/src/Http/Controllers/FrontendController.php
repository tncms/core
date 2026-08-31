<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Localization\CurrentResourcePublisher;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\HomepageResolver;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\PublicContentCacheManager;
use TheNguyen\CMS\Services\SeoManager;
use TheNguyen\CMS\Services\SlugManager;
use TheNguyen\CMS\Services\TaxonomyManager;

/**
 * Renders the public site using the active theme's "theme::" views.
 *
 * Multi-language aware (Phase 9): every action accepts an optional {locale}
 * segment. The default language renders at the unprefixed paths; other active
 * languages render under /{locale}/... Only published content is exposed; a
 * missing translation 404s (no implicit fallback). A missing theme view
 * degrades to a plain fallback response instead of a 500.
 */
class FrontendController
{
    public function __construct(
        private readonly ContentManager $contents,
        private readonly TaxonomyManager $taxonomies,
        private readonly SeoManager $seo,
        private readonly LanguageManager $languages,
        private readonly SlugManager $slugs,
        private readonly HomepageResolver $homepage,
        private readonly PublicContentCacheManager $publicCache,
        private readonly CurrentResourcePublisher $resources,
    ) {}

    public function home(): Response
    {
        $this->tagPageType('homepage');

        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        $locale = $this->resolveLocale($this->routeParam('locale'));

        // P3.3B: the render boundary is the sole writer of the current resource.
        // CORE-FREEZE-4: publish precedes SEO so the current resource is the
        // published authority before SEO is attached.
        $this->resources->publishHome();

        // Homepage SEO is site-level (site name / description), even when a
        // static page is used to render it.
        $this->seo->forHome();

        // Preset/builder homepage (theme-architecture 18): when the active theme
        // has a resolvable section layout (stored layout or active preset), render
        // it via the section stack. Returns null when no layout applies, so the
        // legacy reading.homepage_display behavior below is fully preserved.
        $sections = $this->resolveHomepageSections($locale);

        if ($sections !== null) {
            return $sections;
        }

        $display = settings('reading.homepage_display');

        // A static page chosen in Settings → Reading.
        if ($display === 'static_page') {
            $page = $this->findStaticHomePage();

            if ($page !== null && View::exists('theme::pages.page')) {
                return response($this->renderContent('theme::pages.page', $page, $locale));
            }
        }

        // Latest published posts.
        if ($display === 'latest_posts') {
            $latest = $this->renderLatestPosts($locale);

            if ($latest !== null) {
                return $latest;
            }
        }

        // Legacy / fallback behaviour (unchanged): the seeded "trang-chu" page,
        // then a theme home view, then a plain fallback.
        $page = $this->findHomeContent($locale);

        if ($page !== null && View::exists('theme::pages.page')) {
            return response($this->renderContent('theme::pages.page', $page, $locale));
        }

        if (View::exists('theme::pages.home')) {
            return response(View::make('theme::pages.home', [
                'title' => settings('general.site_name', config('cms.name', 'TN CMS')),
            ])->render());
        }

        return $this->fallback('Home', $locale);
    }

    public function page(): Response
    {
        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        $locale = $this->resolveLocale($this->routeParam('locale'));
        $slug = (string) $this->routeParam('slug');

        $page = $this->findPublishedContent($slug, 'page', $locale);

        if ($page === null) {
            abort(404);
        }

        $this->resources->publishContent($page);
        $this->seo->forContent($page, $locale);

        if (! View::exists('theme::pages.page')) {
            return $this->fallback($page->translatedTitle($locale), $locale);
        }

        return response($this->renderContent('theme::pages.page', $page, $locale));
    }

    public function post(): Response
    {
        $this->tagPageType('content');

        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        $locale = $this->resolveLocale($this->routeParam('locale'));
        $slug = (string) $this->routeParam('slug');

        $post = $this->findPublishedContent($slug, 'post', $locale);

        if ($post === null) {
            abort(404);
        }

        $this->resources->publishContent($post);
        $this->seo->forContent($post, $locale);

        if (! View::exists('theme::posts.post')) {
            return $this->fallback($post->translatedTitle($locale), $locale);
        }

        return response($this->renderContent('theme::posts.post', $post, $locale));
    }

    public function category(): Response
    {
        $this->tagPageType('category');

        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        return $this->renderArchive(
            (string) $this->routeParam('slug'),
            'category',
            'Danh mục',
            $this->resolveLocale($this->routeParam('locale')),
        );
    }

    public function tag(): Response
    {
        $this->tagPageType('tag');

        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        return $this->renderArchive(
            (string) $this->routeParam('slug'),
            'tag',
            'Thẻ',
            $this->resolveLocale($this->routeParam('locale')),
        );
    }

    /**
     * Generic single-segment resolver for the catch-all /{slug} (and the
     * localized /{locale}/{slug}) route. Resolves the path against cms_slugs
     * (the public-slug source of truth) and dispatches to the right renderer:
     *
     * - content → page or post (by content type)
     * - term    → category or tag archive (by taxonomy type)
     *
     * This is how base-less posts/categories/tags resolve when their permalink
     * base is empty (their cms_slugs full_path is the bare slug). Records with a
     * non-empty base keep a prefixed full_path, so a bare /{slug} never reaches
     * them — only pages and base-less records resolve here. Falls back to a
     * page lookup for records without a cms_slugs row, else 404.
     */
    public function resolveSlug(): Response
    {
        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        $locale = $this->resolveLocale($this->routeParam('locale'));
        $slug = (string) $this->routeParam('slug');

        $reference = $this->resolvePublicReference($slug, $locale);

        if ($reference !== null) {
            $resolved = $this->renderReference($reference['reference_type'], $reference['reference_id'], $locale);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        // Fallback: a published page whose cms_slugs row is missing. Page-only,
        // so it stays unambiguous.
        $page = $this->findPublishedContent($slug, 'page', $locale);

        if ($page !== null) {
            $this->resources->publishContent($page);
            $this->seo->forContent($page, $locale);

            if (! View::exists('theme::pages.page')) {
                return $this->fallback($page->translatedTitle($locale), $locale);
            }

            return response($this->renderContent('theme::pages.page', $page, $locale));
        }

        abort(404);
    }

    /**
     * Resolve a public path to its cms_slugs reference metadata, served from the
     * public-content cache when the request is cacheable (guest GET, no preview).
     * Returns null when no public row matches.
     *
     * @return array{reference_type: string, reference_id: int}|null
     */
    private function resolvePublicReference(string $slug, string $locale): ?array
    {
        $resolver = function () use ($slug, $locale): ?array {
            $row = $this->slugs->findPublic($slug, $locale);

            if ($row === null) {
                return null;
            }

            return [
                'reference_type' => (string) $row->reference_type,
                'reference_id' => (int) $row->reference_id,
            ];
        };

        $request = request();

        if (! $this->publicCache->shouldCache($request)) {
            return $resolver();
        }

        return $this->publicCache->rememberResolution(
            $locale,
            $this->publicCache->normalizePath($request),
            $resolver,
        );
    }

    /**
     * Render the record a cms_slugs reference points at, or null when it is
     * missing / unpublished (so the caller can fall back).
     */
    /**
     * Tag the current request with its public page type for the query budget /
     * profiler middleware (v1.0.0-beta.6.4.2). Purely diagnostic; never throws.
     */
    private function tagPageType(string $type): void
    {
        request()->attributes->set('cms.page_type', $type);
    }

    private function renderReference(string $referenceType, int $referenceId, string $locale): ?Response
    {
        if ($referenceType === 'content') {
            $content = Content::query()->find($referenceId);

            if ($content === null || $content->status !== 'published') {
                return null;
            }

            $this->tagPageType('content');
            $this->resources->publishContent($content);
            $this->seo->forContent($content, $locale);

            $view = $content->type === 'post' ? 'theme::posts.post' : 'theme::pages.page';

            if (! View::exists($view)) {
                return $this->fallback($content->translatedTitle($locale), $locale);
            }

            return response($this->renderContent($view, $content, $locale));
        }

        if ($referenceType === 'term') {
            $term = Term::query()->with('taxonomy')->find($referenceId);

            if ($term === null) {
                return null;
            }

            $type = $term->taxonomy?->type ?? 'category';
            $label = $type === 'tag' ? 'Thẻ' : 'Danh mục';

            $this->tagPageType($type === 'tag' ? 'tag' : 'category');

            return $this->renderTermArchive($term, $type, $label, $locale);
        }

        return null;
    }

    /**
     * Read a parameter from the matched route by NAME. Laravel binds route
     * parameters to method arguments positionally (by URI order), which is
     * ambiguous when the same action serves both the unprefixed and the
     * {locale}-prefixed routes — so we read slug/locale by name here instead.
     */
    private function routeParam(string $name): ?string
    {
        $value = request()->route($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalise the locale, set it as the current request locale, and 404 when
     * an explicitly-provided locale is not active.
     */
    private function resolveLocale(?string $locale): string
    {
        $code = $this->languages->normalizeCode($locale);

        if ($locale !== null && $locale !== '' && ! $this->languages->isActive($code)) {
            abort(404);
        }

        $this->languages->setCurrent($code);

        // Remember a logged-in user's FRONTEND reading language, independent of
        // their admin language (v1.0.0-beta.7.1.10). Only on an explicit route
        // locale, so the unprefixed default path never overwrites the choice.
        // Route prefix stays authoritative — this is preference storage only.
        if ($locale !== null && $locale !== '') {
            try {
                $request = request();
                app('cms.locale_preference')->persistFrontendLocale($request, $request->user(), $code);
            } catch (\Throwable) {
                // Best-effort: a preference write must never break the page.
            }
        }

        return $code;
    }

    /**
     * Resolve a preset/builder section homepage for the active theme. Returns a
     * rendered Response when a section layout applies and the theme provides
     * `theme::pages.home`, otherwise null so the caller falls back to the legacy
     * homepage behavior. Layout resolution is best-effort — any failure returns
     * null rather than breaking the homepage.
     */
    private function resolveHomepageSections(string $locale): ?Response
    {
        try {
            $resolved = $this->homepage->resolve($locale);
        } catch (\Throwable) {
            return null;
        }

        if ($resolved === null || $resolved->isEmpty()) {
            return null;
        }

        if (! View::exists('theme::pages.home')) {
            return null;
        }

        return response(View::make('theme::pages.home', [
            'title' => (string) settings('general.site_name', config('cms.name', 'TN CMS')),
            'sections' => $resolved->sections,
        ])->render());
    }

    /**
     * Locate the home content (the page whose default-locale slug is
     * "trang-chu") so it can be rendered in any locale.
     */
    private function findHomeContent(string $locale): ?Content
    {
        $home = $this->contents->findBySlug('trang-chu', $this->languages->defaultCode(), 'page');

        if ($home === null || $home->status !== 'published') {
            return null;
        }

        return $home;
    }

    /**
     * Resolve the static homepage chosen in Settings → Reading
     * (reading.homepage_page_id), if it is a published page.
     */
    private function findStaticHomePage(): ?Content
    {
        $id = (int) settings('reading.homepage_page_id', 0);

        if ($id <= 0) {
            return null;
        }

        $page = Content::query()
            ->where('type', 'page')
            ->whereKey($id)
            ->first();

        if ($page === null || $page->status !== 'published') {
            return null;
        }

        return $page;
    }

    /**
     * Render the latest published posts on the homepage, using the theme's home
     * view when present, otherwise the archive listing. Returns null when the
     * theme provides neither (so the caller can fall back).
     */
    private function renderLatestPosts(string $locale): ?Response
    {
        $perPage = (int) settings('reading.posts_per_page', 10);

        if ($perPage < 1) {
            $perPage = 10;
        }

        $posts = Content::query()
            ->where('type', 'post')
            ->where('status', 'published')
            ->whereHas('translations', fn ($q) => $q->where('locale', $locale))
            ->with('translations')
            ->orderByDesc('published_at')
            ->limit($perPage)
            ->get();

        $title = (string) settings('general.site_name', config('cms.name', 'TN CMS'));

        if (View::exists('theme::pages.home')) {
            return response(View::make('theme::pages.home', [
                'title' => $title,
                'posts' => $posts,
            ])->render());
        }

        if (View::exists('theme::archives.index')) {
            return response(View::make('theme::archives.index', [
                'term' => null,
                'title' => $title,
                'posts' => $posts,
            ])->render());
        }

        return null;
    }

    /**
     * Find a single published page/post by slug in the given locale.
     */
    private function findPublishedContent(string $slug, string $type, string $locale): ?Content
    {
        $content = $this->contents->findBySlug($slug, $locale, $type);

        if ($content === null || $content->status !== 'published') {
            return null;
        }

        return $content;
    }

    /**
     * Render a single content item (page or post) with its translation data.
     */
    private function renderContent(string $view, Content $content, string $locale): string
    {
        $translation = $this->contents->getTranslation($content, $locale);

        // Hooks & Shortcodes (v1.0.0-beta.7.1.11). The stored body is already
        // sanitized at write time (and the theme sanitizes again on output), so
        // here we run the render-time pipeline: title/excerpt/body filters, then
        // expand registered shortcodes between before/after_shortcode filters.
        $ctx = hook_context(['content' => $content, 'locale' => $locale]);

        $title = apply_filters('cms.content.title', $content->translatedTitle($locale), $content, $ctx);
        $excerpt = apply_filters('cms.content.excerpt', $translation?->excerpt, $content, $ctx);

        $body = apply_filters('cms.content.body', $translation?->content, $content, $ctx);
        $body = apply_filters('cms.content.before_shortcode', $body, $content, $ctx);
        $body = do_shortcode($body, ['content' => $content, 'locale' => $locale, '_hook_context' => $ctx]);
        $body = apply_filters('cms.content.after_shortcode', $body, $content, $ctx);

        $data = [
            'content' => $content,
            'title' => $title,
            'body' => $body,
            'excerpt' => $excerpt,
            'featuredImage' => $content->featured_image,
            'publishedAt' => $content->published_at,
            // Page-title visibility contract (PB-FREE-LIBRARY-DESIGN-1-E-H1). A
            // generic presentation flag the theme consumes to decide whether the
            // visible entry title (the document primary heading) is rendered. It
            // never affects the SEO <title>, canonical URL, navigation, or the
            // stored title — only the visible heading. Defaults to Show for
            // backward compatibility (legacy rows and non-page types).
            'showPageTitle' => (bool) ($content->show_page_title ?? true),
        ];

        // Post-only meta consumed by the single-post layout (Phase 8A). Provided
        // here (controller layer) so the theme never queries the database; the
        // theme toggles visibility via show_post_author / show_post_tags.
        if ($content->type === 'post') {
            $data['author'] = $content->author;
            // Strictly per-locale tags: only tags that actually have a
            // translation (name + slug) in the CURRENT locale are exposed to the
            // theme. Foreign-locale tags kept attached for preservation
            // (see PostResource::mergeTermIds) are excluded so the frontend never
            // renders another locale's label or a "Term #id" placeholder.
            $data['tags'] = $content->terms()
                ->whereHas('taxonomy', fn ($q) => $q->where('type', 'tag'))
                ->whereHas('translations', fn ($q) => $q->where('locale', $locale)
                    ->whereNotNull('slug')->where('slug', '!=', '')
                    ->whereNotNull('name')->where('name', '!=', ''))
                ->with(['taxonomy', 'translations' => fn ($q) => $q->where('locale', $locale)])
                ->get();
        }

        $html = View::make($view, $data)->render();

        // Fire the post/page rendered action so extensions can react after the
        // body is built (v1.0.0-beta.7.1.11).
        do_action($content->type === 'post' ? 'cms.post.rendered' : 'cms.page.rendered', $content, $ctx);

        return $html;
    }

    /**
     * Render a single page/post for a signed PREVIEW (v1.0.0-beta.7.1.12.1).
     *
     * This is the ONLY public seam the core PreviewManager renderer needs. It
     * REUSES the exact same pipeline as the published page — SEO, hooks,
     * shortcodes, theme view, layout — through {@see renderContent()}, but
     * WITHOUT the published-status gate (the caller already authorised the
     * preview via a signed URL). No rendering, SEO, or layout logic is
     * duplicated; the preview and the published page render identically.
     */
    public function renderPreviewable(Content $content, ?string $locale = null): Response
    {
        if (($noTheme = $this->noThemeResponse()) !== null) {
            return $noTheme;
        }

        $locale = $this->resolvePreviewLocale($content, $locale);

        $this->resources->publishContent($content);
        $this->seo->forContent($content, $locale);

        $view = $content->type === 'post' ? 'theme::posts.post' : 'theme::pages.page';

        if (! View::exists($view)) {
            return $this->fallback($content->translatedTitle($locale), $locale);
        }

        return response($this->renderContent($view, $content, $locale));
    }

    /**
     * Locale for a preview render: an explicit ACTIVE locale wins; otherwise the
     * default language when the record has that translation, else the record's
     * first available translation, else the default. Never 404s (unlike the
     * public resolveLocale) — a signed preview should always try to render.
     */
    private function resolvePreviewLocale(Content $content, ?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            $code = $this->languages->normalizeCode($explicit);

            if ($this->languages->isActive($code)) {
                $this->languages->setCurrent($code);

                return $code;
            }
        }

        $default = $this->languages->defaultCode();

        if ($content->hasTranslation($default)) {
            $code = $default;
        } else {
            $code = $content->translations()->first()?->locale ?? $default;
        }

        $this->languages->setCurrent($code);

        return $code;
    }

    /**
     * Render a category/tag archive listing its published posts.
     */
    private function renderArchive(string $slug, string $taxonomySlug, string $labelPrefix, string $locale): Response
    {
        $term = $this->taxonomies->findTermBySlug($slug, $locale, $taxonomySlug);

        if ($term === null) {
            abort(404);
        }

        return $this->renderTermArchive($term, $taxonomySlug, $labelPrefix, $locale);
    }

    /**
     * Render a resolved term's archive listing its published posts.
     */
    private function renderTermArchive(Term $term, string $taxonomySlug, string $labelPrefix, string $locale): Response
    {
        $this->resources->publishTerm($term, $taxonomySlug);
        $this->seo->forArchive($term, $taxonomySlug, $locale);

        // Bounded query (v1.0.0-beta.6.3): paginate instead of loading every
        // published post in the term into memory. Reads ?page= from the request
        // and preserves the existing query string on pagination links.
        $posts = $term->contents()
            ->where('type', 'post')
            ->where('status', 'published')
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderByDesc('published_at')
            ->paginate($this->archivePerPage())
            ->withQueryString();

        $title = $labelPrefix.': '.$term->displayName($locale);

        if (! View::exists('theme::archives.index')) {
            return $this->fallback($title, $locale);
        }

        return response(View::make('theme::archives.index', [
            'term' => $term,
            'title' => $title,
            'posts' => $posts,
        ])->render());
    }

    /**
     * Posts-per-page for archives: reading.posts_per_page (default 10), floored
     * at 1 (invalid values fall back to 10) and hard-capped at 100 to bound the
     * query so a huge term can never load unbounded rows into memory.
     */
    private function archivePerPage(): int
    {
        $perPage = (int) settings('reading.posts_per_page', 10);

        if ($perPage < 1) {
            $perPage = 10;
        }

        return min($perPage, 100);
    }

    /**
     * Guard for the "no valid theme at all" state. Returns a friendly 503 when
     * the theme system cannot resolve an effective active theme (no valid theme
     * folder on disk), so the frontend never throws a raw exception or silently
     * renders without a theme. Returns null when a theme is available (normal
     * rendering proceeds). A missing *view* within a present theme is handled
     * separately by fallback().
     */
    private function noThemeResponse(): ?Response
    {
        try {
            if (app('cms.theme')->active() !== null) {
                return null;
            }
        } catch (\Throwable) {
            // Treat a broken theme system as "no theme" rather than a 500.
        }

        $brand = (string) settings('general.site_name', config('cms.name', 'TN CMS'));

        $html = View::exists('errors.no-theme')
            ? View::make('errors.no-theme', ['brand' => $brand])->render()
            : 'No active theme found. Please activate a theme in admin.';

        return response($html, 503);
    }

    /**
     * Minimal HTML response used when no theme view is available, so the
     * frontend degrades gracefully instead of throwing a 500.
     */
    private function fallback(string $title, string $locale): Response
    {
        $site = e((string) settings('general.site_name', config('cms.name', 'TN CMS')));
        $heading = e($title);
        $lang = e($locale);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head><meta charset="utf-8"><title>{$heading} — {$site}</title></head>
        <body style="font-family:system-ui,sans-serif;max-width:48rem;margin:4rem auto;padding:0 1rem;">
            <h1>{$site}</h1>
            <p>No active theme view is available to render "<strong>{$heading}</strong>".</p>
            <p>Activate a theme under <code>Appearance &rarr; Themes</code> in the admin.</p>
        </body>
        </html>
        HTML;

        return response($html);
    }
}
