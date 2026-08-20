<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Registries\SectionRegistry;
use TheNguyen\CMS\Support\LocalizedValue;

/**
 * Schema-aware localization of section fields (theme-architecture 12, 17 §6;
 * Phase 4F-B). Sits between the canonical pagebuilder/06 fields (which may hold
 * {@see LocalizedValue} objects, plain strings, media refs, links, repeaters)
 * and the two consumers that care about a single locale:
 *
 *   - the frontend ({@see SectionResolver}) — resolveForLocale(): localized
 *     leaves collapse to the current-locale string (with fallback) before the
 *     resolver coerces them; everything else (media/number/enum) is untouched;
 *   - the layout editor ({@see LayoutEditor}) — toEditable() projects a section
 *     onto the locale being edited, and applyEdit() merges an edit back into the
 *     localized objects without disturbing other locales.
 *
 * Which leaves are translatable is decided here, once: text/textarea/richtext
 * (unless the schema sets `translatable: false`) and a link's `label` — never a
 * link `url`, media, number, boolean, enum, icon, or id. Repeaters recurse into
 * their item schema. The rules are sensible by default and overridable per field.
 */
class SectionFieldLocalizer
{
    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly LanguageManager $languages,
    ) {}

    /**
     * Collapse a section's localized field leaves to the locale's resolved
     * string (current → default → first available → ''), leaving non-translatable
     * values untouched. The caller (resolver) then validates/defaults as usual.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function resolveForLocale(string $type, array $fields, ?string $locale = null): array
    {
        return $this->walkResolve($this->registry->fieldsSchema($type), $fields, $this->chain($locale));
    }

    /**
     * Project a section's fields onto a single locale for editing: a localized
     * leaf shows that locale's exact value (empty when untranslated); a plain
     * string shows as-is. Non-translatable leaves pass through unchanged.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function toEditable(string $type, array $fields, ?string $locale = null): array
    {
        return $this->walkEditable($this->registry->fieldsSchema($type), $fields, $this->editLocale($locale));
    }

    /**
     * Merge edited form values (single-locale strings for translatable leaves)
     * back into the stored fields, preserving every other locale. Plain strings
     * are migrated to localized objects lazily under the default locale.
     *
     * @param  array<string, mixed>  $existing  the section's currently stored fields
     * @param  array<string, mixed>  $edited  the form values for $locale
     * @return array<string, mixed>
     */
    public function applyEdit(string $type, array $existing, array $edited, ?string $locale = null): array
    {
        return $this->walkMerge(
            $this->registry->fieldsSchema($type),
            $existing,
            $edited,
            $this->editLocale($locale),
            $this->languages->defaultCode(),
        );
    }

    /**
     * Whether a schema field spec holds translatable text. Composite types
     * (link, repeater) are handled by the walkers, not here.
     *
     * @param  array<string, mixed>  $spec
     */
    public function isTranslatableSpec(array $spec): bool
    {
        $type = $spec['type'] ?? 'text';

        if (in_array($type, ['text', 'textarea', 'richtext'], true)) {
            return ($spec['translatable'] ?? true) !== false;
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Frontend resolution
    // -----------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $chain
     * @return array<string, mixed>
     */
    private function walkResolve(array $schema, array $fields, array $chain): array
    {
        foreach ($schema as $key => $spec) {
            if (! is_array($spec) || ! array_key_exists($key, $fields)) {
                continue;
            }

            if ($this->isTranslatableSpec($spec)) {
                $fields[$key] = LocalizedValue::resolve($fields[$key], $chain);
            } elseif (($spec['type'] ?? null) === 'link' && is_array($fields[$key])) {
                if (array_key_exists('label', $fields[$key])) {
                    $fields[$key]['label'] = LocalizedValue::resolve($fields[$key]['label'], $chain);
                }
            } elseif (($spec['type'] ?? null) === 'repeater' && is_array($fields[$key])) {
                $item = is_array($spec['item'] ?? null) ? $spec['item'] : [];
                $fields[$key] = array_map(
                    fn ($row) => is_array($row) ? $this->walkResolve($item, $row, $chain) : $row,
                    $fields[$key],
                );
            }
        }

        return $fields;
    }

    // -----------------------------------------------------------------
    // Editor projection
    // -----------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function walkEditable(array $schema, array $fields, string $locale): array
    {
        foreach ($schema as $key => $spec) {
            if (! is_array($spec) || ! array_key_exists($key, $fields)) {
                continue;
            }

            if ($this->isTranslatableSpec($spec)) {
                $fields[$key] = LocalizedValue::editValue($fields[$key], $locale);
            } elseif (($spec['type'] ?? null) === 'link' && is_array($fields[$key])) {
                if (array_key_exists('label', $fields[$key])) {
                    $fields[$key]['label'] = LocalizedValue::editValue($fields[$key]['label'], $locale);
                }
            } elseif (($spec['type'] ?? null) === 'repeater' && is_array($fields[$key])) {
                $item = is_array($spec['item'] ?? null) ? $spec['item'] : [];
                $fields[$key] = array_map(
                    fn ($row) => is_array($row) ? $this->walkEditable($item, $row, $locale) : $row,
                    $fields[$key],
                );
            }
        }

        return $fields;
    }

    // -----------------------------------------------------------------
    // Editor merge (write-back)
    // -----------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $edited
     * @return array<string, mixed>
     */
    private function walkMerge(array $schema, array $existing, array $edited, string $locale, string $default): array
    {
        $out = [];

        foreach ($schema as $key => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $new = $edited[$key] ?? null;
            $old = $existing[$key] ?? null;
            $type = $spec['type'] ?? 'text';

            if ($this->isTranslatableSpec($spec)) {
                $out[$key] = LocalizedValue::set($old, $locale, is_string($new) ? $new : '', $default);
            } elseif ($type === 'link') {
                $out[$key] = $this->mergeLink($old, $new, $locale, $default);
            } elseif ($type === 'repeater') {
                $item = is_array($spec['item'] ?? null) ? $spec['item'] : [];
                $out[$key] = $this->mergeRepeater($item, is_array($old) ? $old : [], is_array($new) ? $new : [], $locale, $default);
            } else {
                // Non-translatable (media/number/boolean/enum) — take the edit verbatim.
                $out[$key] = $new;
            }
        }

        return $out;
    }

    /**
     * Merge a link: only `label` is localized; `url`/`variant`/`target` are shared.
     */
    private function mergeLink(mixed $old, mixed $new, string $locale, string $default): array
    {
        $new = is_array($new) ? $new : [];
        $oldLabel = is_array($old) ? ($old['label'] ?? null) : null;

        $label = $new['label'] ?? null;
        $link = ['label' => LocalizedValue::set($oldLabel, $locale, is_string($label) ? $label : '', $default)];

        foreach (['url', 'variant', 'target'] as $shared) {
            if (array_key_exists($shared, $new)) {
                $link[$shared] = $new[$shared];
            }
        }

        return $link;
    }

    /**
     * Merge a repeater item-by-item (by index): the edited list is authoritative
     * for structure/order, localized leaves merge against the same-index stored
     * item. (Reordering items while editing a non-default locale is a known
     * limitation — see the editor docs.)
     *
     * @param  array<string, array<string, mixed>>  $itemSchema
     * @param  array<int, mixed>  $old
     * @param  array<int, mixed>  $new
     * @return array<int, array<string, mixed>>
     */
    private function mergeRepeater(array $itemSchema, array $old, array $new, string $locale, string $default): array
    {
        $oldList = array_values($old);
        $out = [];

        foreach (array_values($new) as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            $prior = isset($oldList[$i]) && is_array($oldList[$i]) ? $oldList[$i] : [];
            $out[] = $this->walkMerge($itemSchema, $prior, $item, $locale, $default);
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Locale helpers
    // -----------------------------------------------------------------

    /**
     * The read fallback chain: requested (or current) locale, then the default.
     * LocalizedValue::resolve() adds the "first available" and empty-safe tail.
     *
     * @return array<int, string>
     */
    private function chain(?string $locale): array
    {
        $current = $locale !== null && $locale !== '' ? $locale : $this->languages->currentCode();

        return array_values(array_unique(array_filter([$current, $this->languages->defaultCode()])));
    }

    /**
     * The single locale to edit/merge in (falls back to the default language).
     */
    private function editLocale(?string $locale): string
    {
        return $locale !== null && $locale !== '' ? $locale : $this->languages->defaultCode();
    }
}
