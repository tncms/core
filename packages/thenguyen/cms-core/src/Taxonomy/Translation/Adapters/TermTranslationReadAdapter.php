<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Adapters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationEntityInterface;
use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationReadContract;
use TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields;
use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationRecord;

/**
 * The first Taxonomy Translation Platform read adapter (Phase 9.2D): it reproduces
 * a term's EXISTING legacy read semantics
 * (`Term::displayName/translatedSlug/localeName/localeSlug/translatedDescription/
 * hasTranslation`) through the {@see \TheNguyen\CMS\Taxonomy\Translation\Drivers\TermTranslationDriver},
 * so activating it changes nothing observable. It is the taxonomy analog of the
 * Posts (9.0C) / Pages (9.1C) read adapters, field-mapped to the term columns.
 *
 * ONE ADAPTER, EVERY TAXONOMY. It operates purely over
 * {@see TaxonomyTranslationEntityInterface} and the shared `cms_term_translations`
 * store, so ONE instance serves Category, Tag, Brand, Genre, Knowledge Category and
 * every plugin taxonomy. Per the phase Core Rule it NEVER branches on a concrete
 * taxonomy type — it keys everything on the term's translation key.
 *
 * Fallback strategy per field (mirrors the legacy Term accessors EXACTLY):
 *   - name / slug / description → first-available (requested locale, else the first
 *     stored row); name uses the `Term #id` placeholder, slug the '' default,
 *     description is nullable.
 *   - localeName / localeSlug    → strict requested locale, null when absent.
 *   - seoTitle / seoDescription  → strict requested locale (meta_title/meta_description), nullable.
 *
 * The adapter owns NO write path, NO slug generation / cms_slugs, NO HTML
 * sanitization, NO cache invalidation, and NO locale detection beyond the same
 * LanguageManager authority legacy uses. It is engaged only when
 * `translation.modules.terms.read_driver` leaves 'legacy'.
 *
 * PERFORMANCE: when a term already has its `translations` relation eager-loaded the
 * adapter reads it in memory (zero queries), exactly like legacy; otherwise it
 * hits the driver. {@see batchFields()} resolves a whole list in ONE query.
 */
final class TermTranslationReadAdapter implements TaxonomyTranslationReadContract
{
    /** The per-module adoption flag namespace. */
    public const MODULE = 'terms';

    /** The Eloquent relation legacy reads for eager-loaded translations. */
    private const RELATION = 'translations';

    public function __construct(
        private readonly RelationalTranslationDriverInterface $driver,
        private readonly LanguageManager $language,
    ) {}

    // ── mode / activation ────────────────────────────────────────────────────────

    public function mode(): string
    {
        return (string) config('translation.modules.'.self::MODULE.'.read_driver', 'legacy');
    }

    public function isActive(): bool
    {
        return $this->mode() !== 'legacy';
    }

    // ── first-available fields (requested locale → first stored row) ──────────────

    public function name(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): string
    {
        $value = $this->firstAvailableRecord($entity, $locale)?->field('name');

        return ($value !== null && $value !== '')
            ? (string) $value
            : 'Term #'.$entity->getTranslationKey();
    }

    public function slug(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): string
    {
        return (string) ($this->firstAvailableRecord($entity, $locale)?->field('slug') ?? '');
    }

