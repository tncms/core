<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;

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

    public function title(): string
    {
        if ($this->customTitle !== null) {
            return $this->customTitle;
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

        // Prefer the v0.9.6 key, then the legacy seo.robots, then the default.
        $value = settings('seo.robots_default') ?? settings('seo.robots');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_ROBOTS;
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
        return $this->title();
    }

    public function ogDescription(): string
    {
        return $this->description();
    }

    public function ogType(): string
    {
        return $this->context === 'post' ? 'article' : 'website';
    }

    public function ogImage(): ?string
    {
        // Content featured image first, then the configured default OG image.
        return $this->absoluteUrl($this->content?->featured_image)
            ?? $this->absoluteUrl($this->stringSetting('seo.default_og_image'));
    }

    public function twitterCard(): string
    {
        return $this->ogImage() !== null ? 'summary_large_image' : 'summary';
    }

    public function twitterTitle(): string
    {
        return $this->title();
    }

    public function twitterDescription(): string
    {
        return $this->description();
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

        return "User-agent: *\nAllow: /\n\nSitemap: {$siteUrl}/sitemap.xml\n";
    }

    /**
     * Ordered list of sitemap entries (homepage, pages, posts, categories,
     * tags). Only published content is included. Defensive: returns at least
     * the homepage even before the content tables exist.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public function sitemap(): array
    {
        $lang = app('cms.language');
        $codes = $lang->getPublicLocales();

        if ($codes === []) {
            $codes = [self::DEFAULT_LOCALE];
        }

        // One homepage entry per active language (localized home).
        $entries = [];
        foreach ($codes as $code) {
            $entries[] = ['loc' => url($lang->localizedUrl($code, '/')), 'lastmod' => null];
        }

        if (! Schema::hasTable('cms_contents') || ! Schema::hasTable('cms_content_translations')) {
            return $entries;
        }

        $contents = Content::query()
            ->whereIn('type', ['page', 'post'])
            ->where('status', 'published')
            ->with('translations')
            ->orderByDesc('updated_at')
            ->limit(self::SITEMAP_MAX_URLS)
            ->get();

        foreach ($contents as $content) {
            foreach ($codes as $code) {
                // Only published translations that actually exist for the locale.
                if ($content->localeSlug($code) === null) {
                    continue;
                }

                $entries[] = [
                    'loc' => url(content_url($content, $code)),
                    'lastmod' => $this->lastmod($content->updated_at ?? $content->published_at),
                ];
            }
        }

        if (Schema::hasTable('cms_terms')
            && Schema::hasTable('cms_term_translations')
            && Schema::hasTable('cms_taxonomies')) {
            $terms = Term::query()
                ->whereHas('taxonomy', fn ($q) => $q->whereIn('type', ['category', 'tag']))
                ->with(['taxonomy', 'translations'])
                ->limit(self::SITEMAP_MAX_URLS)
                ->get();

            foreach ($terms as $term) {
                foreach ($codes as $code) {
                    if ($term->localeSlug($code) === null) {
                        continue;
                    }

                    $entries[] = [
                        'loc' => url(term_url($term, $code)),
                        'lastmod' => $this->lastmod($term->updated_at),
                    ];
                }
            }
        }

        return array_slice($entries, 0, self::SITEMAP_MAX_URLS);
    }

    /**
     * Render the sitemap entries as a urlset XML document.
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
