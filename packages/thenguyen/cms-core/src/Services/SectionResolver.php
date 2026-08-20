<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Registries\SectionRegistry;
use TheNguyen\CMS\View\MediaViewModel;
use TheNguyen\CMS\View\ResolvedLayout;
use TheNguyen\CMS\View\SectionViewModel;

/**
 * Turns a canonical pagebuilder/06 layout document into typed
 * {@see SectionViewModel}s (theme-architecture `17` §9, `20` Rules 1–4).
 *
 * Responsibilities: validate each section `type` against the {@see SectionRegistry};
 * validate + default `settings` and `fields` against the type schema; normalize
 * media references into {@see MediaViewModel}s; drop/warn on unknown types and
 * invalid values. Resolution is tolerant — it never throws to the caller
 * (pagebuilder/06 §6, builder-data `05` §9).
 *
 * v1 handles `data`-bound sections (the Company family). `query`/`context`
 * binding is stubbed (empty data + warning) until dynamic sections land.
 */
class SectionResolver
{
    private const SUPPORTED_VERSION = 1;

    /**
     * Media rows referenced by the document being resolved, keyed by id and
     * batch-loaded once per resolve() to avoid a per-image N+1.
     *
     * @var array<int, Media>
     */
    private array $mediaCache = [];

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly MediaManager $media,
        private readonly ?SectionFieldLocalizer $localizer = null,
        private readonly ?SectionDataProvider $dataProvider = null,
    ) {}

    /**
     * Resolve a pagebuilder/06 document (array or JSON string) into typed
     * sections.
     *
     * `$currentPost` (Phase 9C-C) is the Content being viewed on a post-detail
     * page, threaded to the SectionDataProvider so `related` sources can resolve.
     * It is null on the homepage / any context without a current post — `related`
     * then renders empty (never querying Blade).
     */
    public function resolve(array|string $document, ?string $locale = null, ?Content $currentPost = null): ResolvedLayout
    {
        $warnings = [];

        $doc = $this->parse($document, $warnings);

        if ($doc === null) {
            return new ResolvedLayout([], $warnings);
        }

        $this->checkVersion($doc, $warnings);

        $nodes = $doc['sections'] ?? null;

        if (! is_array($nodes)) {
            $warnings[] = 'Document has no "sections" array.';

            return new ResolvedLayout([], $warnings);
        }

        // Populate data-bound sections (placeholder / posts) BEFORE media is
        // prefetched, so any injected media ids batch with the rest and the
        // normal localize/coerce pipeline resolves the injected items.
        $nodes = $this->applyDataSources($nodes, $locale, $currentPost);

        $this->prefetchMedia($nodes);

        $sections = [];

        foreach ($nodes as $index => $node) {
            $vm = $this->resolveNode($node, $index, $locale, $warnings);

            if ($vm !== null) {
                $sections[] = $vm;
            }
        }

        return new ResolvedLayout($sections, $warnings);
    }

    /**
     * Populate data-bound section repeater(s) from the SectionDataProvider when a
     * node's `data_source` setting is `placeholder` or `posts`. Authored (manual)
     * content is left untouched. Mutates a copy of the node list and returns it;
     * never throws (the provider already isolates failures).
     *
     * @param  array<int|string, mixed>  $nodes
     * @return array<int|string, mixed>
     */
    private function applyDataSources(array $nodes, ?string $locale, ?Content $currentPost = null): array
    {
        if ($this->dataProvider === null) {
            return $nodes;
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if (! is_string($type) || ! $this->registry->has($type)) {
                continue;
            }

            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

            if (($settings['data_source'] ?? 'manual') === 'manual') {
                continue;
            }

            $injected = $this->dataProvider->resolve($type, $settings, $locale, $currentPost);

            if ($injected === null) {
                continue;
            }

            $fields = is_array($node['fields'] ?? null) ? $node['fields'] : [];

            foreach ($injected as $key => $value) {
                $fields[$key] = $value;
            }

            $nodes[$index]['fields'] = $fields;
        }

        return $nodes;
    }

    /**
     * Parse the input into a document array, or null when unusable.
     *
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>|null
     */
    private function parse(array|string $document, array &$warnings): ?array
    {
        if (is_string($document)) {
            $decoded = json_decode($document, true);

            if (! is_array($decoded)) {
                $warnings[] = 'Layout JSON is invalid.';

                return null;
            }

            return $decoded;
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<int, string>  $warnings
     */
    private function checkVersion(array $doc, array &$warnings): void
    {
        $version = $doc['version'] ?? self::SUPPORTED_VERSION;

        if (is_int($version) && $version > self::SUPPORTED_VERSION) {
            $warnings[] = "Layout version {$version} is newer than supported version "
                .self::SUPPORTED_VERSION.'; rendering with current rules.';
        }
    }

    /**
     * Resolve a single section node, or null when it must be dropped.
     *
     * @param  array<int, string>  $warnings
     */
    private function resolveNode(mixed $node, int|string $index, ?string $locale, array &$warnings): ?SectionViewModel
    {
        if (! is_array($node)) {
            $warnings[] = "Section #{$index} is not an object — dropped.";

            return null;
        }

        // A section disabled in the layout editor is kept in the document but not
        // rendered (no warning — it is intentionally hidden).
        if (($node['enabled'] ?? true) === false) {
            return null;
        }

        $type = $node['type'] ?? null;

        if (! is_string($type) || ! $this->registry->has($type)) {
            $warnings[] = 'Unknown section type "'.(is_string($type) ? $type : gettype($type))."\" at #{$index} — dropped.";

            return null;
        }

        $mode = $this->registry->bindingMode($type);

        if ($mode !== 'data') {
            // query/context binding is not wired in v1.
            $warnings[] = "Section \"{$type}\" uses unsupported binding mode \"{$mode}\" — rendered with empty data.";
        }

        $settings = $this->coerceSettings($type, is_array($node['settings'] ?? null) ? $node['settings'] : []);

        $rawFields = is_array($node['fields'] ?? null) ? $node['fields'] : [];

        // Collapse localized field leaves to the current locale (with fallback)
        // BEFORE validation, so coercion only ever sees plain values. Without a
        // localizer wired (e.g. isolated unit tests), plain strings pass through.
        if ($mode === 'data' && $this->localizer !== null) {
            $rawFields = $this->localizer->resolveForLocale($type, $rawFields, $locale);
        }

        $fields = $mode === 'data'
            ? $this->coerceFields($this->registry->fieldsSchema($type), $rawFields, $warnings)
            : [];

        $id = is_string($node['id'] ?? null) && $node['id'] !== '' ? $node['id'] : $this->generateId();

        return new SectionViewModel($id, $type, $settings, $fields);
    }

    /**
     * Validate + default a section's settings against its schema. Unknown keys
     * are ignored; invalid values fall back to the declared default.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function coerceSettings(string $type, array $raw): array
    {
        $schema = $this->registry->settingsSchema($type);
        $out = [];

        foreach ($schema as $key => $spec) {
            $type_ = $spec['type'] ?? 'text';
            $default = $spec['default'] ?? null;
            $value = $raw[$key] ?? null;

            $out[$key] = match ($type_) {
                'enum' => $this->coerceEnum($value, is_array($spec['values'] ?? null) ? $spec['values'] : [], $default),
                'boolean' => is_bool($value) ? $value : (bool) ($value ?? $default),
                'number' => $this->clampNumber($value, $spec, $default),
                'terms', 'content', 'authors' => $this->coerceIdList($value),
                default => is_string($value) ? $value : $default,
            };
        }

        return $out;
    }

    /**
     * Validate + default a set of fields against a fields schema, normalizing
     * media references and recursing into repeaters.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function coerceFields(array $schema, array $raw, array &$warnings): array
    {
        $out = [];

        foreach ($schema as $key => $spec) {
            $out[$key] = $this->coerceField($spec, $raw[$key] ?? null, $warnings);
        }

        return $out;
    }

    /**
     * Coerce a single field value by its schema type.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, string>  $warnings
     */
    private function coerceField(array $spec, mixed $value, array &$warnings): mixed
    {
        $type = $spec['type'] ?? 'text';
        $default = $spec['default'] ?? null;

        return match ($type) {
            'text', 'textarea', 'richtext' => $this->coerceString($value, $default),
            'number' => $this->clampNumber($value, $spec, $default),
            'boolean' => is_bool($value) ? $value : (bool) ($value ?? $default),
            'link' => $this->coerceLink($value, $default),
            'media' => $this->resolveMedia($value, $warnings),
            'repeater' => $this->coerceRepeater($spec, $value, $warnings),
            default => $value ?? $default,
        };
    }

    /**
     * Match a value against the allowed enum values, returning the declared
     * (typed) value. Strict match first, then a string-equality fallback so a
     * numeric enum submitted as a string by an admin form (e.g. columns "3")
     * still resolves to the typed declared value (3).
     *
     * @param  array<int, mixed>  $values
     */
    private function coerceEnum(mixed $value, array $values, mixed $default): mixed
    {
        foreach ($values as $allowed) {
            if ($allowed === $value) {
                return $allowed;
            }
        }

        foreach ($values as $allowed) {
            if ((string) $allowed === (string) $value) {
                return $allowed;
            }
        }

        return $default;
    }

    /**
     * Coerce a `terms` multi-select value into a clean list of positive int ids
     * (tolerating numeric strings from form state), de-duplicated. The stored
     * config holds ids only — never resolved labels.
     *
     * @return array<int, int>
     */
    private function coerceIdList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $out[] = (int) $item;
            }
        }

        return array_values(array_unique($out));
    }

    private function coerceString(mixed $value, mixed $default): mixed
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function clampNumber(mixed $value, array $spec, mixed $default): mixed
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $num = $value + 0;

        if (isset($spec['min']) && is_numeric($spec['min']) && $num < $spec['min']) {
            $num = $spec['min'] + 0;
        }

        if (isset($spec['max']) && is_numeric($spec['max']) && $num > $spec['max']) {
            $num = $spec['max'] + 0;
        }

        return $num;
    }

    /**
     * Coerce a link value `{label,url,target?,variant?}`; invalid → default.
     */
    private function coerceLink(mixed $value, mixed $default): mixed
    {
        if (! is_array($value)) {
            return $default;
        }

        $label = is_string($value['label'] ?? null) ? $value['label'] : '';
        $url = is_string($value['url'] ?? null) ? $value['url'] : '#';

        $link = ['label' => $label, 'url' => $url];

        if (is_string($value['target'] ?? null)) {
            $link['target'] = $value['target'];
        }

        if (is_string($value['variant'] ?? null)) {
            $link['variant'] = $value['variant'];
        }

        return $link;
    }

    /**
     * Coerce a repeater: clamp count to `max`, coerce each item against the
     * item schema (recursing for nested media/repeaters).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function coerceRepeater(array $spec, mixed $value, array &$warnings): array
    {
        if (! is_array($value)) {
            return [];
        }

        $itemSchema = is_array($spec['item'] ?? null) ? $spec['item'] : [];
        $max = isset($spec['max']) && is_int($spec['max']) ? $spec['max'] : null;

        $items = array_values($value);

        if ($max !== null && count($items) > $max) {
            $warnings[] = "Repeater exceeded max of {$max} items; extra items dropped.";
            $items = array_slice($items, 0, $max);
        }

        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $out[] = $this->coerceFields($itemSchema, $item, $warnings);
        }

        return $out;
    }

    /**
     * Normalize a media reference into a MediaViewModel:
     *   {kind:"media", id:N} | {id:N} → look up cms_media
     *   {ref:"import-key"}            → unresolved (importer pre-resolves) → null + warn
     *   "https://… / /uploads/…"      → bare URL (tolerated, degraded)
     *   anything else                 → null
     *
     * @param  array<int, string>  $warnings
     */
    /**
     * Batch-load every media row referenced anywhere in the document into
     * {@see self::$mediaCache}, so resolveMedia() reads from memory instead of
     * issuing one query per image. Collecting a superset of ids is harmless —
     * the lookup is a single whereIn and unmatched ids are simply absent.
     *
     * @param  array<int|string, mixed>  $nodes
     */
    private function prefetchMedia(array $nodes): void
    {
        $ids = [];
        $this->collectMediaIds($nodes, $ids);
        $this->mediaCache = $ids === [] ? [] : $this->media->findMany($ids);
    }

    /**
     * Recursively gather candidate media ids (any `{... id: <numeric> ...}`
     * shape) from the raw document.
     *
     * @param  array<int|string, mixed>  $data
     * @param  array<int, int>  $ids
     */
    private function collectMediaIds(array $data, array &$ids): void
    {
        if (isset($data['id']) && is_numeric($data['id'])) {
            $ids[] = (int) $data['id'];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectMediaIds($value, $ids);
            }
        }
    }

    private function resolveMedia(mixed $value, array &$warnings): ?MediaViewModel
    {
        if (is_string($value) && $value !== '') {
            return MediaViewModel::fromUrl($value);
        }

        if (! is_array($value)) {
            return null;
        }

        if (isset($value['id']) && is_numeric($value['id'])) {
            $id = (int) $value['id'];
            // Resolved from the batch prefetch (see prefetchMedia), which loads a
            // superset of every referenced id — so an absent id means not found.
            $row = $this->mediaCache[$id] ?? null;

            if ($row === null) {
                $warnings[] = 'Media id '.$id.' not found — skipped.';

                return null;
            }

            return MediaViewModel::fromMedia($row);
        }

        if (isset($value['ref']) && is_string($value['ref'])) {
            // Import keys are resolved to ids by the demo importer (not yet built).
            $warnings[] = 'Unresolved media import key "'.$value['ref'].'" — skipped.';

            return null;
        }

        if (isset($value['url']) && is_string($value['url']) && $value['url'] !== '') {
            $alt = is_string($value['alt'] ?? null) ? $value['alt'] : '';

            return MediaViewModel::fromUrl($value['url'], $alt);
        }

        return null;
    }

    private function generateId(): string
    {
        return 'sec_'.bin2hex(random_bytes(6));
    }
}
