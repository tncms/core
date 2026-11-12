<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\RobotsRestriction;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;
use TheNguyen\CMS\Seo\ResourceRobotsPolicy;
use TheNguyen\CMS\Sitemap\CoreSitemapProvider;
use TheNguyen\CMS\Sitemap\SitemapManager;
use TheNguyen\CMS\Sitemap\SitemapProviderRegistry;

/**
 * Lightweight SEO foundation (Phase 7.5).
 *
 * Holds the SEO context for the current request (home / page / post /
 * category / tag) — set by the FrontendController before a theme view is
 * rendered — and resolves the meta values the theme's SEO partial outputs.
 * Also builds robots.txt and sitemap.xml content. No analyzer, no plugin.
 */
class SeoManager
{
    private const DEFAULT_LOCALE = 'vi';

    private const DEFAULT_TITLE = 'TN CMS';

    private const DEFAULT_DESCRIPTION = 'TN CMS website';

    private const DEFAULT_ROBOTS = 'index,follow';

    private const DESCRIPTION_LIMIT = 160;

    /** sitemaps.org caps a single urlset at 50,000 URLs. */
    private const SITEMAP_MAX_URLS = 50000;

    /** home|page|post|category|tag|default */
    private string $context = 'default';

    private string $locale = self::DEFAULT_LOCALE;

    private ?Content $content = null;

    private ?ContentTranslation $translation = null;

    private ?Term $term = null;

    private ?TermTranslation $termTranslation = null;

    private ?string $canonical = null;

    private ?string $customTitle = null;

    private ?string $customDescription = null;

    private ?string $robotsOverride = null;

    /** Owner-supplied override for managed content (CORE-CONTENT-EXT-R1). */
    private ?ContentSeoOverride $contentOverride = null;

    /** Owner metadata could not be obtained: site-default text only (CORE-38-TOPLIST-SEAMS-R1). */
    private bool $neutralContent = false;

    /** The Page rendered at `/` (CORE-SEO-RELEASE-HARDENING-R1, H2); robots only. */
    private ?Content $homePage = null;

    /**
     * Reset to a neutral (site-default) context.
     */
    public function reset(): self
    {
        $this->context = 'default';
        $this->locale = self::DEFAULT_LOCALE;
        $this->content = null;
        $this->translation = null;
        $this->term = null;
        $this->termTranslation = null;
        $this->canonical = null;
        $this->customTitle = null;
        $this->customDescription = null;
        $this->robotsOverride = null;
        $this->contentOverride = null;
        $this->neutralContent = false;
        $this->homePage = null;

        return $this;
    }

    public function forHome(): self
    {
        $this->reset();
        $this->context = 'home';

        $lang = app('cms.language');
        $this->locale = $lang->currentCode();
        $this->canonical = url($lang->localizedUrl($this->locale, '/'));

        // P3.3B: SEO is a pure CONSUMER of the current resource — the render
        // boundary (CurrentResourcePublisher) is the sole writer, never SEO.
        return $this;
    }

    /**
     * The Page that `/` renders (static front page or conventional home page).
     * Its per-item robots policy restricts the home robots, combined with the
     * site policy (CORE-SEO-RELEASE-HARDENING-R1, H2); title, description,
     * canonical and hreflang stay site-level. Only valid after forHome().
     */
    public function homePage(Content $page): self
    {
        if ($this->context === 'home') {
            $this->homePage = $page;
        }

        return $this;
    }

    public function forContent(Content $content, string $locale = self::DEFAULT_LOCALE): self
    {
        $this->reset();
        $this->locale = $locale;
        $this->content = $content;
        $this->translation = $content->translations()->where('locale', $locale)->first();
        $this->context = $content->type === 'post' ? 'post' : 'page';
        $this->canonical = url()->current();

        return $this;
    }

    public function forArchive(Term $term, string $type, string $locale = self::DEFAULT_LOCALE): self
    {
        $this->reset();
        $this->locale = $locale;
        $this->term = $term;
        $this->termTranslation = $term->translations()->where('locale', $locale)->first();
        $this->context = $type === 'tag' ? 'tag' : 'category';
        $this->canonical = url()->current();

        return $this;
    }

