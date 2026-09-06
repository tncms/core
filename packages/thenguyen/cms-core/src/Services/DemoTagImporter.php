<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Support\DemoConflict;
use TheNguyen\CMS\Support\DemoFingerprint;

/**
 * EG-9 — native tag importer. Tags are Core {@see Term} rows under the flat `tag`
 * taxonomy; every write goes through {@see TaxonomyManager}. Flat taxonomy: no
 * parent, no featured image.
 *
 * Contract (tags.json):
 *   { "tags": [ {
 *       "key": "tncms",                   // stable symbolic identity (required)
 *       "translations": {                 // at least one usable locale required
 *         "en": { "name": "TN CMS", "slug": "tncms" },
 *         "vi": { "name": "TN CMS", "slug": "tncms" }
 *       }
 *   } ] }
 *
 * Semantics mirror {@see DemoCategoryImporter}: symbolic keys are identity
 * ("tag:{key}" in imported_keys), re-import updates the same row, and a user
 * slug collision is never overwritten (Core slug authority suffixes) but is
 * classified UNOWNED_SAME_SLUG.
 */
class DemoTagImporter
{
    private const TAXONOMY = 'tag';

    public function __construct(
        private readonly TaxonomyManager $taxonomies,
        private readonly LanguageManager $languages,
    ) {}

    /**
     * @param  array<string, mixed>  $data  decoded tags.json
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
        $items = is_array($data['tags'] ?? null) ? $data['tags'] : [];

        $keyMap = [];
        $termIds = [];
        $fingerprints = [];
        $warnings = [];
        $conflicts = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $warnings[] = "tags[{$index}] is not an object — skipped.";

                continue;
            }

            $key = is_string($item['key'] ?? null) ? trim($item['key']) : '';

            if ($key === '') {
                $warnings[] = "tags[{$index}] is missing a \"key\" — skipped.";

                continue;
            }

            if ($resolver->has(self::TAXONOMY, $key)) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::SYMBOL_DUPLICATE];
                $warnings[] = "tag '{$key}': duplicate symbolic key — skipped.";

                continue;
            }

            $ordered = $this->orderedTranslations(is_array($item['translations'] ?? null) ? $item['translations'] : []);

            if ($ordered === []) {
                $warnings[] = "tag '{$key}' declares no usable translations — skipped.";

                continue;
            }

            $mapKey = 'tag:'.$key;
            $existingId = $existingKeys[$mapKey] ?? null;
            $term = (is_int($existingId) || (is_string($existingId) && ctype_digit($existingId)))
                ? Term::query()->whereKey((int) $existingId)->first()
                : null;

            if ($term === null && ($slugClass = $this->slugConflictClass($ordered)) !== null) {
                $conflicts[] = ['key' => $key, 'class' => $slugClass];
                $warnings[] = "tag '{$key}': a localized slug is already used — imported under a distinct slug.";
            }

            try {
                foreach ($ordered as $locale => $fields) {
                    $payload = [
                        'locale' => $locale,
                        'name' => $fields['name'],
                        'slug' => $fields['slug'],
                    ];

                    if ($term === null) {
                        $term = $this->taxonomies->createTerm(self::TAXONOMY, $payload);
                    } else {
                        $this->taxonomies->updateTerm($term, $payload);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = "tag '{$key}' could not be imported: ".$e->getMessage();

                continue;
            }

            $resolver->register(self::TAXONOMY, $key, (int) $term->id);
            $keyMap[$mapKey] = (int) $term->id;
            $termIds[] = (int) $term->id;
            $fingerprints[$mapKey] = DemoFingerprint::of($item);
        }

        return [$keyMap, $termIds, $fingerprints, $warnings, $conflicts, $termIds !== []];
    }

    /**
     * Delete ONLY importer-created tags, by id (provenance authority — never a
     * slug/title match), with shared-taxonomy safety: a tag still referenced by
     * content is retained as preserved/shared so no user relationship is broken.
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
     * Read-only preview (dry-run): forecast create/update per tag, classify
     * conflicts (owned match/changed, unowned slug collision, duplicate key), and
     * WRITE NOTHING.
     *
     * @param  array<string, mixed>  $data  decoded tags.json
     * @param  array<string, int|string>  $existingKeys  prior imported_keys
     * @param  array<string, string>  $priorFingerprints
     * @return array{
     *   0: array<int, array{file: string, action: string, detail: string}>,
     *   1: array<int, array{key: string, class: string}>
     * }  [actions, conflicts]
     */
    public function plan(array $data, array $existingKeys, array $priorFingerprints): array
    {
        $items = is_array($data['tags'] ?? null) ? $data['tags'] : [];
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

            $mapKey = 'tag:'.$key;

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

            $actions[] = ['file' => 'tags', 'action' => $owned ? 'update' : 'create', 'detail' => $key];
        }

        return [$actions, $conflicts];
    }

    /**
     * Default-locale slug collision → UNOWNED_SAME_SLUG; non-default only →
     * UNOWNED_SAME_TRANSLATED_SLUG; none → null. Read-only; ownership is never
     * inferred from the match.
     *
     * @param  array<string, array{name: string, slug: string}>  $ordered
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
     * @param  array<mixed, mixed>  $translations
     * @return array<string, array{name: string, slug: string}>
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
            ];
        }

        if (isset($out[$default])) {
            $out = [$default => $out[$default]] + $out;
        }

        return $out;
    }
}
