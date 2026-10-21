<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ProfileFieldCollisionException;
use TheNguyen\CMS\Exceptions\ProfileFieldFrozenException;
use TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;
use TheNguyen\CMS\Models\UserProfileValue;

/**
 * Audited, transactional write authority for admin-managed profile field
 * definitions (Account Profile Field Builder, P2).
 *
 * This is the ONLY supported admin write path. It enforces, server-side and
 * independently of any Filament UI:
 *  - the `profile_fields.manage` permission (defence in depth);
 *  - the reserved `custom.` admin namespace for admin-created field keys;
 *  - reserved-key, type/visibility/editability allow-lists and no-arbitrary-PHP
 *    metadata (shared with {@see UserProfileFieldRegistry});
 *  - collision rejection against both persisted rows and code-declared
 *    definitions (admin may never hijack a Core/plugin key);
 *  - immutability of Core/plugin-owned (locked) definitions;
 *  - freezing of data-shape properties (type, encryption, options, key) once a
 *    field has stored values.
 * It never reads or writes users' private profile VALUES.
 */
class ProfileFieldAdminService
{
    /** Reserved leading namespace for admin-created fields. */
    public const ADMIN_NAMESPACE = 'custom';

    public function __construct(private readonly UserProfileFieldRegistry $registry) {}

    // ---------------- Groups ----------------

    /** @param array{key?: string, sort_order?: int, is_active?: bool, label?: string, label_vi?: string} $data */
    public function createGroup(array $data, ?Authenticatable $actor): UserProfileFieldGroup
    {
        $this->authorize($actor);
        $key = $this->normalize($data['key'] ?? '');
        $this->assertGroupKeyShape($key);
        $this->assertNotReservedGroup($key);

        if (UserProfileFieldGroup::query()->where('key', $key)->exists() || $this->registry->hasGroup($key)) {
            throw ProfileFieldCollisionException::crossSource($key, 'existing', 'admin');
        }

        return DB::transaction(function () use ($key, $data): UserProfileFieldGroup {
            $group = UserProfileFieldGroup::query()->create([
                'key' => $key,
                'source' => 'admin',
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_locked' => false,
            ]);
            $this->writeTranslations('group', (int) $group->getKey(), $data);

            return $group;
        });
    }

    /** @param array{sort_order?: int, is_active?: bool, label?: string, label_vi?: string} $data */
    public function updateGroup(UserProfileFieldGroup $group, array $data, ?Authenticatable $actor): UserProfileFieldGroup
    {
        $this->authorize($actor);

        if ($group->source !== 'admin') {
            // Locked group: only ordering/activation may change.
            $group->update(['sort_order' => (int) ($data['sort_order'] ?? $group->sort_order), 'is_active' => (bool) ($data['is_active'] ?? $group->is_active)]);

            return $group;
        }

        return DB::transaction(function () use ($group, $data): UserProfileFieldGroup {
            $group->update([
                'sort_order' => (int) ($data['sort_order'] ?? $group->sort_order),
                'is_active' => (bool) ($data['is_active'] ?? $group->is_active),
            ]);
            $this->writeTranslations('group', (int) $group->getKey(), $data);

            return $group;
        });
    }

    public function deleteGroup(UserProfileFieldGroup $group, ?Authenticatable $actor): void
    {
        $this->authorize($actor);

        if ($group->source !== 'admin') {
            throw ProfileFieldFrozenException::locked($group->key);
        }

        if ($group->fields()->exists()) {
            throw new InvalidProfileFieldDefinitionException(
                "Group \"{$group->key}\" still has fields; move or delete them first.",
            );
        }

        DB::transaction(function () use ($group): void {
            UserProfileFieldTranslation::query()->where('owner_type', 'group')->where('owner_id', $group->getKey())->delete();
            $group->delete();
        });
    }

    // ---------------- Fields ----------------

