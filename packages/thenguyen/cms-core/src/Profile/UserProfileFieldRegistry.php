<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ProfileFieldCollisionException;
use TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;

/**
 * In-memory registry of user profile field definitions
 * (Account Profile Field Engine, P1-A — CORE-ACCOUNT-PROFILE-1).
 *
 * Mirrors the PermissionManager idiom: plugins/Core register definitions in
 * their service provider (in-memory, no DB), and persistence happens only when
 * {@see reconcile()} is called explicitly — schema-guarded and non-destructive.
 * There is NO unguarded per-boot write (CLARIFICATION-001).
 *
 * Registration never silently overwrites: a key owned by another source, or the
 * same source with an incompatible definition, is a hard collision. Registering
 * a field does NOT grant any read access to user values; the privacy-aware read
 * API (UserProfileManager) is P1-B.
 */
class UserProfileFieldRegistry
{
    public const TYPES = [
        'text', 'textarea', 'number', 'date', 'datetime',
        'select', 'checkbox', 'radio', 'boolean', 'url', 'email', 'media',
    ];

    public const VISIBILITIES = ['public', 'authenticated', 'owner', 'admin'];

    public const EDITABILITIES = ['owner', 'admin', 'system', 'immutable'];

    public const RESERVED_NAMESPACE = 'core';

    /**
     * Auth identifiers and users-table columns that a profile field may never
     * shadow (as a full key or as a leading namespace segment).
     */
    public const RESERVED_KEYS = [
        'id', 'name', 'username', 'email', 'email_verified_at', 'password',
        'remember_token', 'remember_token_rotated_at', 'avatar', 'phone', 'bio',
        'timezone', 'admin_locale', 'frontend_locale', 'editing_locale',
        'frontend_last_login_at', 'frontend_last_login_ip',
        'frontend_last_user_agent_hash', 'frontend_session_version',
        'created_at', 'updated_at', 'role', 'roles', 'status',
    ];

    /** @var array<string, array{key: string, source: string, sort_order: int, label: ?string, help: ?string}> */
    private array $groups = [];

    /** @var array<string, ProfileFieldDefinition> */
    private array $fields = [];

    // -----------------------------------------------------------------
    // Registration (in-memory)
    // -----------------------------------------------------------------

    /**
     * Register a field group. Idempotent for identical same-source calls.
     *
     * @param  array{sort_order?: int, label?: string, help?: string}  $attributes
     */
    public function registerGroup(string $key, array $attributes = [], string $source = 'core'): void
    {
        $key = $this->normalizeKey($key);
        $this->assertGroupKeyShape($key);
        $this->assertNotReserved($key, $source);

        $group = [
            'key' => $key,
            'source' => $source,
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'label' => isset($attributes['label']) ? (string) $attributes['label'] : null,
            'help' => isset($attributes['help']) ? (string) $attributes['help'] : null,
        ];

        if (isset($this->groups[$key])) {
            $this->assertNoGroupCollision($key, $group);

            return; // identical same-source re-registration → idempotent
        }

        $this->groups[$key] = $group;
    }

    /**
     * Register a field definition. $key MUST be namespaced ("<ns>.<name>").
     * Idempotent for identical same-source calls; any other re-registration is
     * a collision.
     *
     * @param  array<string, mixed>  $definition
     */
    public function registerField(string $key, array $definition, string $source = 'core'): void
    {
        $key = $this->normalizeKey($key);

        // Reserved names are checked before format so a bare "email"/"password"
        // reports a reserved-key error rather than a generic shape error.
        $this->assertNotReserved($key, $source);
        $this->assertFieldKeyShape($key);

        $def = $this->buildDefinition($key, $definition, $source);

        if (isset($this->fields[$key])) {
            $this->assertNoFieldCollision($key, $def);

            return; // identical same-source re-registration → idempotent
        }

        $this->fields[$key] = $def;
    }

