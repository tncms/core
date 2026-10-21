<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;
use TheNguyen\CMS\Models\UserProfileValue;

/**
 * Read model for the authenticated owner's dynamic profile-fields form (P3).
 *
 * Presentation only. Every VALUE is obtained through {@see UserProfileManager}
 * (authorization-filtered — a field the owner may not read is never surfaced).
 * Hard rules:
 *   - encrypted fields NEVER surface plaintext to HTML/JS/hidden inputs — only a
 *     boolean "has a stored value" flag, so a blank input means "unchanged";
 *   - `media` fields are display-only in P3 (no secure upload/serve seam exists),
 *     so they are excluded from the editable/write set;
 *   - a field the owner may READ but not EDIT renders read-only (no input);
 *   - labels/help/choice labels are localized with a current→default→neutral
 *     fallback; everything is escaped by Blade at render time.
 *
 * Definitions are read from the persisted Field Builder tables (so admin-created
 * fields work); value authority stays entirely with UserProfileManager.
 */
class AccountProfileFieldsPresenter
{
    /** @var Collection<int, UserProfileField>|null per-instance (per-request) cache */
    private ?Collection $activeFieldsCache = null;

    public function __construct(private readonly UserProfileManager $manager) {}

    /**
     * Editable, writeable (non-media) field models for the owner, keyed by key.
     * This is the authoritative server-side whitelist the update endpoint shapes
     * its payload against (the manager re-checks authorization on write).
     *
     * @return array<string, UserProfileField>
     */
    public function editableDefinitions(User $user): array
    {
        $editable = $this->manager->editableFields($user, $user);

        if ($editable === []) {
            return [];
        }

        $out = [];

        foreach ($this->activeFields() as $field) {
            if (! in_array($field->key, $editable, true)) {
                continue;
            }

            if ($field->type === 'media') {
                continue; // P3 scope: media is display-only (id-ref, no upload seam)
            }

            $out[$field->key] = $field;
        }

        return $out;
    }

    /**
     * Full render schema for the owner's profile form.
     *
     * @return array{hasFields: bool, action: string, groups: array<int, array{key: string, label: string, fields: array<int, array<string, mixed>>}>}
     */
    public function forOwner(User $user): array
    {
        $readable = $this->manager->readableFields($user, $user);

        if ($readable === []) {
            return ['hasFields' => false, 'action' => $this->action(), 'groups' => []];
        }

        $editableKeys = $this->manager->editableFields($user, $user);
        $values = $this->manager->values($user, $user); // authorization-filtered, batched

        /** @var Collection<int, UserProfileField> $fields */
        $fields = $this->activeFields()->filter(static fn (UserProfileField $f): bool => in_array($f->key, $readable, true));

        $groups = $this->groups();
        $trans = $this->translations($fields);
        $stored = $this->storedFieldIds($user, $fields); // one query: which fields have a row
        $locale = app()->getLocale();
        $default = default_locale();

        $buckets = [];

        foreach ($fields as $field) {
            $group = $groups->get((int) $field->group_id);
            $gkey = $group?->key ?? '_ungrouped';
            $encrypted = (bool) $field->is_encrypted;
            $fid = (int) $field->getKey();

            $buckets[$gkey]['group'] = $group;
            $buckets[$gkey]['sort'] = $group?->sort_order ?? 9999;
            $buckets[$gkey]['label'] = $group !== null
                ? ($this->resolve($trans, 'group', (int) $group->getKey(), 'label', $locale, $default) ?? $group->key)
                : '';
            $buckets[$gkey]['fields'][] = [
                'key' => $field->key,
                'id' => $fid,
                'dom_id' => 'pf_'.$fid,
                'type' => $field->type,
                'label' => $this->resolve($trans, 'field', $fid, 'label', $locale, $default) ?? $field->key,
                'help' => $this->resolve($trans, 'field', $fid, 'help', $locale, $default),
                'choices' => $this->choices($field, $trans, $fid, $locale, $default),
                // media is display-only in P3 (no secure upload/serve seam), so it
                // renders read-only even when its definition is owner-editable.
                'editable' => $field->type !== 'media' && in_array($field->key, $editableKeys, true),
                'encrypted' => $encrypted,
                'multiple' => $field->type === 'checkbox',
                // Encrypted values never leave the manager as plaintext here.
                'value' => $encrypted ? null : ($values[$field->key] ?? null),
                'hasValue' => in_array($fid, $stored, true),
            ];
        }

        uasort($buckets, static fn (array $a, array $b): int => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        $out = [];

        foreach ($buckets as $gkey => $bucket) {
            $out[] = [
                'key' => $gkey,
                'label' => $bucket['label'] ?? '',
                'fields' => $bucket['fields'] ?? [],
            ];
        }

        return ['hasFields' => true, 'action' => $this->action(), 'groups' => $out];
    }

    private function action(): string
    {
        return route('cms.account.profile.fields.update');
    }

    /** @return Collection<int, UserProfileField> */
    private function activeFields(): Collection
    {
        return $this->activeFieldsCache ??= UserProfileField::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('key')
            ->get();
    }

    /** @return Collection<int, UserProfileFieldGroup> keyed by id */
    private function groups(): Collection
    {
        return UserProfileFieldGroup::query()->orderBy('sort_order')->get()->keyBy(static fn (UserProfileFieldGroup $g): int => (int) $g->getKey());
    }

    /**
     * Which of the given fields have a stored value row for the user (one query).
     *
     * @param  Collection<int, UserProfileField>  $fields
     * @return array<int, int>
     */
    private function storedFieldIds(User $user, Collection $fields): array
    {
        if ($fields->isEmpty()) {
            return [];
        }

        return UserProfileValue::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('field_id', $fields->modelKeys())
            ->pluck('field_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Batch-load translations for the rendered fields and their groups.
     *
     * @param  Collection<int, UserProfileField>  $fields
     * @return array<string, UserProfileFieldTranslation> keyed by "owner_type:owner_id:locale"
     */
    private function translations(Collection $fields): array
    {
        $fieldIds = $fields->modelKeys();
        $groupIds = $fields->pluck('group_id')->filter()->unique()->values()->all();

        if ($fieldIds === [] && $groupIds === []) {
            return [];
        }

        $rows = UserProfileFieldTranslation::query()
            ->where(function ($q) use ($fieldIds, $groupIds): void {
                if ($fieldIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', 'field')->whereIn('owner_id', $fieldIds));
                }
                if ($groupIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', 'group')->whereIn('owner_id', $groupIds));
                }
            })
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->owner_type.':'.$row->owner_id.':'.$row->locale] = $row;
        }

        return $map;
    }

    /** @param array<string, UserProfileFieldTranslation> $trans */
    private function resolve(array $trans, string $type, int $id, string $col, string $locale, string $default): ?string
    {
        foreach ([$locale, $default, ''] as $loc) {
            $row = $trans[$type.':'.$id.':'.$loc] ?? null;
            $value = $row?->{$col};

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Localized choices [value => label] for select/radio/checkbox, else [].
     *
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

        // Optional localized option labels override the base labels.
        foreach ([$locale, $default, ''] as $loc) {
            $row = $trans['field:'.$fid.':'.$loc] ?? null;
            $labels = $row?->options_labels;

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
