<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Content;

/**
 * Demo pages importer (CORE-THEME-2): creates/updates the Pages a theme demo
 * preset declares in its `pages` file. Core owns every database write — the
 * theme only ships the declarative JSON.
 *
 * File shape (pages.json):
 *
 *   { "pages": [ {
 *       "key": "landing",                  // stable symbolic key (required)
 *       "template": "landing",             // optional page-template id
 *       "status": "published",             // draft|published|pending|private
 *       "homepage": true,                  // optional: static homepage target
 *       "show_page_title": false,          // optional presentation flag
 *       "translations": {                  // at least one locale required
 *         "en": { "title": "Landing", "slug": "landing",
 *                 "content": "<p>…</p>", "excerpt": "" }
 *       }
 *   } ] }
 *
 * Semantics:
 *   - symbolic keys map to content ids via imported_keys ("page:{key}") — a
 *     re-import updates the SAME rows (idempotent), a deleted row is recreated;
 *   - slug conflicts resolve through the normal slug-uniqueness authority
 *     (suffixing) — existing owner content is never overwritten or deleted;
 *   - a template id the owning theme does not declare imports as a warning
 *     with no template (never an arbitrary view reference);
 *   - deleteImported() removes ONLY pages this importer created, by id.
 */
class DemoPageImporter
{
    private const STATUSES = ['draft', 'published', 'pending', 'private'];

    public function __construct(
        private readonly ContentManager $contents,
        private readonly LanguageManager $languages,
        private readonly PageTemplateRegistry $templates,
    ) {}

    /**
     * Import the declared pages for a theme demo package.
     *
     * @param  array<string, mixed>  $data  decoded pages.json
     * @param  array<string, int>  $existingKeys  prior imported_keys
     * @param  string  $ownerTheme  the theme slug that owns the demo package
     * @return array{0: array<string, int>, 1: array<int, int>, 2: ?int, 3: array<int, string>, 4: bool}
     *   [keyMap ("page:{key}" => id), importedPageIds, homepagePageId, warnings, anyImported]
     */
    public function import(array $data, array $existingKeys, string $ownerTheme): array
    {
        $items = is_array($data['pages'] ?? null) ? $data['pages'] : [];

        $keyMap = [];
        $pageIds = [];
        $warnings = [];
        $homepageId = null;

        $declared = $this->templates->templatesFor($ownerTheme);

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $warnings[] = "pages[{$index}] is not an object — skipped.";

                continue;
            }

            $key = $item['key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                $warnings[] = "pages[{$index}] is missing a \"key\" — skipped.";

                continue;
            }

            $key = trim($key);
            $mapKey = 'page:'.$key;

            if (isset($keyMap[$mapKey])) {
                $warnings[] = "duplicate page key '{$key}' — skipped.";

                continue;
            }

            $translations = is_array($item['translations'] ?? null) ? $item['translations'] : [];
            $ordered = $this->orderedTranslations($translations);

            if ($ordered === []) {
                $warnings[] = "page '{$key}' declares no usable translations — skipped.";

                continue;
            }

            $status = is_string($item['status'] ?? null) && in_array($item['status'], self::STATUSES, true)
                ? $item['status']
                : 'published';

            $template = null;
            $declaredTemplate = $item['template'] ?? null;

            if (is_string($declaredTemplate) && trim($declaredTemplate) !== '') {
                $declaredTemplate = trim($declaredTemplate);

                if (isset($declared[$declaredTemplate])) {
                    $template = $declaredTemplate;
                } else {
                    $warnings[] = "page '{$key}': template '{$declaredTemplate}' is not declared by theme '{$ownerTheme}' — imported without a template.";
                }
            }

            $existingId = $existingKeys[$mapKey] ?? null;
            $content = is_int($existingId) || (is_string($existingId) && ctype_digit($existingId))
                ? Content::query()->where('type', 'page')->whereKey((int) $existingId)->first()
                : null;

            try {
                foreach ($ordered as $locale => $fields) {
                    $payload = [
                        'type' => 'page',
                        'status' => $status,
                        'locale' => $locale,
                        'title' => $fields['title'],
                        'slug' => $fields['slug'],
                        'content' => $fields['content'],
                        'excerpt' => $fields['excerpt'],
                        'template' => $template,
                    ];

                    if (array_key_exists('show_page_title', $item)) {
                        $payload['show_page_title'] = (bool) $item['show_page_title'];
                    }

                    if ($content === null) {
                        $content = $this->contents->create($payload);
                    } else {
                        $this->contents->update($content, $payload);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = "page '{$key}' could not be imported: ".$e->getMessage();

                continue;
            }

            $keyMap[$mapKey] = (int) $content->id;
            $pageIds[] = (int) $content->id;

            if (($item['homepage'] ?? false) === true) {
                if ($homepageId !== null) {
                    $warnings[] = "page '{$key}': more than one page declares \"homepage\" — keeping the first.";
                } else {
                    $homepageId = (int) $content->id;
                }
            }
        }

        return [$keyMap, $pageIds, $homepageId, $warnings, $pageIds !== []];
    }

    /**
     * Delete ONLY importer-created pages, by id. Returns the ids deleted.
     *
     * Content soft-deletes; a soft-deleted row keeps its translation rows and
     * their unique (locale, slug) index occupied, which would block an
     * idempotent re-import after reset. Importer-owned demo pages are
     * therefore purged for real: ContentManager::delete() first (slug-registry
     * cleanup + hooks), then forceDelete() so the FK cascade removes the
     * translations. Only ids recorded in the import provenance ever reach
     * this method — user content is never touched.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public function deleteImported(array $ids): array
    {
        $deleted = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            $content = Content::query()->withTrashed()->where('type', 'page')->whereKey($id)->first();

            if ($content === null) {
                continue;
            }

            if (! $content->trashed() && ! $this->contents->delete($content)) {
                continue;
            }

            $content->forceDelete();
            $deleted[] = $id;
        }

        return $deleted;
    }

    /**
     * Normalize + order translations: default locale first (so it owns the
     * created row), then the rest; entries missing a title are dropped.
     *
     * @param  array<mixed, mixed>  $translations
     * @return array<string, array{title: string, slug: string, content: string, excerpt: string}>
     */
    private function orderedTranslations(array $translations): array
    {
        $default = $this->languages->defaultCode();
        $out = [];

        foreach ($translations as $locale => $fields) {
            if (! is_string($locale) || $locale === '' || ! is_array($fields)) {
                continue;
            }

            $title = $fields['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                continue;
            }

            $out[$locale] = [
                'title' => trim($title),
                'slug' => is_string($fields['slug'] ?? null) ? trim($fields['slug']) : '',
                'content' => is_string($fields['content'] ?? null) ? $fields['content'] : '',
                'excerpt' => is_string($fields['excerpt'] ?? null) ? $fields['excerpt'] : '',
            ];
        }

        if (isset($out[$default])) {
            $out = [$default => $out[$default]] + $out;
        }

        return $out;
    }
}