    // -----------------------------------------------------------------
    // Read (in-memory)
    // -----------------------------------------------------------------

    public function has(string $key): bool
    {
        return isset($this->fields[$this->normalizeKey($key)]);
    }

    public function hasGroup(string $key): bool
    {
        return isset($this->groups[$this->normalizeKey($key)]);
    }

    public function get(string $key): ?ProfileFieldDefinition
    {
        return $this->fields[$this->normalizeKey($key)] ?? null;
    }

    /** @return array<string, array{key: string, source: string, sort_order: int, label: ?string, help: ?string}> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return array<int, ProfileFieldDefinition> */
    public function all(): array
    {
        return array_values($this->fields);
    }

    /** @return array<int, ProfileFieldDefinition> */
    public function inGroup(string $groupKey): array
    {
        $groupKey = $this->normalizeKey($groupKey);

        $matched = array_values(array_filter(
            $this->fields,
            fn (ProfileFieldDefinition $d): bool => $d->group === $groupKey,
        ));

        usort($matched, static function (ProfileFieldDefinition $a, ProfileFieldDefinition $b): int {
            return [$a->sortOrder, $a->key] <=> [$b->sortOrder, $b->key];
        });

        return $matched;
    }

    // -----------------------------------------------------------------
    // Persistence (explicit, schema-guarded, non-destructive)
    // -----------------------------------------------------------------

    /**
     * Mirror the in-memory registry into the cms_user_profile_* tables.
     * Idempotent (updateOrCreate by key); never deletes rows for definitions
     * that are not currently registered (e.g. a disabled plugin's fields —
     * their persisted values must survive). Fails closed when the schema is
     * absent (fresh install / mid-migration): it simply returns.
     */
    public function reconcile(): void
    {
        if (! Schema::hasTable('cms_user_profile_field_groups')
            || ! Schema::hasTable('cms_user_profile_fields')) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->groups as $group) {
                $row = UserProfileFieldGroup::query()->updateOrCreate(
                    ['key' => $group['key']],
                    [
                        'source' => $group['source'],
                        'sort_order' => $group['sort_order'],
                        'is_active' => true,
                        'is_locked' => $this->isLocked($group['source']),
                    ],
                );

                $this->persistTranslation('group', (int) $row->getKey(), $group['label'], $group['help']);
            }

            foreach ($this->fields as $def) {
                $groupId = UserProfileFieldGroup::query()->where('key', $def->group)->value('id');

                if ($groupId === null) {
                    continue; // group not persisted (should not happen): skip, never guess
                }

                $row = UserProfileField::query()->updateOrCreate(
                    ['key' => $def->key],
                    [
                        'group_id' => $groupId,
                        'type' => $def->type,
                        'options' => $def->options,
                        'validation' => $def->validation,
                        'default_value' => $def->default,
                        'visibility' => $def->visibility,
                        'editability' => $def->editability,
                        'is_encrypted' => $def->isEncrypted,
                        'source' => $def->source,
                        'sort_order' => $def->sortOrder,
                        'is_active' => true,
                        'is_locked' => $this->isLocked($def->source),
                        'schema_revision' => 1,
                    ],
                );

                $this->persistTranslation('field', (int) $row->getKey(), $def->label, $def->help);
            }
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function buildDefinition(string $key, array $definition, string $source): ProfileFieldDefinition
    {
        $group = isset($definition['group']) ? $this->normalizeKey((string) $definition['group']) : '';

        if ($group === '' || ! $this->hasGroup($group)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" references unknown group "%s".', $key, $group),
            );
        }

