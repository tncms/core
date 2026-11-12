<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Content\Extension\ContentSitemapPolicy;
use TheNguyen\CMS\Content\Extension\FrontPage;
use TheNguyen\CMS\Content\Extension\LocalizedContentSitemapPolicy;
use TheNguyen\CMS\Content\Extension\OwnerState;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;
use TheNguyen\CMS\Seo\ResourceRobotsPolicy;
use Throwable;

/**
 * Core's own sitemap entries: the localized home page, published discoverable
 * pages and posts (CONTENT-EXT ownership and availability applied in SQL), and
 * category/tag archives. URLs and alternates come from the resolver authority
 * (`cms.localization.content_url`), the same one on-page hreflang uses.
 */
final class CoreSitemapProvider extends KeysetSitemapProvider
{
    private const CONTENT_TYPES = ['pages' => 'page', 'posts' => 'post'];

    private const TERM_TYPES = ['categories' => 'category', 'tags' => 'tag'];

    private bool $frontPageResolved = false;

    private ?int $frontPageId = null;

    public function types(): array
    {
        return ['home', 'pages', 'posts', 'categories', 'tags'];
    }

    public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int
    {
        if ($type === 'home') {
            return $after === null && $offset === 0 && $this->isPublicLocale($locale) && ! $this->homeNoindex($locale) ? 1 : null;
        }

        return parent::keyAt($type, $locale, $after, $offset);
    }

    public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable
    {
        if ($type === 'home') {
            return $this->keyAt('home', $locale, $after, 0) === 1 && ($until === null || $until >= 1)
                ? [$this->homeEntry($locale)]
                : [];
        }

        return parent::entries($type, $locale, $after, $until, $limit);
    }

    protected function query(string $type, string $locale): ?Builder
    {
        if (! $this->isPublicLocale($locale)) {
            return null;
        }

        if (isset(self::CONTENT_TYPES[$type]) && $this->tables('cms_contents', 'cms_content_translations')) {
            $query = $this->eligibleContent(self::CONTENT_TYPES[$type], $locale)
                ->select(['cms_contents.id', 'cms_contents.type', 'cms_contents.status', 'cms_contents.updated_at', 'cms_contents.published_at']);

            // The page rendered at `/` is the home entry; its own slug URL would
            // be a duplicate of it (CORE-SITEMAP-S02).
            if ($type === 'pages' && ($front = $this->frontPageId()) !== null) {
                $query->where('cms_contents.id', '!=', $front);
            }

            return $this->withoutNoindexItems($query, 'cms_contents');
        }

        if (isset(self::TERM_TYPES[$type]) && $this->tables('cms_terms', 'cms_term_translations', 'cms_taxonomies', 'cms_content_terms')) {
            $query = Term::query();
            $query->whereHas('taxonomy', static fn ($q) => $q->where('type', self::TERM_TYPES[$type]));
            $query->select(['cms_terms.id', 'cms_terms.taxonomy_id', 'cms_terms.updated_at'])
                ->whereExists(static fn ($q) => $q->selectRaw('1')->from('cms_term_translations as sm_t')
                    ->whereColumn('sm_t.term_id', 'cms_terms.id')
                    ->where('sm_t.locale', $locale)
                    ->where('sm_t.slug', '!=', ''))
                // An archive is listed only when it has at least one post that is
                // itself listed in this locale, decided live in SQL, never from
                // stored counters (CORE-SITEMAP-S02).
                ->whereExists($this->eligibleContent('post', $locale)
                    ->selectRaw('1')
                    ->join('cms_content_terms as sm_ct', 'sm_ct.content_id', '=', 'cms_contents.id')
                    ->whereColumn('sm_ct.term_id', 'cms_terms.id')
                    ->toBase());

            // The archive's OWN robots policy decides its listing; a noindex post
            // still counts toward its archive's presence above (public content).
            return $this->withoutNoindexItems($query, 'cms_terms');
        }

        return null;
    }

