<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Support\DemoConflict;
use TheNguyen\CMS\Support\DemoFingerprint;

/**
 * EG-9 — native post importer. Posts are Core {@see Content} rows (type=post)
 * written through {@see ContentManager} (Core owns persistence, slug uniqueness,
 * term sync, revisions). The theme ships only declarative JSON.
 *
 * Contract (posts.json):
 *   { "posts": [ {
 *       "key": "hello-tncms",             // stable symbolic identity (required)
 *       "status": "published",            // draft|published|pending|private
 *       "published_at": "2026-01-01T…Z",  // optional
 *       "author": "first_super_admin",    // safe strategy token (never a raw id)
 *       "featured_media": "media:cover",  // optional media symbolic ref
 *       "categories": ["category:news"],  // symbolic refs → term ids
 *       "tags": ["tag:tncms"],            // symbolic refs → term ids
 *       "is_featured": false,             // optional
 *       "translations": { "en": {title,slug,excerpt,content}, "vi": {…} }
 *   } ] }
 *
 * Semantics:
 *   - symbolic keys map to content ids via imported_keys ("post:{key}") — a
 *     re-import updates the SAME row (idempotent), term sync replaces (no dup);
 *   - author resolves through {@see DemoAuthorResolver}; an unresolved author is
 *     classified AUTHOR_UNRESOLVED and the post is skipped (fail closed);
 *   - featured media resolves only through imported/reused media provenance;
 *   - a user post colliding on a localized slug is never overwritten (Core slug
 *     authority suffixes) and is classified UNOWNED_SAME_SLUG.
 */
class DemoPostImporter
{
    private const STATUSES = ['draft', 'published', 'pending', 'private'];

    public function __construct(
        private readonly ContentManager $contents,
        private readonly LanguageManager $languages,
        private readonly DemoAuthorResolver $authors,
    ) {}

    /**
     * @param  array<string, mixed>  $data  decoded posts.json
     * @param  array<string, int|string>  $existingKeys  prior imported_keys
     * @return array{
     *   0: array<string, int>,
     *   1: array<int, int>,
     *   2: array<string, string>,
     *   3: array<int, string>,
     *   4: array<int, array{key: string, class: string}>,
     *   5: bool
     * }  [keyMap, postIds, fingerprints, warnings, conflicts, anyImported]
     */
    public function import(array $data, DemoSymbolResolver $resolver, array $existingKeys): array
    {
        $items = is_array($data['posts'] ?? null) ? $data['posts'] : [];

        $keyMap = [];
        $postIds = [];
        $fingerprints = [];
        $warnings = [];
        $conflicts = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $warnings[] = "posts[{$index}] is not an object — skipped.";

                continue;
            }

            $key = is_string($item['key'] ?? null) ? trim($item['key']) : '';

            if ($key === '') {
                $warnings[] = "posts[{$index}] is missing a \"key\" — skipped.";

                continue;
            }