    /**
     * A paginated listing (CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1): pages 2..last
     * canonicalize to themselves, keeping only `?page=N`; page 1, invalid and
     * out-of-range pages keep the base canonical. Chain after forArchive().
     */
    public function paginated(int $page, int $lastPage): self
    {
        if ($this->canonical !== null && $page >= 2 && $page <= $lastPage) {
            $this->canonical = strtok($this->canonical, '?').'?page='.$page;
        }

        return $this;
    }

    /**
     * Set an explicit title/description for a resource that has no local
     * Content model — e.g. a plugin rendering remote content read-only. The
     * overrides win over the context-derived title()/description() (and thus
     * the OG/Twitter variants), while the rest of the SEO surface stays on
     * sensible site defaults. Empty strings are ignored so a real default can
     * still apply.
     */
    public function forCustom(string $title, string $description, ?string $canonical = null): self
    {
        $this->reset();
        $this->context = 'page';
        $this->customTitle = $title !== '' ? $title : null;
        $this->customDescription = $description !== '' ? $description : null;
        $this->canonical = $canonical ?? url()->current();

        // A custom SEO page carries NO localizable resource identity — and SEO no
        // longer writes the current-resource authority at all (P3.3B): the render
        // boundary owns publication, so a resource a plugin controller already
        // published (e.g. a Product) is never touched here.
        return $this;
    }

    /**
     * Apply a verified content owner's sanitized SEO override
     * (CORE-CONTENT-EXT-R1). Only valid after forContent(); canonical,
     * hreflang, og:url, og:type and keywords stay Core's. Robots can only
     * become more restrictive.
     */
    public function applyContentOverride(ContentSeoOverride $override): self
    {
        if ($this->content !== null && $this->customTitle === null) {
            $this->contentOverride = $override;
        }

        return $this;
    }

    /**
     * Neutral head for managed content whose owner metadata could not be
     * obtained (CORE-38-TOPLIST-SEAMS-R1): the editable Core title,
     * description, keywords and images may have drifted from what the owner
     * approved, so the site defaults are used, no social image is emitted and
     * the page is noindex. Canonical and hreflang stay Core's. Only valid
     * after forContent().
     */
    public function applyNeutralContentSeo(): self
    {
        if ($this->content !== null && $this->customTitle === null) {
            $this->neutralContent = true;
            $this->contentOverride = new ContentSeoOverride(robots: RobotsRestriction::NoIndex, suppressOgImage: true);
        }

        return $this;
    }

    /**
     * Force a non-indexable robots policy for the current context — utility
     * frontend pages such as search results and error pages that must not be
     * indexed while their links stay followable (CORE-FRONTEND-1). Chain after a
     * context setter, e.g. forCustom(...)->noindex(). The site-wide "discourage
     * search engines" toggle in robots() still wins over this override.
     */
    public function noindex(bool $follow = true): self
    {
        $this->robotsOverride = $follow ? 'noindex,follow' : 'noindex,nofollow';

        return $this;
    }

    public function title(): string
    {
        if ($this->customTitle !== null) {
            return $this->customTitle;
        }

        if ($this->neutralContent) {
            return $this->defaultTitle();
        }

        if ($this->contentOverride?->title !== null) {
            return $this->contentOverride->title;
        }

        return match ($this->context) {
            'home' => $this->defaultTitle(),
            'page', 'post' => $this->firstNonEmpty([
                $this->translation?->meta_title,
                $this->content?->translatedTitle($this->locale),
                $this->defaultTitle(),
            ]),
            'category' => $this->firstNonEmpty([
                $this->termTranslation?->meta_title,
                'Category: '.$this->termName(),
            ]),
            'tag' => $this->firstNonEmpty([
                $this->termTranslation?->meta_title,
                'Tag: '.$this->termName(),
            ]),
            default => $this->siteName(),
        };
    }