    /**
     * Content listed in a locale: the same publication + CONTENT-EXT visibility
     * as Content::published()->discoverable(), with an exact-locale slug.
     * Soft-deleted rows are excluded by the model scope.
     */
    private function eligibleContent(string $contentType, string $locale): Builder
    {
        $query = Content::query();
        $active = app('cms.content_ownership')->activeOwnerSlugs();
        $query->where('cms_contents.type', $contentType)
            ->published()
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('cms_content_translations as sm_t')
                ->whereColumn('sm_t.content_id', 'cms_contents.id')
                ->where('sm_t.locale', $locale)
                ->where('sm_t.slug', '!=', '')
                // core_render of an inactive owner's content needs a body in THIS
                // locale, exactly like the page (CORE-UNIFIED-OWNERSHIP-R1).
                ->where(static fn ($w) => $w->whereNull('cms_contents.managed_by')
                    ->orWhereIn('cms_contents.managed_by', $active)
                    ->orWhere(static fn ($b) => $b->whereNotNull('sm_t.content')->where('sm_t.content', '!=', ''))));

        return app('cms.content_ownership')->constrainDiscoverable($query, $locale);
    }

    /**
     * Leave out items whose per-item robots policy is noindex
     * (CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1). Applied only to the listed rows,
     * never to the archive-presence subquery.
     */
    private function withoutNoindexItems(Builder $query, string $table): Builder
    {
        $column = $table.'.'.ResourceRobotsPolicy::COLUMN;

        return $query->where(static fn ($q) => $q->whereNull($column)
            ->orWhereNotIn($column, ResourceRobotsPolicy::NOINDEX_VALUES));
    }

    /**
     * Whether the Page rendered at `/` is noindex: the home then follows that
     * policy and has no entry (CORE-SEO-RELEASE-HARDENING-R1, H2). Saving the
     * page or the front-page selection invalidates Core's manifest. A front
     * Page without a translation in $locale makes that language's home noindex
     * too, so it has no entry there (CORE-RELEASE-BLOCKERS-R1, TD-R3).
     */
    private function homeNoindex(string $locale): bool
    {
        $front = $this->frontPageId();

        if ($front === null) {
            return false;
        }

        $page = Content::query()->find($front);

        if ($page === null) {
            return false;
        }

        return in_array($page->getAttribute(ResourceRobotsPolicy::COLUMN), ResourceRobotsPolicy::NOINDEX_VALUES, true)
            || ! $page->hasTranslation($locale);
    }

    /** Resolved once per provider instance (one instance per sitemap build or child). */
    private function frontPageId(): ?int
    {
        if (! $this->frontPageResolved) {
            $this->frontPageId = FrontPage::renderedAtRootId();
            $this->frontPageResolved = true;
        }

        return $this->frontPageId;
    }

    protected function entriesFor(Collection $rows, string $type, string $locale): iterable
    {
        $locales = app('cms.language')->getPublicLocales();

        if (isset(self::CONTENT_TYPES[$type])) {
            $this->attachTranslations($rows, ContentTranslation::class, 'content_id', $locales);
            $excluded = $this->ownerExclusions($rows->modelKeys(), $locale);
        } else {
            $rows->load('taxonomy:id,type');
            $this->attachTranslations($rows, TermTranslation::class, 'term_id', $locales);
            $excluded = [];
        }

        $urls = app('cms.localization.content_url');

        foreach ($rows as $row) {
            if (isset($excluded[(int) $row->getKey()])) {
                continue;
            }

            $loc = $urls->absoluteForResource($row, $locale);

            if ($loc === null) {
                continue;
            }

            $alternates = [];
            foreach ($locales as $code) {
                $alternate = $code === $locale ? $loc : $urls->absoluteForResource($row, $code);
                if ($alternate !== null) {
                    $alternates[$code] = $alternate;
                }
            }

            yield new SitemapEntry(
                (int) $row->getKey(),
                $loc,
                $row->getAttribute('updated_at') ?? $row->getAttribute('published_at'),
                $this->withDefault($alternates),
            );
        }
    }

    private function homeEntry(string $locale): SitemapEntry
    {
        $language = app('cms.language');
        $alternates = [];

        foreach ($language->getPublicLocales() as $code) {
            // Policy A: a locale whose `/` is the translation-unavailable 404 is not an alternate.
            if ($code === $locale || ! FrontPage::staticHomeUnavailableIn($code)) {
                $alternates[$code] = url($language->localizedUrl($code, '/'));
            }
        }

        return new SitemapEntry(1, $alternates[$locale], null, $this->withDefault($alternates));
    }

    /**
     * One query per batch with only the URL columns (no bodies).
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $locales
     */
    private function attachTranslations(Collection $rows, string $model, string $foreignKey, array $locales): void
    {
        // Body-like columns are selected as NULL: translation adapters read them
        // by name, and ContentTranslation::$content is also a relation that
        // would otherwise lazy-load the parent per row.
        $nulls = $model === ContentTranslation::class ? ['title', 'excerpt', 'content', 'meta_title', 'meta_description'] : ['name', 'description', 'meta_title', 'meta_description'];
        $byOwner = $model::query()
            ->select(['id', $foreignKey, 'locale', 'slug'])
            ->addSelect(array_map(static fn (string $c) => DB::raw('NULL as '.$c), $nulls))
            ->whereIntegerInRaw($foreignKey, $rows->modelKeys())
            ->whereIn('locale', $locales)
            ->get()
            ->groupBy($foreignKey);

        $empty = (new $model)->newCollection();

        foreach ($rows as $row) {
            $row->setRelation('translations', $byOwner->get($row->getKey(), $empty));
        }
    }

    /**
     * Owner noindex exclusions: one marker query, then one policy call per
     * active owner (ADR D9). Owners with an SEO provider but no
     * ContentSitemapPolicy, and throwing policies, exclude their ids. A
     * LocalizedContentSitemapPolicy is asked for the chunk locale
     * (CORE-38-TOPLIST-SEAMS-R1).
     *
     * @param  list<int|string>  $ids
     * @return array<int, true>
     */
    private function ownerExclusions(array $ids, string $locale): array
    {
        $byOwner = [];
        foreach (app('cms.content_ownership')->markersFor(array_map('intval', $ids)) as $id => $marker) {
            if ($marker->state === OwnerState::Active) {
                $byOwner[$marker->owner][] = (int) $id;
            }
        }

        $excluded = [];
        foreach ($byOwner as $owner => $ownedIds) {
            try {
                $policy = app('cms.content_seo')->providerFor($owner);

                if ($policy === null) {
                    continue; // no SEO provider: the owner cannot restrict robots
                }

                // A provider that can restrict robots but cannot answer in
                // batch would make the sitemap guess: fail closed.
                $drop = match (true) {
                    $policy instanceof LocalizedContentSitemapPolicy => array_intersect($ownedIds, array_map('intval', $policy->sitemapExcludedIdsForLocale($ownedIds, $locale))),
                    $policy instanceof ContentSitemapPolicy => array_intersect($ownedIds, array_map('intval', $policy->sitemapExcludedIds($ownedIds))),
                    default => $ownedIds,
                };
            } catch (Throwable $e) {
                Log::warning('TN CMS sitemap policy failed', ['owner' => $owner, 'exception' => $e::class]);
                $drop = $ownedIds;
            }

            foreach ($drop as $id) {
                $excluded[(int) $id] = true;
            }
        }

        return $excluded;
    }

    /**
     * @param  array<string, string>  $alternates
     * @return array<string, string>
     */
    private function withDefault(array $alternates): array
    {
        $default = app('cms.language')->defaultCode();

        if (count($alternates) > 1 && isset($alternates[$default])) {
            $alternates['x-default'] = $alternates[$default];
        }

        return $alternates;
    }

    private function isPublicLocale(string $locale): bool
    {
        return in_array($locale, app('cms.language')->getPublicLocales(), true);
    }

    private function tables(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
