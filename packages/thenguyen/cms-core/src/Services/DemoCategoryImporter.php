<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Support\DemoConflict;
use TheNguyen\CMS\Support\DemoFingerprint;

/**
 * EG-9 — native category importer. Categories are Core {@see Term} rows under the
 * hierarchical `category` taxonomy; every write goes through {@see TaxonomyManager}
 * (Core owns persistence, slug uniqueness, hierarchy integrity and revisions).
 *
 * Contract (categories.json):
 *   { "categories": [ {
 *       "key": "news",                    // stable symbolic identity (required)
 *       "parent": "category:updates",     // optional symbolic parent ref
 *       "sort_order": 2,                  // optional deterministic ordering
 *       "featured_media": "media:cover",  // optional media symbolic ref
 *       "translations": {                 // at least one usable locale required
 *         "en": { "name": "News", "slug": "news", "description": "<p>…</p>" },
 *         "vi": { "name": "Tin tức", "slug": "tin-tuc" }
 *       }
 *   } ] }
 *
 * Semantics:
 *   - symbolic keys map to term ids via imported_keys ("category:{key}") — a
 *     re-import updates the SAME rows (idempotent);
 *   - parents are two-pass symbolic refs (all rows created first, then wired), so
 *     forward references resolve; an unresolved parent imports without a parent
 *     and is classified TAXONOMY_UNRESOLVED;
 *   - a user term that collides on a localized slug is NEVER overwritten — the
 *     Core slug authority suffixes the demo slug and the collision is classified
 *     UNOWNED_SAME_SLUG (ownership is provenance, never a slug match).
 */
class DemoCategoryImporter
{
    private const TAXONOMY = 'category';

    public function __construct(
        private readonly TaxonomyManager $taxonomies,
        private readonly LanguageManager $languages,
    ) {}

    /**
     * @param  array<string, mixed>  $data  decoded categories.json
     * @param  array<string, int|string>  $existingKeys  prior imported_keys
     * @return array{
     *   0: array<string, int>,
     *   1: array<int, int>,
     *   2: array<string, string>,
     *   3: array<int, string>,
     *   4: array<int, array{key: string, class: string}>,
     *   5: bool
     * }  [keyMap, termIds, fingerprints, warnings, conflicts, anyImported]
     */
    public function import(array $data, DemoSymbolResolver $resolver, array $existingKeys): array
    {
        $items = is_array($data['categories'] ?? null) ? $data['categories'] : [];

        $keyMap = [];
        $termIds = [];
        $fingerprints = [];
        $warnings = [];
        $conflicts = [];
        /** @var array<string, string> $parentRefs  key => parent reference */
        $parentRefs = [];

        // --- Pass 1: create/update term rows + translations, register identity ---
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $warnings[] = "categories[{$index}] is not an object — skipped.";

                continue;
            }

            $key = is_string($item['key'] ?? null) ? trim($item['key']) : '';

            if ($key === '') {
                $warnings[] = "categories[{$index}] is missing a \"key\" — skipped.";

                continue;
            }

            $mapKey = 'category:'.$key;