            if ($resolver->has('post', $key)) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::SYMBOL_DUPLICATE];
                $warnings[] = "post '{$key}': duplicate symbolic key — skipped.";

                continue;
            }

            $ordered = $this->orderedTranslations(is_array($item['translations'] ?? null) ? $item['translations'] : []);

            if ($ordered === []) {
                $warnings[] = "post '{$key}' declares no usable translations — skipped.";

                continue;
            }

            // Author FIRST — fail closed before any write when it cannot resolve.
            $author = $this->authors->resolve(is_string($item['author'] ?? null) ? $item['author'] : null);
            if ($author['status'] !== 'resolved') {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::AUTHOR_UNRESOLVED];
                $warnings[] = "post '{$key}': author strategy '".($item['author'] ?? 'first_super_admin')."' did not resolve — skipped.";

                continue;
            }

            $mapKey = 'post:'.$key;
            $existingId = $existingKeys[$mapKey] ?? null;
            $post = (is_int($existingId) || (is_string($existingId) && ctype_digit($existingId)))
                ? Content::query()->where('type', 'post')->whereKey((int) $existingId)->first()
                : null;

            if ($post === null && ($slugClass = $this->slugConflictClass($ordered)) !== null) {
                $conflicts[] = ['key' => $key, 'class' => $slugClass];
                $warnings[] = "post '{$key}': a localized slug is already used — imported under a distinct slug.";
            }

            $status = is_string($item['status'] ?? null) && in_array($item['status'], self::STATUSES, true)
                ? $item['status']
                : 'published';

            $featuredImage = $this->resolveFeaturedMedia($item, $resolver, $key, $conflicts, $warnings);
            $termIds = $this->resolveTermIds($item, $resolver, $key, $conflicts, $warnings);

            $base = [
                'type' => 'post',
                'status' => $status,
                'author_id' => $author['id'],
                'is_featured' => (bool) ($item['is_featured'] ?? false),
                'term_ids' => $termIds,
            ];

            if ($featuredImage !== null) {
                $base['featured_image'] = $featuredImage;
            }

            if (is_string($item['published_at'] ?? null) && trim($item['published_at']) !== '') {
                $base['published_at'] = trim($item['published_at']);
            }

            try {
                $first = true;
                foreach ($ordered as $locale => $fields) {
                    $payload = [
                        'locale' => $locale,
                        'title' => $fields['title'],
                        'slug' => $fields['slug'],
                        'content' => $fields['content'],
                        'excerpt' => $fields['excerpt'],
                    ];

                    // Base facets (author/status/media/terms/published_at) belong on
                    // the row, written once with the first (default-locale) call.
                    if ($first) {
                        $payload = array_merge($payload, $base);
                    }

                    if ($post === null) {
                        $post = $this->contents->create($payload);
                    } else {
                        $this->contents->update($post, $payload);
                    }

                    $first = false;
                }
            } catch (\Throwable $e) {
                $warnings[] = "post '{$key}' could not be imported: ".$e->getMessage();

                continue;
            }

            $resolver->register('post', $key, (int) $post->id);
            $keyMap[$mapKey] = (int) $post->id;
            $postIds[] = (int) $post->id;
            $fingerprints[$mapKey] = DemoFingerprint::of($item);
        }

        return [$keyMap, $postIds, $fingerprints, $warnings, $conflicts, $postIds !== []];
    }

    /**
     * Read-only preview (dry-run): forecast create/update per post, and classify
     * conflicts — owned match/changed (fingerprint), unowned slug collision,
     * duplicate key, unresolved author/media/taxonomy — WRITING NOTHING.
     *
     * @param  array<string, mixed>  $data  decoded posts.json
     * @param  array<string, int|string>  $existingKeys  prior imported_keys
     * @param  array<string, string>  $priorFingerprints
     * @return array{
     *   0: array<int, array{file: string, action: string, detail: string}>,
     *   1: array<int, array{key: string, class: string}>
     * }  [actions, conflicts]
     */
    public function plan(array $data, DemoSymbolResolver $planResolver, array $existingKeys, array $priorFingerprints): array
    {
        $items = is_array($data['posts'] ?? null) ? $data['posts'] : [];
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

            $mapKey = 'post:'.$key;

            if (isset($seen[$mapKey])) {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::SYMBOL_DUPLICATE];

                continue;
            }
            $seen[$mapKey] = true;

            $ordered = $this->orderedTranslations(is_array($item['translations'] ?? null) ? $item['translations'] : []);
            if ($ordered === []) {
                continue;
            }

            // Author resolution is read-only.
            $author = $this->authors->resolve(is_string($item['author'] ?? null) ? $item['author'] : null);
            if ($author['status'] !== 'resolved') {
                $conflicts[] = ['key' => $key, 'class' => DemoConflict::AUTHOR_UNRESOLVED];
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

            // Featured media ref.
            $mediaRef = $item['featured_media'] ?? null;
            if (is_string($mediaRef) && trim($mediaRef) !== '') {
                $res = $planResolver->resolve(trim($mediaRef));
                if ($res['status'] !== DemoSymbolResolver::STATUS_RESOLVED || $res['namespace'] !== 'media') {
                    $conflicts[] = ['key' => $key, 'class' => DemoConflict::MEDIA_UNRESOLVED];
                }
            }

            // Taxonomy refs.
            foreach (['category' => 'categories', 'tag' => 'tags'] as $namespace => $field) {
                foreach (is_array($item[$field] ?? null) ? $item[$field] : [] as $ref) {
                    if (! is_string($ref)) {
                        continue;
                    }
                    $res = $planResolver->resolve(trim($ref));
                    if ($res['status'] !== DemoSymbolResolver::STATUS_RESOLVED || $res['namespace'] !== $namespace) {
                        $conflicts[] = ['key' => $key, 'class' => DemoConflict::TAXONOMY_UNRESOLVED];
                    }
                }
            }

            $actions[] = ['file' => 'posts', 'action' => $owned ? 'update' : 'create', 'detail' => $key];
        }

        return [$actions, $conflicts];
    }

    /**
     * Delete ONLY importer-created posts, by id (provenance authority — never a
     * slug/title match). Content soft-deletes and keeps its translation rows and
     * their unique (locale, slug) index occupied, which would block an idempotent
     * re-import after reset; so importer-owned demo posts are purged for real:
     * ContentManager::delete() first (slug-registry cleanup + hooks), then
     * forceDelete() so the FK cascade removes translations and post↔term rows.
     * Only ids recorded in the import provenance ever reach this method.
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

            $post = Content::query()->withTrashed()->where('type', 'post')->whereKey($id)->first();

            if ($post === null) {
                continue;
            }

            if (! $post->trashed() && ! $this->contents->delete($post)) {
                continue;
            }

            $post->forceDelete();
            $deleted[] = $id;
        }

        return $deleted;
    }

    /**
     * Resolve categories[] + tags[] symbolic refs to term ids. An unresolved or
     * wrong-namespace ref is classified TAXONOMY_UNRESOLVED and dropped (never a
     * raw id, never a guess).
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array{key: string, class: string}>  $conflicts
     * @param  array<int, string>  $warnings
     * @return array<int, int>
     */
    private function resolveTermIds(array $item, DemoSymbolResolver $resolver, string $key, array &$conflicts, array &$warnings): array
    {
        $ids = [];

        foreach (['category' => 'categories', 'tag' => 'tags'] as $namespace => $field) {
            $refs = is_array($item[$field] ?? null) ? $item[$field] : [];

            foreach ($refs as $ref) {
                if (! is_string($ref)) {
                    continue;
                }

                $resolution = $resolver->resolve(trim($ref));

                if ($resolution['status'] === DemoSymbolResolver::STATUS_RESOLVED && $resolution['namespace'] === $namespace) {
                    $ids[] = (int) $resolution['id'];

                    continue;
                }

                $conflicts[] = ['key' => $key, 'class' => DemoConflict::TAXONOMY_UNRESOLVED];
                $warnings[] = "post '{$key}': {$field} ref '{$ref}' did not resolve — dropped.";
            }
        }

        return array_values(array_unique($ids));
    }

    /**
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
            $warnings[] = "post '{$key}': featured_media '{$ref}' did not resolve — imported without a featured image.";

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
     * Default-locale slug collision → UNOWNED_SAME_SLUG; non-default only →
     * UNOWNED_SAME_TRANSLATED_SLUG; none → null. Read-only; ownership is never
     * inferred from the match (the Core slug authority suffixes on write).
     *
     * @param  array<string, array{title: string, slug: string, content: string, excerpt: string}>  $ordered
     */
    private function slugConflictClass(array $ordered): ?string
    {
        $default = $this->languages->defaultCode();
        $translated = false;

        foreach ($ordered as $locale => $fields) {
            if ($fields['slug'] === '' || $this->contents->findBySlug($fields['slug'], $locale, 'post') === null) {
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
     * Default locale first (owns the created row), then the rest; entries missing
     * a title are dropped.
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
