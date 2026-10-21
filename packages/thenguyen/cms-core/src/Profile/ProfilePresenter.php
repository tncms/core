<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;

/**
 * Privacy-first READ/DISPLAY model for presenting a target user's profile to a
 * viewer (Account Profile Field Engine, P4). Presentation only.
 *
 * Authorization is delegated ENTIRELY to {@see UserProfileManager} — there is no
 * second authorization implementation here. `present()` only ever renders what
 * `UserProfileManager::values($target, $viewer)` already returned (a field the
 * viewer may not read is absent, with no existence oracle). The viewer is an
 * EXPLICIT argument — a guest viewer (null) sees public fields only; there is no
 * viewer = target fallback.
 *
 * Hard presentation rules (distinct from the privileged manager service path):
 *   - encrypted fields are NEVER surfaced here (no plaintext/ciphertext in the
 *     presentation API) — read them through the manager with an authorized actor;
 *   - `media` fields are omitted (id-reference only; no certified serving seam);
 *   - only active fields, ordered by group then field, with localized labels/help/
 *     option-labels and a current → default → neutral locale fallback;
 *   - values are display-normalized (choice LABELS, not raw keys); all output is
 *     escaped by Blade at render time and never evaluated as PHP.
 *
 * Batched: `present()` is a constant number of queries; `presentMany()` uses
 * {@see UserProfileManager::valuesForMany()} and shares one field/translation load
 * (no per-user field query growth).
 */
class ProfilePresenter
{
    /** Types excluded from presentation output (sensitive or no safe display seam). */
    private const EXCLUDED_TYPES = ['media'];

    public function __construct(private readonly UserProfileManager $manager) {}

    /**
     * Render model for a single target as seen by $viewer.
     *
     * @param  array<int, string>|null  $only  restrict to these field keys
     * @return array{target_id: int, hasFields: bool, groups: array<int, array{key: string, label: string, fields: array<int, array<string, mixed>>}>}
     */
    public function present(Model $target, ?Authenticatable $viewer, ?array $only = null): array
    {
        $values = $this->manager->values($target, $viewer); // authorization-filtered

        return $this->build((int) $target->getKey(), $values, $only);
    }

    /**
     * Batched render models for many targets (no N+1).
     *
     * @param  iterable<int, Model>  $targets
     * @param  array<int, string>|null  $only
     * @return array<int, array{target_id: int, hasFields: bool, groups: array<int, array<string, mixed>>}>
     */
    public function presentMany(iterable $targets, ?Authenticatable $viewer, ?array $only = null): array
    {
        $targets = is_array($targets) ? $targets : iterator_to_array($targets);
        $valuesByUser = $this->manager->valuesForMany($targets, $viewer); // 2 queries total

        // Warm the shared field + translation caches once.
        $this->activeFields();
        $this->translations();

        $out = [];

        foreach ($targets as $target) {
            $tid = (int) $target->getKey();
            $out[$tid] = $this->build($tid, $valuesByUser[$tid] ?? [], $only);
        }

        return $out;
    }