            if ($resolver->has(self::TAXONOMY, $key)) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::SYMBOL_DUPLICATE];
                $warnings[] = "category '{$key}': duplicate symbolic key — skipped.";

                continue;
            }

            $ordered = $this->orderedTranslations(is_array($item['translations'] ?? null) ? $item['translations'] : []);

            if ($ordered === []) {
                $warnings[] = "category '{$key}' declares no usable translations — skipped.";

                continue;
            }

            $existingId = $existingKeys[$mapKey] ?? null;
            $term = (is_int($existingId) || (is_string($existingId) && ctype_digit($existingId)))
                ? Term::query()->whereKey((int) $existingId)->first()
                : null;

            // Classify a user (unowned) slug collision BEFORE writing — never
            // overwrite, never claim. Only meaningful for a fresh (unowned) import.
            if ($term === null && ($slugClass = $this->slugConflictClass($ordered)) !== null) {
                $conflicts[] = ['key' => $key, 'class' => $slugClass];
                $warnings[] = "category '{$key}': a localized slug is already used — imported under a distinct slug.";
            }

            $featuredImage = $this->resolveFeaturedMedia($item, $resolver, $key, $conflicts, $warnings);

            try {
                foreach ($ordered as $locale => $fields) {
                    $payload = [
                        'locale' => $locale,
                        'name' => $fields['name'],
                        'slug' => $fields['slug'],
                        'description' => $fields['description'],
                        'sort_order' => is_int($item['sort_order'] ?? null) ? $item['sort_order'] : 0,
                    ];

                    if ($featuredImage !== null) {
                        $payload['featured_image'] = $featuredImage;
                    }

                    if ($term === null) {
                        $term = $this->taxonomies->createTerm(self::TAXONOMY, $payload);
                    } else {
                        $this->taxonomies->updateTerm($term, $payload);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = "category '{$key}' could not be imported: ".$e->getMessage();

                continue;
            }

            $resolver->register(self::TAXONOMY, $key, (int) $term->id);
            $keyMap[$mapKey] = (int) $term->id;
            $termIds[] = (int) $term->id;
            $fingerprints[$mapKey] = DemoFingerprint::of($item);

            $parent = $item['parent'] ?? null;
            if (is_string($parent) && trim($parent) !== '') {
                $parentRefs[$key] = trim($parent);
            }
        }

        // --- Pass 2: wire hierarchical parents from symbolic refs ---
        foreach ($parentRefs as $key => $parentRef) {
            $childId = $keyMap['category:'.$key] ?? null;
            if ($childId === null) {
                continue;
            }

            $resolution = $resolver->resolve($parentRef);

            if ($resolution['status'] !== DemoSymbolResolver::STATUS_RESOLVED
                || $resolution['namespace'] !== self::TAXONOMY
                || $resolution['id'] === $childId) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::TAXONOMY_UNRESOLVED];
                $warnings[] = "category '{$key}': parent '{$parentRef}' did not resolve to another category — imported without a parent.";

                continue;
            }

            $child = Term::query()->whereKey($childId)->first();
            if ($child === null) {
                continue;
            }

            try {
                $this->taxonomies->updateTerm($child, [
                    'locale' => $this->languages->defaultCode(),
                    'parent_id' => $resolution['id'],
                ]);
            } catch (\Throwable $e) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::TAXONOMY_UNRESOLVED];
                $warnings[] = "category '{$key}': parent could not be set: ".$e->getMessage();
            }
        }

        return [$keyMap, $termIds, $fingerprints, $warnings, $conflicts, $termIds !== []];
    }

    /**
     * Delete ONLY importer-created categories, by id (provenance authority — never
     * a slug/title match), with SHARED-TAXONOMY SAFETY: a term still referenced by
     * content (e.g. a user post attached it after import) is NEVER deleted — it is
     * retained as preserved/shared so no user relationship is destroyed. Callers
     * must delete importer-owned posts first, so any remaining association is
     * external (user) content. Deletion removes slug rows (via deleteTerm) then
     * force-deletes so a subsequent re-import is clean.
     *
     * @param  array<int, int|string>  $ids
     * @return array{0: array<int, int>, 1: array<int, int>} [deleted, preserved]
     */
    public function deleteImported(array $ids): array
    {
        $deleted = [];
        $preserved = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            $term = Term::query()->withTrashed()->whereKey($id)->first();

            if ($term === null) {
                continue;
            }

            // Shared-taxonomy safety: preserve a term external content still uses.
            if ($term->contents()->exists()) {
                $preserved[] = $id;

                continue;
            }

            if (! $term->trashed()) {
                $this->taxonomies->deleteTerm($term);
            }

            $term->forceDelete();
            $deleted[] = $id;
        }

        return [$deleted, $preserved];
    }

    /**
     * Read-only preview (dry-run): forecast create/update per category, classify
     * conflicts (owned match/changed via fingerprint, unowned slug collision,
     * duplicate symbolic key, unresolved parent), and WRITE NOTHING.
     *
     * @param  array<string, mixed>  $data  decoded categories.json
     * @param  array<string, int|string>  $existingKeys  prior imported_keys
     * @param  array<string, string>  $priorFingerprints  prior provenance fingerprints
     * @return array{
     *   0: array<int, array{file: string, action: string, detail: string}>,
     *   1: array<int, array{key: string, class: string}>
     * }  [actions, conflicts]
     */
    public function plan(array $data, DemoSymbolResolver $planResolver, array $existingKeys, array $priorFingerprints): array
    {
        $items = is_array($data['categories'] ?? null) ? $data['categories'] : [];
        $actions = [];
        $conflicts = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = is_string($item['key'] ?? null) ? trim($item['key']) : '';
            if ($key === '') {
                continue;
            }

            $mapKey = 'category:'.$key;

            if (isset($seen[$mapKey])) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::SYMBOL_DUPLICATE];

                continue;
            }
            $seen[$mapKey] = true;

            $ordered = $this->orderedTranslations(is_array($item['translations'] ?? null) ? $item['translations'] : []);
            if ($ordered === []) {
                continue;
            }

            $owned = array_key_exists($mapKey, $existingKeys);

            if ($owned) {
                $prior = $priorFingerprints[$mapKey] ?? null;
                $conflicts[] = [
                    'key' => $key,
                    'class' => is_string($prior) && hash_equals($prior, DemoFingerprint::of($item))
                        ? DemoConflict::OWNED_MATCH
                        : DemoConflict::OWNED_CHANGED,
                ];
            } elseif (($slugClass = $this->slugConflictClass($ordered)) !== null) {
                $conflicts[] = ['key' => $key, 'class' => $slugClass];
            }

            $parent = $item['parent'] ?? null;
            if (is_string($parent) && trim($parent) !== '') {
                $resolution = $planResolver->resolve(trim($parent));
                if ($resolution['status'] !== DemoSymbolResolver::STATUS_RESOLVED || $resolution['namespace'] !== self::TAXONOMY) {
                    $conflicts[] = ['key' => $key, 'class' => DemoConflict::TAXONOMY_UNRESOLVED];
                }
            }

            $actions[] = ['file' => 'categories', 'action' => $owned ? 'update' : 'create', 'detail' => $key];
        }

        return [$actions, $conflicts];
    }

    /**
     * Resolve an optional `featured_media: media:{key}` ref to a stored media URL,
     * ONLY through imported/reused media provenance (never a path/filename guess).
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array{key: string, class: string}>  $conflicts
     * @param  array<int, string>  $warnings
     */
    private function resolveFeaturedMedia(array $item, DemoSymbolResolver $resolver, string $key, array &$conflicts, array &$warnings): ?string
    {
        $ref = $item['featured_media'] ?? null;

        if (! is_string($ref) || trim($ref) === '') {
            return null;
        }

        $resolution = $resolver->resolve(trim($ref));

        if ($resolution['status'] !== DemoSymbolResolver::STATUS_RESOLVED || $resolution['namespace'] !== 'media') {
            $conflicts[] = ['key' => $key, 'class' => DemoConflict::MEDIA_UNRESOLVED];
            $warnings[] = "category '{$key}': featured_media '{$ref}' did not resolve — imported without a featured image.";

            return null;
        }

        $media = Media::query()->whereKey($resolution['id'])->first();

        if ($media === null || ! is_string($media->url) || $media->url === '') {
            $conflicts[] = ['key' => $key, 'class' => DemoConflict::MEDIA_UNRESOLVED];

            return null;
        }

        return $media->url;
    }

    /**
     * Classify an unowned localized-slug collision without writing: a collision on
     * the DEFAULT-locale slug is UNOWNED_SAME_SLUG; a collision only on a
     * non-default locale is UNOWNED_SAME_TRANSLATED_SLUG; no collision is null.
     * Ownership is never inferred from the match — the Core slug authority simply
     * suffixes the demo slug on write.
     *
     * @param  array<string, array{name: string, slug: string, description: ?string}>  $ordered
     */
    private function slugConflictClass(array $ordered): ?string
    {
        $default = $this->languages->defaultCode();
        $translated = false;

        foreach ($ordered as $locale => $fields) {
            if ($fields['slug'] === '' || $this->taxonomies->findTermBySlug($fields['slug'], $locale, self::TAXONOMY) === null) {
                continue;
            }

            if ($locale === $default) {
                return DemoConflict::UNOWNED_SAME_SLUG;
            }

            $translated = true;
        }

        return $translated ? DemoConflict::UNOWNED_SAME_TRANSLATED_SLUG : null;
    }

    /**
     * Normalize + order translations: default locale first (so it owns the created
     * row), then the rest; entries missing a name are dropped.
     *
     * @param  array<mixed, mixed>  $translations
     * @return array<string, array{name: string, slug: string, description: ?string}>
     */
    private function orderedTranslations(array $translations): array
    {
        $default = $this->languages->defaultCode();
        $out = [];

        foreach ($translations as $locale => $fields) {
            if (! is_string($locale) || $locale === '' || ! is_array($fields)) {
                continue;
            }

            $name = $fields['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $out[$locale] = [
                'name' => trim($name),
                'slug' => is_string($fields['slug'] ?? null) ? trim($fields['slug']) : '',
                'description' => is_string($fields['description'] ?? null) ? $fields['description'] : null,
            ];
        }

        if (isset($out[$default])) {
            $out = [$default => $out[$default]] + $out;
        }

        return $out;
    }
}
