<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Content\Extension\ContentSitemapPolicy;
use TheNguyen\CMS\Content\Extension\OwnerState;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;
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

    public function types(): array
    {
        return ['home', 'pages', 'posts', 'categories', 'tags'];
    }

    public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int
    {
        if ($type === 'home') {
            return $after === null && $offset === 0 && $this->isPublicLocale($locale) ? 1 : null;
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
            // Same publication + CONTENT-EXT visibility as Content::published()->discoverable().
            $query = Content::query();
            $active = app('cms.content_ownership')->activeOwnerSlugs();
            $query->select(['cms_contents.id', 'cms_contents.type', 'cms_contents.status', 'cms_contents.updated_at', 'cms_contents.published_at'])
                ->where('cms_contents.type', self::CONTENT_TYPES[$type])
                ->where('cms_contents.status', 'published')
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

        if (isset(self::TERM_TYPES[$type]) && $this->tables('cms_terms', 'cms_term_translations', 'cms_taxonomies')) {
            $query = Term::query();
            $query->whereHas('taxonomy', static fn ($q) => $q->where('type', self::TERM_TYPES[$type]));
            $query->select(['cms_terms.id', 'cms_terms.taxonomy_id', 'cms_terms.updated_at'])
                ->whereExists(static fn ($q) => $q->selectRaw('1')->from('cms_term_translations as sm_t')
                    ->whereColumn('sm_t.term_id', 'cms_terms.id')
                    ->where('sm_t.locale', $locale)
                    ->where('sm_t.slug', '!=', ''));

            return $query;
        }

        return null;
    }

    protected function entriesFor(Collection $rows, string $type, string $locale): iterable
    {
        $locales = app('cms.language')->getPublicLocales();

        if (isset(self::CONTENT_TYPES[$type])) {
            $this->attachTranslations($rows, ContentTranslation::class, 'content_id', $locales);
            $excluded = $this->ownerExclusions($rows->modelKeys());
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
            $alternates[$code] = url($language->localizedUrl($code, '/'));
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
     * ContentSitemapPolicy, and throwing policies, exclude their ids.
     *
     * @param  list<int|string>  $ids
     * @return array<int, true>
     */
    private function ownerExclusions(array $ids): array
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
                $drop = $policy instanceof ContentSitemapPolicy
                    ? array_intersect($ownedIds, array_map('intval', $policy->sitemapExcludedIds($ownedIds)))
                    : $ownedIds;
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