        $type = (string) ($definition['type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" has unknown type "%s".', $key, $type),
            );
        }

        $visibility = (string) ($definition['visibility'] ?? 'owner');

        if (! in_array($visibility, self::VISIBILITIES, true)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" has invalid visibility "%s".', $key, $visibility),
            );
        }

        $editability = (string) ($definition['editability'] ?? 'owner');

        if (! in_array($editability, self::EDITABILITIES, true)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" has invalid editability "%s".', $key, $editability),
            );
        }

        $options = $this->safeArray($key, 'options', $definition['options'] ?? []);
        $validation = $this->safeArray($key, 'validation', $definition['validation'] ?? []);
        $this->assertNoPhp($key, $definition['default'] ?? null);

        return new ProfileFieldDefinition(
            key: $key,
            group: $group,
            type: $type,
            visibility: $visibility,
            editability: $editability,
            isEncrypted: (bool) ($definition['is_encrypted'] ?? false),
            source: $source,
            sortOrder: (int) ($definition['sort_order'] ?? 0),
            options: $options,
            validation: $validation,
            default: $definition['default'] ?? null,
            label: isset($definition['label']) ? (string) $definition['label'] : null,
            help: isset($definition['help']) ? (string) $definition['help'] : null,
        );
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private function firstSegment(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    private function assertGroupKeyShape(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile group key "%s" must be a single snake_case segment.', $key),
            );
        }
    }

    private function assertFieldKeyShape(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field key "%s" must be namespaced snake_case ("<namespace>.<name>").', $key),
            );
        }
    }

    private function assertNotReserved(string $key, string $source): void
    {
        $first = $this->firstSegment($key);

        if ($first === self::RESERVED_NAMESPACE && $source !== 'core') {
            throw ReservedProfileFieldKeyException::for($key, 'the "core" namespace is reserved for Core.');
        }

        if (in_array($key, self::RESERVED_KEYS, true) || in_array($first, self::RESERVED_KEYS, true)) {
            throw ReservedProfileFieldKeyException::for($key, 'it shadows an auth identifier or users-table column.');
        }
    }

    /** @return array<string, mixed> */
    private function safeArray(string $key, string $field, mixed $value): array
    {
        if ($value === [] || $value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" %s must be an array.', $key, $field),
            );
        }

        $this->assertNoPhp($key, $value);

        return $value;
    }

    /** Reject closures/objects anywhere in metadata — no arbitrary PHP may be persisted. */
    private function assertNoPhp(string $key, mixed $value): void
    {
        if ($value instanceof Closure || is_object($value)) {
            throw InvalidProfileFieldDefinitionException::because(
                sprintf('Profile field "%s" metadata must not contain PHP callbacks or objects.', $key),
            );
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoPhp($key, $item);
            }
        }
    }

    private function assertNoFieldCollision(string $key, ProfileFieldDefinition $incoming): void
    {
        $existing = $this->fields[$key];

        if ($existing->source !== $incoming->source) {
            throw ProfileFieldCollisionException::crossSource($key, $existing->source, $incoming->source);
        }

        if ($existing->signature() !== $incoming->signature()) {
            throw ProfileFieldCollisionException::incompatible($key, $existing->source);
        }
    }

    /** @param array{key: string, source: string, sort_order: int, label: ?string, help: ?string} $incoming */
    private function assertNoGroupCollision(string $key, array $incoming): void
    {
        $existing = $this->groups[$key];

        if ($existing['source'] !== $incoming['source']) {
            throw ProfileFieldCollisionException::crossSource($key, $existing['source'], $incoming['source']);
        }

        if ($existing !== $incoming) {
            throw ProfileFieldCollisionException::incompatible($key, $existing['source']);
        }
    }

    private function isLocked(string $source): bool
    {
        return $source !== 'admin';
    }

    private function persistTranslation(string $ownerType, int $ownerId, ?string $label, ?string $help): void
    {
        if (($label === null && $help === null) || ! Schema::hasTable('cms_user_profile_field_translations')) {
            return;
        }

        UserProfileFieldTranslation::query()->updateOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'locale' => $this->defaultLocale()],
            ['label' => $label, 'help' => $help],
        );
    }

    private function defaultLocale(): string
    {
        if (function_exists('default_locale')) {
            return default_locale();
        }

        return (string) config('app.locale', 'en');
    }
}