    public function description(): string
    {
        if ($this->customDescription !== null) {
            return $this->customDescription;
        }

        if ($this->neutralContent) {
            return $this->defaultDescription();
        }

        if ($this->contentOverride?->description !== null) {
            return $this->contentOverride->description;
        }

        return match ($this->context) {
            'home' => $this->defaultDescription(),
            'page', 'post' => $this->firstNonEmpty([
                $this->translation?->meta_description,
                $this->translation?->excerpt,
                $this->truncate($this->translation?->content),
                $this->defaultDescription(),
            ]),
            'category', 'tag' => $this->firstNonEmpty([
                $this->termTranslation?->meta_description,
                $this->termTranslation?->description,
                $this->defaultDescription(),
            ]),
            default => $this->defaultDescription(),
        };
    }

    public function keywords(): string
    {
        if ($this->neutralContent) {
            return '';
        }

        return match ($this->context) {
            'page', 'post' => (string) ($this->translation?->meta_keywords ?? ''),
            default => '',
        };
    }

    public function canonical(): string
    {
        return $this->canonical ?? url()->current();
    }

    public function robots(): string
    {
        // Site-wide "discourage search engines" wins over any default.
        if ((bool) settings('seo.noindex_site', false)) {
            return 'noindex,nofollow';
        }

        // A per-context override (search/error utility pages) wins over the
        // configured site default but not the site-wide discourage toggle above.
        if ($this->robotsOverride !== null) {
            $robots = $this->robotsOverride;
        } else {
            // Prefer the v0.9.6 key, then the legacy seo.robots, then the default.
            $value = settings('seo.robots_default') ?? settings('seo.robots');
            $robots = is_string($value) && $value !== '' ? $value : self::DEFAULT_ROBOTS;
        }

        // A content owner can only add restrictions (CORE-CONTENT-EXT-R1).
        $restriction = $this->contentOverride?->robots;
        $robots = $restriction !== null ? self::restrictRobots($robots, $restriction) : $robots;

        // The resource's own per-item restriction (CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1)
        // merges the same way: it can only add noindex/nofollow, never loosen. On
        // the home, the Page rendered at `/` is that resource (H2).
        $item = ResourceRobotsPolicy::restriction(($this->content ?? $this->term ?? $this->homePage)?->getAttribute(ResourceRobotsPolicy::COLUMN));
        $robots = $item !== null ? self::restrictRobots($robots, $item) : $robots;

        // A home Page with no translation in this language is not that language's
        // content (its own localized URL is 404 and it is absent from that
        // locale's sitemap), so the language's home is not indexed either. What
        // visitors see there is unchanged (CORE-RELEASE-BLOCKERS-R1, TD-R3).
        if ($this->context === 'home' && $this->homePage !== null && ! $this->homePage->hasTranslation((string) $this->locale)) {
            $robots = self::restrictRobots($robots, RobotsRestriction::NoIndex);
        }

        return $robots;
    }

    /**
     * Whether the site as a whole may be indexed: false under "discourage
     * search engines" or when the default robots policy every page inherits
     * is noindex (or none). The sitemap and robots.txt follow this decision.
     */
    public function siteIndexable(): bool
    {
        if ((bool) settings('seo.noindex_site', false)) {
            return false;
        }

        $value = settings('seo.robots_default') ?? settings('seo.robots');
        $tokens = is_string($value) ? array_map(static fn (string $t): string => strtolower(trim($t)), explode(',', $value)) : [];

        return ! in_array('noindex', $tokens, true) && ! in_array('none', $tokens, true);
    }