    public function description(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string
    {
        return $this->nullableString($this->firstAvailableRecord($entity, $locale)?->field('description'));
    }

    // ── strict fields (requested locale only, null when absent) ───────────────────

    public function localeName(TaxonomyTranslationEntityInterface $entity, string $locale): ?string
    {
        return $this->nullableString($this->strictRecord($entity, $locale)?->field('name'));
    }

    public function localeSlug(TaxonomyTranslationEntityInterface $entity, string $locale): ?string
    {
        return $this->nullableString($this->strictRecord($entity, $locale)?->field('slug'));
    }

    public function seoTitle(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string
    {
        return $this->nullableString($this->strictRecord($entity, $this->resolveLocale($locale))?->field('meta_title'));
    }

    public function seoDescription(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string
    {
        return $this->nullableString($this->strictRecord($entity, $this->resolveLocale($locale))?->field('meta_description'));
    }

    public function hasTranslation(TaxonomyTranslationEntityInterface $entity, string $locale): bool
    {
        $loaded = $this->loadedTranslations($entity);
        if ($loaded !== null) {
            return $loaded->contains(fn (object $row): bool => (string) $row->locale === $locale);
        }

        return $this->driver->exists($entity->getTranslationKey(), $locale);
    }

    // ── bundled + batch resolution (N+1-safe) ─────────────────────────────────────

    /** Resolve every display + SEO field for one term (uses the loaded relation when present). */
    public function fields(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): TermLocalizedFields
    {
        $locale = $this->resolveLocale($locale);
        $first = $this->firstAvailableRecord($entity, $locale);
        $strict = $this->strictRecord($entity, $locale);

        return new TermLocalizedFields(
            name: ($n = $first?->field('name')) !== null && $n !== '' ? (string) $n : 'Term #'.$entity->getTranslationKey(),
            slug: (string) ($first?->field('slug') ?? ''),
            description: $this->nullableString($first?->field('description')),
            seoTitle: $this->nullableString($strict?->field('meta_title')),
            seoDescription: $this->nullableString($strict?->field('meta_description')),
        );
    }

    /**
     * Resolve a whole list of terms in ONE driver query (no N+1). Returns a map
     * keyed by each entity's translation key.
     *
     * @param  array<int, TaxonomyTranslationEntityInterface>  $entities
     * @return array<int|string, TermLocalizedFields>
     */
    public function batchFields(array $entities, ?string $locale = null): array
    {
        $locale = $this->resolveLocale($locale);

        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = $entity->getTranslationKey();
        }

        $byId = $ids === [] ? [] : $this->driver->batchRead($ids);

        $out = [];
        foreach ($entities as $entity) {
            $id = $entity->getTranslationKey();
            $locales = $byId[$id] ?? [];

            $first = $locales[$locale] ?? (reset($locales) ?: null);
            $strict = $locales[$locale] ?? null;

            $out[$id] = new TermLocalizedFields(
                name: ($n = $first?->field('name')) !== null && $n !== '' ? (string) $n : 'Term #'.$id,
                slug: (string) ($first?->field('slug') ?? ''),
                description: $this->nullableString($first?->field('description')),
                seoTitle: $this->nullableString($strict?->field('meta_title')),
                seoDescription: $this->nullableString($strict?->field('meta_description')),
            );
        }

        return $out;
    }

    // ── diagnostics (never throws) ────────────────────────────────────────────────

    public function diagnostics(): array
    {
        $driverDiagnostics = [];
        try {
            $driverDiagnostics = $this->driver->diagnostics();
        } catch (\Throwable $e) {
            $driverDiagnostics = ['error' => $e->getMessage()];
        }

        return [
            'module' => self::MODULE,
            'entity' => 'term',
            'mode' => $this->mode(),
            'active' => $this->isActive(),
            'driver' => $this->driver->name(),
            'fallback' => [
                'name' => 'first_available',
                'slug' => 'first_available',
                'description' => 'first_available',
                'locale_name' => 'strict',
                'locale_slug' => 'strict',
                'seo_title' => 'strict',
                'seo_description' => 'strict',
                'has_translation' => 'strict',
            ],
            'driver_diagnostics' => $driverDiagnostics,
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────────────

    /**
     * The first-available record: the requested locale's row, else the first stored
     * row. Uses the eager-loaded relation when present (zero queries), else the driver.
     */
    private function firstAvailableRecord(TaxonomyTranslationEntityInterface $entity, ?string $locale): ?TranslationRecord
    {
        $locale = $this->resolveLocale($locale);
        $loaded = $this->loadedTranslations($entity);

        if ($loaded !== null) {
            $row = $loaded->firstWhere('locale', $locale) ?? $loaded->first();

            return $row ? $this->recordFromModel($entity, $row) : null;
        }

        $record = $this->driver->read($entity->getTranslationKey(), $locale);
        if ($record !== null) {
            return $record;
        }

        $all = $this->driver->readAllLocales($entity->getTranslationKey());

        return $all === [] ? null : (reset($all) ?: null);
    }

    /** The strict requested-locale record (no fallback). Loaded relation, else driver. */
    private function strictRecord(TaxonomyTranslationEntityInterface $entity, string $locale): ?TranslationRecord
    {
        $loaded = $this->loadedTranslations($entity);

        if ($loaded !== null) {
            $row = $loaded->firstWhere('locale', $locale);

            return $row ? $this->recordFromModel($entity, $row) : null;
        }

        return $this->driver->read($entity->getTranslationKey(), $locale);
    }

    /**
     * The eager-loaded `translations` collection when the entity is an Eloquent
     * model that already has it loaded; null otherwise (so we fall back to the
     * driver). Generic — works for any taxonomy model, no concrete type reference.
     *
     * @return Collection<int, object>|null
     */
    private function loadedTranslations(TaxonomyTranslationEntityInterface $entity): ?Collection
    {
        if ($entity instanceof Model && $entity->relationLoaded(self::RELATION)) {
            /** @var Collection<int, object> $collection */
            $collection = $entity->getRelation(self::RELATION);

            return $collection;
        }

        return null;
    }

    /** Build a driver record from an eager-loaded translation model, by field name. */
    private function recordFromModel(TaxonomyTranslationEntityInterface $entity, object $row): TranslationRecord
    {
        $fields = [];
        foreach (TaxonomyTranslationFields::all() as $field) {
            $fields[$field] = $row->{$field} ?? null;
        }

        return new TranslationRecord($entity->getTranslationKey(), (string) $row->locale, $fields);
    }

    private function resolveLocale(?string $locale): string
    {
        return ($locale !== null && $locale !== '') ? $locale : $this->language->defaultCode();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
