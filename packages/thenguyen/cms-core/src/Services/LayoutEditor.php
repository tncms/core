<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Str;
use TheNguyen\CMS\Registries\SectionRegistry;

/**
 * The reusable, theme-agnostic core for editing a homepage layout
 * (theme-architecture 18, pagebuilder/06). It loads/saves the canonical
 * pagebuilder/06 document stored at `theme.{slug}.homepage_layout`, falling back
 * to the active preset blueprint, and provides immutable section operations
 * (add / remove / duplicate / reorder / enable-disable / update) driven entirely
 * by the SectionRegistry — no per-preset special cases.
 *
 * Section operations are pure array transforms (a document in, a new document
 * out) so they are trivial to test; load()/save()/resetToPreset() are the only
 * storage-touching methods.
 */
class LayoutEditor
{
    private const VERSION = 1;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly PresetRepository $presets,
        private readonly SectionRegistry $registry,
        private readonly SectionResolver $resolver,
        private readonly ThemeManager $themes,
        private readonly MediaManager $media,
        private readonly SectionFieldLocalizer $localizer,
    ) {}

    // ---------------------------------------------------------------------
    // Layout CRUD (storage)
    // ---------------------------------------------------------------------

    /**
     * The editable layout document for a theme: the stored layout if present,
     * else the active preset's blueprint, else an empty document.
     *
     * @return array{version: int, sections: array<int, array<string, mixed>>}
     */
    public function load(?string $theme = null): array
    {
        $theme = $this->resolveTheme($theme);

        $stored = $this->settings->get($this->layoutKey($theme));
        if ($this->isDocument($stored)) {
            return $this->normalize($stored);
        }

        $presetId = $this->settings->get($this->presetKey($theme));
        if (is_string($presetId) && $presetId !== '') {
            $layout = $this->presets->layout($presetId, $theme);
            if ($this->isDocument($layout)) {
                return $this->normalize($layout);
            }
        }

        return ['version' => self::VERSION, 'sections' => []];
    }

    /**
     * Validate + persist a layout document at theme.{slug}.homepage_layout.
     * Rejects a structurally invalid document (no sections array); tolerates
     * per-section issues (collected as warnings).
     *
     * @param  array<string, mixed>  $document
     * @return array{success: bool, warnings: array<int, string>, errors: array<int, string>, document: array<string, mixed>}
     */
    public function save(?string $theme, array $document): array
    {
        $theme = $this->resolveTheme($theme);

        if (! isset($document['sections']) || ! is_array($document['sections'])) {
            return ['success' => false, 'warnings' => [], 'errors' => ['Layout has no "sections" array.'], 'document' => $document];
        }

        $document = $this->normalize($document);
        $document = $this->normalizeMedia($document);
        $resolved = $this->resolver->resolve($document, null);

        $this->settings->set($this->layoutKey($theme), $document, 'array', [
            'is_public' => true,
            'autoload' => true,
            'description' => 'Homepage layout (editor)',
        ]);

        return ['success' => true, 'warnings' => $resolved->warnings, 'errors' => [], 'document' => $document];
    }

    /**
     * Reset the homepage layout to the active preset's blueprint by clearing the
     * stored layout (the resolver/editor then fall back to the preset). Returns
     * the now-effective document.
     *
     * @return array{version: int, sections: array<int, array<string, mixed>>}
     */
    public function resetToPreset(?string $theme = null): array
    {
        $theme = $this->resolveTheme($theme);
        $this->settings->forget($this->layoutKey($theme));

        return $this->load($theme);
    }

    /**
     * A deep copy of a document with freshly generated section ids (a starting
     * point for "duplicate layout" / templating). Not persisted.
     *
     * @param  array<string, mixed>  $document
     * @return array{version: int, sections: array<int, array<string, mixed>>}
     */
    public function duplicateDocument(array $document): array
    {
        $document = $this->normalize($document);
        $document['sections'] = array_map(function (array $section): array {
            $section['id'] = $this->newId();

            return $section;
        }, $document['sections']);

        return $document;
    }

    // ---------------------------------------------------------------------
    // Section CRUD (pure transforms)
    // ---------------------------------------------------------------------

    /**
     * Build a new section node for a registered type, with schema defaults.
     *
     * @return array<string, mixed>
     */
    public function newSection(string $type): array
    {
        return [
            'id' => $this->newId(),
            'type' => $type,
            'enabled' => true,
            'binding' => null,
            'settings' => $this->registry->defaultSettings($type),
            'fields' => $this->registry->defaultFields($type),
        ];
    }

    /**
     * Add a section of the given type at $index (append when null/out of range).
     * Unknown types are ignored (returns the document unchanged).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function addSection(array $document, string $type, ?int $index = null): array
    {
        if (! $this->registry->has($type)) {
            return $this->normalize($document);
        }

        $document = $this->normalize($document);
        $node = $this->newSection($type);

        if ($index === null || $index < 0 || $index > count($document['sections'])) {
            $document['sections'][] = $node;
        } else {
            array_splice($document['sections'], $index, 0, [$node]);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function removeSection(array $document, string $id): array
    {
        $document = $this->normalize($document);
        $document['sections'] = array_values(array_filter(
            $document['sections'],
            static fn (array $section): bool => $section['id'] !== $id,
        ));

        return $document;
    }

    /**
     * Duplicate a section, inserting the clone (with a new id) right after it.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function duplicateSection(array $document, string $id): array
    {
        $document = $this->normalize($document);
        $index = $this->indexOf($document, $id);

        if ($index === null) {
            return $document;
        }

        $clone = $document['sections'][$index];
        $clone['id'] = $this->newId();

        array_splice($document['sections'], $index + 1, 0, [$clone]);

        return $document;
    }

    /**
     * Move a section one step up or down ($direction = "up" | "down").
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function moveSection(array $document, string $id, string $direction): array
    {
        $document = $this->normalize($document);
        $index = $this->indexOf($document, $id);

        if ($index === null) {
            return $document;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($document['sections'])) {
            return $document;
        }

        [$document['sections'][$index], $document['sections'][$target]] =
            [$document['sections'][$target], $document['sections'][$index]];

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function setEnabled(array $document, string $id, bool $enabled): array
    {
        return $this->mutateSection($document, $id, static function (array $section) use ($enabled): array {
            $section['enabled'] = $enabled;

            return $section;
        });
    }

    /**
     * Replace a section's settings + fields (used after editing its form).
     *
     * When `$locale` is given, the edited form values (single-locale strings for
     * translatable leaves) are merged back into the section's localized field
     * objects, preserving every other locale (Phase 4F-B). When null, the fields
     * are stored verbatim (programmatic/legacy callers keep plain strings).
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateSection(array $document, string $id, array $settings, array $fields, ?string $locale = null): array
    {
        $localizer = $this->localizer;

        return $this->mutateSection($document, $id, static function (array $section) use ($settings, $fields, $locale, $localizer): array {
            $section['settings'] = $settings;

            $section['fields'] = $locale === null
                ? $fields
                : $localizer->applyEdit((string) $section['type'], is_array($section['fields'] ?? null) ? $section['fields'] : [], $fields, $locale);

            return $section;
        });
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    public function getSection(array $document, string $id): ?array
    {
        $document = $this->normalize($document);
        $index = $this->indexOf($document, $id);

        return $index === null ? null : $document['sections'][$index];
    }

    /**
     * Project a section's stored fields into editor-form values:
     *   - localized text leaves collapse to the edited locale's string (Phase 4F-B);
     *   - a canonical media ref ({kind:media,id}) resolves to its URL string so the
     *     URL-based MediaPicker can display it.
     * Other fields pass through unchanged. The inverse runs on save (media →
     * {kind:media,id}) and on {@see updateSection()} (locale string → localized).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function toEditorFields(string $type, array $fields, ?string $locale = null): array
    {
        $fields = $this->localizer->toEditable($type, $fields, $locale);

        return $this->mediaToUrls($this->registry->fieldsSchema($type), $fields);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $document
     */
    private function indexOf(array $document, string $id): ?int
    {
        foreach ($document['sections'] as $i => $section) {
            if (($section['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     * @return array<string, mixed>
     */
    private function mutateSection(array $document, string $id, callable $mutator): array
    {
        $document = $this->normalize($document);

        foreach ($document['sections'] as $i => $section) {
            if (($section['id'] ?? null) === $id) {
                $document['sections'][$i] = $mutator($section);
                break;
            }
        }

        return $document;
    }

    /**
     * Normalize a document: ensure version + a list of well-formed section nodes
     * (each with id, type, enabled flag, settings, fields).
     *
     * @param  array<string, mixed>  $document
     * @return array{version: int, sections: array<int, array<string, mixed>>}
     */
    private function normalize(array $document): array
    {
        $sections = [];

        foreach (is_array($document['sections'] ?? null) ? $document['sections'] : [] as $section) {
            if (! is_array($section) || ! is_string($section['type'] ?? null)) {
                continue;
            }

            $sections[] = [
                'id' => is_string($section['id'] ?? null) && $section['id'] !== '' ? $section['id'] : $this->newId(),
                'type' => $section['type'],
                'enabled' => ($section['enabled'] ?? true) !== false,
                'binding' => $section['binding'] ?? null,
                'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
                'fields' => is_array($section['fields'] ?? null) ? $section['fields'] : [],
            ];
        }

        return [
            'version' => is_int($document['version'] ?? null) ? $document['version'] : self::VERSION,
            'sections' => $sections,
        ];
    }

    /**
     * Canonicalize every media field across the document to {kind:media,id}.
     * A picked/legacy URL is resolved to its media id when a cms_media row
     * matches (else the URL is kept for rendering compatibility); an empty value
     * becomes null; an already-canonical ref or an unresolved import {ref} is
     * preserved. Walks each section's fields schema, recursing into repeaters.
     *
     * @param  array{version: int, sections: array<int, array<string, mixed>>}  $document
     * @return array{version: int, sections: array<int, array<string, mixed>>}
     */
    private function normalizeMedia(array $document): array
    {
        foreach ($document['sections'] as $i => $section) {
            $type = is_string($section['type'] ?? null) ? $section['type'] : '';
            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];

            $document['sections'][$i]['fields'] = $this->normalizeMediaFields(
                $this->registry->fieldsSchema($type),
                $fields,
            );
        }

        return $document;
    }

    /**
     * Normalize the media values in a fields map against a fields schema,
     * recursing into repeaters. Non-media fields are untouched.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalizeMediaFields(array $schema, array $fields): array
    {
        foreach ($schema as $key => $spec) {
            if (! is_array($spec) || ! array_key_exists($key, $fields)) {
                continue;
            }

            $type = $spec['type'] ?? 'text';

            if ($type === 'media') {
                $fields[$key] = $this->normalizeMediaValue($fields[$key]);
            } elseif ($type === 'repeater' && is_array($fields[$key])) {
                $itemSchema = is_array($spec['item'] ?? null) ? $spec['item'] : [];
                $fields[$key] = array_map(
                    fn ($item) => is_array($item) ? $this->normalizeMediaFields($itemSchema, $item) : $item,
                    $fields[$key],
                );
            }
        }

        return $fields;
    }

    /**
     * Normalize a single media field value:
     *   {kind:media,id} | {id}  → {kind:media,id}
     *   {ref:key}               → preserved (resolved by the demo importer)
     *   {url:...} / URL string  → {kind:media,id} when resolvable, else the URL
     *   empty / anything else   → null
     */
    private function normalizeMediaValue(mixed $value): array|string|null
    {
        if (is_array($value)) {
            if (isset($value['id']) && is_numeric($value['id'])) {
                return ['kind' => 'media', 'id' => (int) $value['id']];
            }

            if (isset($value['ref']) && is_string($value['ref'])) {
                return $value;
            }

            if (isset($value['url']) && is_string($value['url']) && $value['url'] !== '') {
                return $this->normalizeMediaUrl($value['url']);
            }

            return null;
        }

        if (is_string($value) && $value !== '') {
            return $this->normalizeMediaUrl($value);
        }

        return null;
    }

    /**
     * Resolve a URL to a canonical media ref when a cms_media row matches; keep
     * the URL string otherwise (rendering compatibility — the resolver degrades
     * gracefully from a bare URL).
     */
    private function normalizeMediaUrl(string $url): array|string
    {
        $media = $this->media->findByUrl($url);

        return $media !== null ? ['kind' => 'media', 'id' => (int) $media->id] : $url;
    }

    /**
     * Inverse of media normalization for the editor form: resolve a canonical
     * media ref to its URL string (the MediaPicker works in URLs). Recurses into
     * repeaters; non-media fields pass through.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function mediaToUrls(array $schema, array $fields): array
    {
        foreach ($schema as $key => $spec) {
            if (! is_array($spec) || ! array_key_exists($key, $fields)) {
                continue;
            }

            $type = $spec['type'] ?? 'text';

            if ($type === 'media') {
                $fields[$key] = $this->mediaToUrl($fields[$key]);
            } elseif ($type === 'repeater' && is_array($fields[$key])) {
                $itemSchema = is_array($spec['item'] ?? null) ? $spec['item'] : [];
                $fields[$key] = array_map(
                    fn ($item) => is_array($item) ? $this->mediaToUrls($itemSchema, $item) : $item,
                    $fields[$key],
                );
            }
        }

        return $fields;
    }

    /**
     * Resolve a single stored media value to a URL string for the picker.
     * Unknown ids / unresolved refs / empties become '' (picker shows "no image").
     */
    private function mediaToUrl(mixed $value): string
    {
        if (is_array($value)) {
            if (isset($value['id']) && is_numeric($value['id'])) {
                $row = $this->media->find((int) $value['id']);

                return $row !== null ? (string) $row->url : '';
            }

            if (isset($value['url']) && is_string($value['url'])) {
                return $value['url'];
            }

            return '';
        }

        return is_string($value) ? $value : '';
    }

    private function isDocument(mixed $value): bool
    {
        return is_array($value) && isset($value['sections']) && is_array($value['sections']);
    }

    private function resolveTheme(?string $theme): string
    {
        if (is_string($theme) && $theme !== '') {
            return $theme;
        }

        return $this->themes->active()?->slug ?? 'default';
    }

    private function layoutKey(string $theme): string
    {
        return 'theme.'.$theme.'.homepage_layout';
    }

    private function presetKey(string $theme): string
    {
        return 'theme.'.$theme.'.homepage_preset';
    }

    private function newId(): string
    {
        return 'sec_'.Str::lower(Str::random(10));
    }
}