    /** @param array<string, mixed> $data */
    public function createField(array $data, ?Authenticatable $actor): UserProfileField
    {
        $this->authorize($actor);
        $key = $this->normalize($data['key'] ?? '');
        $this->assertFieldKeyShape($key);
        $this->assertAdminNamespace($key);
        $this->assertNotReservedField($key);

        if (UserProfileField::query()->where('key', $key)->exists() || $this->registry->has($key)) {
            throw ProfileFieldCollisionException::crossSource($key, 'existing', 'admin');
        }

        $group = $this->resolveGroup($data['group'] ?? $data['group_id'] ?? null);
        $type = $this->assertType($data['type'] ?? '');
        $visibility = $this->assertVisibility($data['visibility'] ?? 'owner');
        $editability = $this->assertEditability($data['editability'] ?? 'owner');
        $options = $this->safeArray($key, 'options', $data['options'] ?? []);

        return DB::transaction(function () use ($key, $group, $type, $visibility, $editability, $options, $data): UserProfileField {
            $field = UserProfileField::query()->create([
                'key' => $key,
                'group_id' => $group->getKey(),
                'type' => $type,
                'options' => $options,
                'validation' => $this->safeArray($key, 'validation', $data['validation'] ?? []),
                'default_value' => $data['default_value'] ?? null,
                'visibility' => $visibility,
                'editability' => $editability,
                'is_encrypted' => (bool) ($data['is_encrypted'] ?? false),
                'source' => 'admin',
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_locked' => false,
                'schema_revision' => 1,
            ]);
            $this->writeTranslations('field', (int) $field->getKey(), $data);

            return $field;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateField(UserProfileField $field, array $data, ?Authenticatable $actor): UserProfileField
    {
        $this->authorize($actor);

        // Core/plugin-owned fields: only ordering/activation may change.
        if ($field->source !== 'admin') {
            $field->update([
                'sort_order' => (int) ($data['sort_order'] ?? $field->sort_order),
                'is_active' => (bool) ($data['is_active'] ?? $field->is_active),
            ]);

            return $field;
        }

        $hasValues = $this->impactedValueCount($field) > 0;
        $attrs = [
            'sort_order' => (int) ($data['sort_order'] ?? $field->sort_order),
            'is_active' => (bool) ($data['is_active'] ?? $field->is_active),
            'visibility' => $this->assertVisibility($data['visibility'] ?? $field->visibility),
            'editability' => $this->assertEditability($data['editability'] ?? $field->editability),
        ];

        // Data-shape properties: frozen once values exist; validated otherwise.
        $this->applyShape($field, $data, $attrs, 'type', fn ($v) => $this->assertType($v), $hasValues);
        $this->applyShape($field, $data, $attrs, 'is_encrypted', fn ($v) => (bool) $v, $hasValues);
        $this->applyShape($field, $data, $attrs, 'options', fn ($v) => $this->safeArray($field->key, 'options', is_array($v) ? $v : []), $hasValues);

        if (array_key_exists('default_value', $data)) {
            $attrs['default_value'] = $data['default_value'];
        }

        return DB::transaction(function () use ($field, $attrs, $data): UserProfileField {
            $field->update($attrs);
            $this->writeTranslations('field', (int) $field->getKey(), $data);

            return $field;
        });
    }

    public function deleteField(UserProfileField $field, ?Authenticatable $actor): void
    {
        $this->authorize($actor);

        if ($field->source !== 'admin') {
            throw ProfileFieldFrozenException::locked($field->key);
        }

        // Value rows cascade via the FK; translations are removed explicitly.
        DB::transaction(function () use ($field): void {
            UserProfileFieldTranslation::query()->where('owner_type', 'field')->where('owner_id', $field->getKey())->delete();
            $field->delete();
        });
    }

    /** Preflight: how many stored values a field deletion/freeze would affect. */
    public function impactedValueCount(UserProfileField $field): int
    {
        return (int) UserProfileValue::query()->where('field_id', $field->getKey())->count();
    }

    // ---------------- Internals ----------------

    private function authorize(?Authenticatable $actor): void
    {
        if ($actor === null || ! cms_can(UserProfileManager::PERM_MANAGE, $actor)) {
            throw new AuthorizationException('Not allowed to manage profile fields.');
        }
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $attrs */
    private function applyShape(UserProfileField $field, array $data, array &$attrs, string $key, callable $cast, bool $hasValues): void
    {
        if (! array_key_exists($key, $data)) {
            return;
        }

        $new = $cast($data[$key]);

        if ($hasValues && $new != $field->{$key}) {
            throw ProfileFieldFrozenException::hasValues($field->key, $key);
        }

        $attrs[$key] = $new;
    }

    private function resolveGroup(mixed $groupRef): UserProfileFieldGroup
    {
        if (is_numeric($groupRef)) {
            $g = UserProfileFieldGroup::query()->find((int) $groupRef);
        } else {
            $g = UserProfileFieldGroup::query()->where('key', $this->normalize((string) $groupRef))->first();
        }

        if ($g === null) {
            throw new InvalidProfileFieldDefinitionException('Field references an unknown group.');
        }

        return $g;
    }

    private function normalize(string $key): string
    {
        return strtolower(trim($key));
    }

    private function assertGroupKeyShape(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw InvalidProfileFieldDefinitionException::because("Group key \"{$key}\" must be a single snake_case segment.");
        }
    }

    private function assertFieldKeyShape(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw InvalidProfileFieldDefinitionException::because("Field key \"{$key}\" must be namespaced snake_case.");
        }
    }

    private function assertAdminNamespace(string $key): void
    {
        if (explode('.', $key, 2)[0] !== self::ADMIN_NAMESPACE) {
            throw ReservedProfileFieldKeyException::for($key, 'admin-created fields must use the reserved "'.self::ADMIN_NAMESPACE.'." namespace.');
        }
    }

    private function assertNotReservedField(string $key): void
    {
        $first = explode('.', $key, 2)[0];
        if (in_array($key, UserProfileFieldRegistry::RESERVED_KEYS, true) || in_array($first, UserProfileFieldRegistry::RESERVED_KEYS, true)) {
            throw ReservedProfileFieldKeyException::for($key, 'it shadows an auth identifier or users-table column.');
        }
    }

    private function assertNotReservedGroup(string $key): void
    {
        if ($key === UserProfileFieldRegistry::RESERVED_NAMESPACE || in_array($key, UserProfileFieldRegistry::RESERVED_KEYS, true)) {
            throw ReservedProfileFieldKeyException::for($key, 'the group key is reserved.');
        }
    }

    private function assertType(mixed $type): string
    {
        $type = (string) $type;
        if (! in_array($type, UserProfileFieldRegistry::TYPES, true)) {
            throw InvalidProfileFieldDefinitionException::because("Unknown field type \"{$type}\".");
        }

        return $type;
    }

    private function assertVisibility(mixed $v): string
    {
        $v = (string) $v;
        if (! in_array($v, UserProfileFieldRegistry::VISIBILITIES, true)) {
            throw InvalidProfileFieldDefinitionException::because("Invalid visibility \"{$v}\".");
        }

        return $v;
    }

    private function assertEditability(mixed $v): string
    {
        $v = (string) $v;
        if (! in_array($v, UserProfileFieldRegistry::EDITABILITIES, true)) {
            throw InvalidProfileFieldDefinitionException::because("Invalid editability \"{$v}\".");
        }

        return $v;
    }

    /** @return array<string, mixed> */
    private function safeArray(string $key, string $field, mixed $value): array
    {
        if ($value === [] || $value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw InvalidProfileFieldDefinitionException::because("Field \"{$key}\" {$field} must be an array.");
        }
        $this->assertNoPhp($key, $value);

        return $value;
    }

    private function assertNoPhp(string $key, mixed $value): void
    {
        if ($value instanceof \Closure || is_object($value)) {
            throw InvalidProfileFieldDefinitionException::because("Field \"{$key}\" metadata must not contain PHP callbacks or objects.");
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoPhp($key, $item);
            }
        }
    }

    /** Persist default-locale + optional VI label/help translations. @param array<string, mixed> $data */
    private function writeTranslations(string $ownerType, int $ownerId, array $data): void
    {
        $default = function_exists('default_locale') ? default_locale() : (string) config('app.locale', 'en');

        if (array_key_exists('label', $data) || array_key_exists('help', $data)) {
            UserProfileFieldTranslation::query()->updateOrCreate(
                ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'locale' => $default],
                ['label' => $data['label'] ?? null, 'help' => $data['help'] ?? null],
            );
        }

        // Only write the explicit VI row when Vietnamese is NOT already the site
        // default (otherwise the default-locale row above IS the VI row, and we
        // must not overwrite it with the secondary input).
        if ($default !== 'vi' && (array_key_exists('label_vi', $data) || array_key_exists('help_vi', $data))) {
            UserProfileFieldTranslation::query()->updateOrCreate(
                ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'locale' => 'vi'],
                ['label' => $data['label_vi'] ?? null, 'help' => $data['help_vi'] ?? null],
            );
        }
    }
}