    /**
     * Convenience: only the publicly/authorized readable URL links (url-type
     * fields) for a target, as safe link descriptors. No hard-coded social schema.
     *
     * @return array<int, array{key: string, label: string, url: string}>
     */
    public function links(Model $target, ?Authenticatable $viewer): array
    {
        $links = [];

        foreach ($this->present($target, $viewer)['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['type'] === 'url' && is_string($field['href'] ?? null) && $field['href'] !== '') {
                    $links[] = ['key' => $field['key'], 'label' => $field['label'], 'url' => $field['href']];
                }
            }
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $values  already authorization-filtered
     * @param  array<int, string>|null  $only
     * @return array{target_id: int, hasFields: bool, groups: array<int, array<string, mixed>>}
     */
    private function build(int $targetId, array $values, ?array $only): array
    {
        if ($values === []) {
            return ['target_id' => $targetId, 'hasFields' => false, 'groups' => []];
        }

        $fields = $this->activeFields();
        $groups = $this->groups();
        $trans = $this->translations();
        $locale = app()->getLocale();
        $default = default_locale();
        $onlySet = $only !== null ? array_fill_keys($only, true) : null;

        $buckets = [];

        foreach ($fields as $field) {
            $key = $field->key;

            if (! array_key_exists($key, $values)) {
                continue; // not readable by the viewer (or no value) — omit
            }

            if ($onlySet !== null && ! isset($onlySet[$key])) {
                continue;
            }

            if ((bool) $field->is_encrypted || in_array($field->type, self::EXCLUDED_TYPES, true)) {
                continue; // never present encrypted/media values
            }

            $value = $values[$key];

            if ($value === null || $value === '' || $value === []) {
                continue; // nothing to show
            }

            $group = $groups->get((int) $field->group_id);
            $gkey = $group?->key ?? '_ungrouped';
            $fid = (int) $field->getKey();
            $choices = $this->choices($field, $trans, $fid, $locale, $default);

            $buckets[$gkey]['sort'] = $group?->sort_order ?? 9999;
            $buckets[$gkey]['label'] = $group !== null
                ? ($this->resolve($trans, 'group', (int) $group->getKey(), 'label', $locale, $default) ?? $group->key)
                : '';
            $buckets[$gkey]['fields'][] = [
                'key' => $key,
                'type' => $field->type,
                'label' => $this->resolve($trans, 'field', $fid, 'label', $locale, $default) ?? $key,
                'help' => $this->resolve($trans, 'field', $fid, 'help', $locale, $default),
                'value' => $value,
                'display' => $this->displayValue($field->type, $value, $choices),
                // Safe external href for url fields (scheme re-checked at display),
                // null for everything else — the partial renders a link only when set.
                'href' => $field->type === 'url' ? $this->safeUrl((string) $value, $field) : null,
            ];
        }

        uasort($buckets, static fn (array $a, array $b): int => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        $out = [];

        foreach ($buckets as $gkey => $bucket) {
            $out[] = ['key' => $gkey, 'label' => $bucket['label'] ?? '', 'fields' => $bucket['fields'] ?? []];
        }

        return ['target_id' => $targetId, 'hasFields' => $out !== [], 'groups' => $out];
    }

    /**
     * Human-readable display projection (choice LABELS, booleans, scalars).
     *
     * @param  array<string, string>  $choices
     */
    private function displayValue(string $type, mixed $value, array $choices): string
    {
        return match ($type) {
            'checkbox' => implode(', ', array_map(static fn ($v): string => $choices[(string) $v] ?? (string) $v, (array) $value)),
            'select', 'radio' => $choices[(string) $value] ?? (string) $value,
            'boolean' => $value ? __('Yes') : __('No'),
            default => is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value,
        };
    }

    /**
     * A safe external href for a url value, or null. Re-checks the scheme against
     * the field's allow-list (default http/https) at display time — defence in
     * depth over the write-time validation (never emits javascript:/data:/etc.).
     */
    private function safeUrl(string $url, UserProfileField $field): ?string
    {
        if ($url === '') {
            return null;
        }

        $allowed = $field->options['schemes'] ?? ['https', 'http'];
        $allowed = array_map('strtolower', (array) $allowed);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return ($scheme !== '' && in_array($scheme, $allowed, true)) ? $url : null;
    }

    /** @var Collection<int, UserProfileField>|null */
    private ?Collection $fieldsCache = null;

    /** @var array<string, UserProfileFieldTranslation>|null */
    private ?array $transCache = null;

    /** @return Collection<int, UserProfileField> */
    private function activeFields(): Collection
    {
        return $this->fieldsCache ??= UserProfileField::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('key')
            ->get();
    }

    /** @var Collection<int, UserProfileFieldGroup>|null */
    private ?Collection $groupsCache = null;

    /** @return Collection<int, UserProfileFieldGroup> keyed by id */
    private function groups(): Collection
    {
        return $this->groupsCache ??= UserProfileFieldGroup::query()->orderBy('sort_order')->get()
            ->keyBy(static fn (UserProfileFieldGroup $g): int => (int) $g->getKey());
    }

    /** @return array<string, UserProfileFieldTranslation> keyed by "owner_type:owner_id:locale" */
    private function translations(): array
    {
        if ($this->transCache !== null) {
            return $this->transCache;
        }

        $fields = $this->activeFields();
        $fieldIds = $fields->modelKeys();
        $groupIds = $fields->pluck('group_id')->filter()->unique()->values()->all();

        $rows = ($fieldIds === [] && $groupIds === [])
            ? collect()
            : UserProfileFieldTranslation::query()
                ->where(function ($q) use ($fieldIds, $groupIds): void {
                    if ($fieldIds !== []) {
                        $q->orWhere(fn ($w) => $w->where('owner_type', 'field')->whereIn('owner_id', $fieldIds));
                    }
                    if ($groupIds !== []) {
                        $q->orWhere(fn ($w) => $w->where('owner_type', 'group')->whereIn('owner_id', $groupIds));
                    }
                })->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->owner_type.':'.$row->owner_id.':'.$row->locale] = $row;
        }

        return $this->transCache = $map;
    }

    /** @param array<string, UserProfileFieldTranslation> $trans */
    private function resolve(array $trans, string $type, int $id, string $col, string $locale, string $default): ?string
    {
        foreach ([$locale, $default, ''] as $loc) {
            $value = ($trans[$type.':'.$id.':'.$loc] ?? null)?->{$col};

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, UserProfileFieldTranslation>  $trans
     * @return array<string, string>
     */
    private function choices(UserProfileField $field, array $trans, int $fid, string $locale, string $default): array
    {
        if (! in_array($field->type, ['select', 'radio', 'checkbox'], true)) {
            return [];
        }

        $raw = $field->options['choices'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $base = array_is_list($raw) ? array_combine($raw, $raw) : $raw;

        foreach ([$locale, $default, ''] as $loc) {
            $labels = ($trans['field:'.$fid.':'.$loc] ?? null)?->options_labels;

            if (is_array($labels) && $labels !== []) {
                foreach ($base as $value => $label) {
                    if (isset($labels[$value]) && is_string($labels[$value]) && $labels[$value] !== '') {
                        $base[$value] = $labels[$value];
                    }
                }
                break;
            }
        }

        $out = [];

        foreach ($base as $value => $label) {
            $out[(string) $value] = is_string($label) && $label !== '' ? $label : (string) $value;
        }

        return $out;
    }
}