    /**
     * Merge per directive: noindex/nofollow from either side win; any other
     * directives in the Core value (e.g. max-image-preview) are kept.
     */
    private static function restrictRobots(string $robots, RobotsRestriction $restriction): string
    {
        $tokens = array_values(array_filter(array_map(
            static fn (string $t): string => strtolower(trim($t)),
            explode(',', $robots),
        ), static fn (string $t): bool => $t !== ''));

        $noindex = in_array('noindex', $tokens, true) || in_array('none', $tokens, true) || $restriction->noindex();
        $nofollow = in_array('nofollow', $tokens, true) || in_array('none', $tokens, true) || $restriction->nofollow();
        $other = array_values(array_diff($tokens, ['index', 'noindex', 'follow', 'nofollow', 'none', 'all']));

        return implode(',', [$noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow', ...$other]);
    }

    /**
     * Configured title separator (e.g. "|", "–"). Themes may use it to build a
     * "{title} {sep} {site}" document title. Defaults to "|".
     */
    public function titleSeparator(): string
    {
        $value = settings('seo.title_separator', '|');

        return is_string($value) && trim($value) !== '' ? $value : '|';
    }

    public function ogTitle(): string
    {
        return $this->contentOverride?->ogTitle ?? $this->title();
    }

    public function ogDescription(): string
    {
        return $this->contentOverride?->ogDescription ?? $this->description();
    }

    public function ogType(): string
    {
        return $this->context === 'post' ? 'article' : 'website';
    }

    public function ogImage(): ?string
    {
        // An owner's explicit suppression means no image at all (CORE-38-TOPLIST-SEAMS-R1).
        if ($this->contentOverride?->suppressOgImage) {
            return null;
        }

        // Owner override, then content featured image, then the configured default.
        return $this->absoluteUrl($this->contentOverride?->ogImage)
            ?? $this->absoluteUrl($this->content?->featured_image)
            ?? $this->absoluteUrl($this->stringSetting('seo.default_og_image'));
    }

    public function twitterCard(): string
    {
        return $this->ogImage() !== null ? 'summary_large_image' : 'summary';
    }

    public function twitterTitle(): string
    {
        return $this->ogTitle();
    }

    public function twitterDescription(): string
    {
        return $this->ogDescription();
    }

    public function twitterImage(): ?string
    {
        return $this->ogImage();
    }

    /**
     * All resolved SEO values for the current context.
     *
     * @return array<string, string|null>
     */
    public function current(): array
    {
        return [
            'title' => $this->title(),
            'description' => $this->description(),
            'keywords' => $this->keywords(),
            'canonical' => $this->canonical(),
            'robots' => $this->robots(),
            'og_title' => $this->ogTitle(),
            'og_description' => $this->ogDescription(),
            'og_type' => $this->ogType(),
            'og_url' => $this->canonical(),
            'og_image' => $this->ogImage(),
            'twitter_card' => $this->twitterCard(),
            'twitter_title' => $this->twitterTitle(),
            'twitter_description' => $this->twitterDescription(),
            'twitter_image' => $this->twitterImage(),
            'site_name' => $this->siteName(),
        ];
    }

    /** Current SEO context: home|page|post|category|tag|default. */
    public function contextType(): string
    {
        return $this->context;
    }

    public function contextContent(): ?Content
    {
        return $this->content;
    }

    public function contextTerm(): ?Term
    {
        return $this->term;
    }

    /**
     * hreflang alternates for the current context. One entry per active
     * language that has a valid URL, plus an x-default pointing at the default
     * language. Returns absolute URLs.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function alternates(): array
    {
        // CORE-L10N.1B: hreflang alternates flow through the same Localization Platform pipeline
        // as the language switcher and canonical — SeoManager no longer knows page/post/category/
        // tag. A target that is unavailable for a locale (no eligible translation, or a context
        // no resolver supports) is skipped, so behaviour is byte-identical to the legacy builder.
        $service = app('cms.localization.switch_targets');
        $generator = app('cms.localization.url_generator');
        $defaultCode = app('cms.language')->defaultCode();

        // P3.3: the SAME current-resource authority the switcher uses — SeoManager
        // no longer builds its own LocalizationContext from private content/term.
        $context = app('cms.localization.context_factory')->current();

        $out = [];
        $defaultHref = null;

        foreach ($service->targets($context) as $target) {
            if (! $target->available) {
                continue;
            }

            $absolute = $generator->absolute($target->url);
            $out[] = ['hreflang' => $target->locale, 'href' => $absolute];

            if ($target->locale === $defaultCode) {
                $defaultHref = $absolute;
            }
        }

        if ($defaultHref !== null && count($out) > 1) {
            $out[] = ['hreflang' => 'x-default', 'href' => $defaultHref];
        }

        return $out;
    }

    /**
     * Plain-text robots.txt body.
     */
    public function robotsTxt(): string
    {
        $siteUrl = rtrim(url('/'), '/');

        // "Discourage search engines" → disallow the whole site.
        if ((bool) settings('seo.noindex_site', false)) {
            return "User-agent: *\nDisallow: /\n";
        }

        // A noindex default, or the operator switching the sitemap off
        // (CORE-SITEMAP-ADMIN-R1), disables it: do not advertise it.
        if (! $this->siteIndexable() || ! app('cms.sitemap_settings')->enabled()) {
            return "User-agent: *\nAllow: /\n";
        }

        return "User-agent: *\nAllow: /\n\nSitemap: {$siteUrl}/sitemap.xml\n";
    }

    /**
     * Core sitemap entries as one flat list (home, pages, posts, categories,
     * tags per public locale), capped at 50,000.
     *
     * @deprecated CORE-SITEMAP-R1: /sitemap.xml is an index served by
     *             `cms.sitemap`. Kept for existing callers; it is a bounded
     *             projection of the Core sitemap provider (same visibility
     *             rules) read in keyset batches.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public function sitemap(): array
    {
        return settings()->withinReadScope(fn (): array => $this->legacySitemapEntries());
    }

    /** @return list<array{loc: string, lastmod: string|null}> */
    private function legacySitemapEntries(): array
    {
        $provider = app(CoreSitemapProvider::class);
        $policy = app('cms.sitemap_settings');
        $entries = [];

        foreach ($provider->types() as $type) {
            if (! $policy->typeIncluded(SitemapProviderRegistry::CORE, $type)) {
                continue;
            }

            foreach (SitemapManager::locales() as $locale) {
                $after = null;

                while (true) {
                    foreach ($provider->entries($type, $locale, $after, null, 1000) as $entry) {
                        $entries[] = ['loc' => $entry->loc, 'lastmod' => $this->lastmod($entry->lastmod)];

                        if (count($entries) >= self::SITEMAP_MAX_URLS) {
                            return $entries;
                        }
                    }

                    $after = $provider->keyAt($type, $locale, $after, 999);

                    if ($after === null) {
                        break;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Render the sitemap entries as a urlset XML document.
     *
     * @deprecated CORE-SITEMAP-R1: no longer routed; see {@see sitemap()}.
     */
    public function sitemapXml(): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($this->sitemap() as $entry) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($entry['loc'], ENT_XML1).'</loc>';

            if ($entry['lastmod'] !== null) {
                $lines[] = '    <lastmod>'.$entry['lastmod'].'</lastmod>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    private function siteName(): string
    {
        $value = setting_localized('general.site_name', $this->locale);

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_TITLE;
    }

    private function siteDescription(): string
    {
        $value = setting_localized('general.site_description', $this->locale);

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_DESCRIPTION;
    }

    /**
     * Default document title: seo.default_meta_title → site name → constant.
     */
    private function defaultTitle(): string
    {
        return $this->firstNonEmpty([
            $this->localizedStringSetting('seo.default_meta_title'),
            $this->siteName(),
        ]);
    }

    /**
     * Default meta description: seo.default_meta_description → site description.
     */
    private function defaultDescription(): string
    {
        return $this->firstNonEmpty([
            $this->localizedStringSetting('seo.default_meta_description'),
            $this->siteDescription(),
        ]);
    }

    /**
     * Read a setting as a trimmed non-empty string, or null.
     */
    private function stringSetting(string $key): ?string
    {
        $value = settings($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Read a localized setting (for the current SEO context locale) as a trimmed
     * non-empty string, or null. Falls back locale → default → global.
     */
    private function localizedStringSetting(string $key): ?string
    {
        $value = setting_localized($key, $this->locale);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function termName(): string
    {
        return $this->term?->displayName($this->locale) ?? '';
    }

    /**
     * First non-empty string in the list, or '' if none.
     *
     * @param  array<int, string|null>  $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Collapse whitespace and clip to the description length budget.
     */
    private function truncate(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($clean === '') {
            return '';
        }

        return mb_substr($clean, 0, self::DESCRIPTION_LIMIT);
    }

    /**
     * Turn a stored (possibly root-relative) media path into an absolute URL.
     */
    private function absoluteUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        // Already absolute with a scheme — use as-is.
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        // Protocol-relative (//cdn/...) — promote to https so social-card
        // crawlers (which require an absolute scheme) accept it.
        if (str_starts_with($path, '//')) {
            return 'https:'.$path;
        }

        return url($path);
    }

    private function lastmod(mixed $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format(\DateTimeInterface::ATOM);
        }

        return null;
    }
}
