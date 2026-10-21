<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Exceptions\UnknownProfileFieldException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileValue;

/**
 * The single privacy-aware authority for reading and writing user profile
 * values (Account Profile Field Engine, P1-B — binding `cms.user_profile`).
 *
 * Every read and write passes through the per-field visibility/editability
 * gate here — there is no raw path. Definitions are resolved from the persisted
 * tables (so admin-created fields work in P2 too); a write to a field that is
 * not persisted and active fails closed. Field registration is never an
 * authorization grant: a plugin that registered a field still cannot read
 * another user's private value unless the acting user holds the permission.
 *
 * Permissions (registered via PermissionManager, group "Users"):
 *  - profile_fields.view_private : read owner/admin-visibility values of OTHERS
 *  - profile_fields.edit_others  : write owner/admin-editable values of OTHERS
 *  - profile_fields.manage       : manage definitions (Field Builder, P2)
 */
class UserProfileManager
{
    public const PERM_VIEW_PRIVATE = 'profile_fields.view_private';

    public const PERM_EDIT_OTHERS = 'profile_fields.edit_others';

    public const PERM_MANAGE = 'profile_fields.manage';

    public function __construct(private readonly ProfileValueCaster $caster) {}

    // -----------------------------------------------------------------
    // Reads (authorization-filtered — denial is indistinguishable from unset)
    // -----------------------------------------------------------------

    public function get(Model $target, string $key, ?Authenticatable $actor, ?string $locale = null): mixed
    {
        $field = $this->activeField($key);

        if ($field === null) {
            return null; // unknown/inactive — never an oracle, never auto-created
        }

        $def = ProfileFieldDefinition::fromModel($field);

        if (! $this->canRead($def, $actor, $target)) {
            return null;
        }

        $row = $this->valueRow((int) $target->getKey(), (int) $field->getKey(), $this->locale($locale));

        return $row === null ? $def->default : $this->decode($row, $def);
    }

    /**
     * All values the actor may read on the target, keyed by field key. One
     * query across the readable fields (no N+1).
     *
     * @return array<string, mixed>
     */
    public function values(Model $target, ?Authenticatable $actor, ?string $locale = null): array
    {
        return $this->valuesForMany([$target], $actor, $locale)[(int) $target->getKey()] ?? [];
    }

    /**
     * Batched, authorization-filtered values for many targets. Two queries total
     * (fields + values), grouped in memory — safe for author listings.
     *
     * @param  iterable<int, Model>  $targets
     * @return array<int, array<string, mixed>>
     */
    public function valuesForMany(iterable $targets, ?Authenticatable $actor, ?string $locale = null): array
    {
        $targets = is_array($targets) ? $targets : iterator_to_array($targets);
        $fields = $this->activeFields();
        $loc = $this->locale($locale);

        $ids = array_map(static fn (Model $t): int => (int) $t->getKey(), $targets);

        $rows = $ids === [] || $fields->isEmpty()
            ? collect()
            : UserProfileValue::query()
                ->whereIn('user_id', $ids)
                ->whereIn('field_id', $fields->modelKeys())
                ->whereIn('locale', array_unique([$loc, '']))
                ->get();

        $out = [];

        foreach ($targets as $target) {
            $tid = (int) $target->getKey();
            $map = [];

            foreach ($fields as $field) {
                $def = ProfileFieldDefinition::fromModel($field);

                if (! $this->canRead($def, $actor, $target)) {
                    continue;
                }

                $row = $this->pickRow($rows, $tid, (int) $field->getKey(), $loc);
                $map[$field->key] = $row === null ? $def->default : $this->decode($row, $def);
            }

            $out[$tid] = $map;
        }

        return $out;
    }

    /** Field keys the actor may read on the target. @return array<int, string> */
    public function readableFields(Model $target, ?Authenticatable $actor): array
    {
        return $this->activeFields()
            ->filter(fn (UserProfileField $f): bool => $this->canRead(ProfileFieldDefinition::fromModel($f), $actor, $target))
            ->map(fn (UserProfileField $f): string => $f->key)
            ->values()->all();
    }

    /** Field keys the actor may edit on the target. @return array<int, string> */
    public function editableFields(Model $target, ?Authenticatable $actor): array
    {
        return $this->activeFields()
            ->filter(fn (UserProfileField $f): bool => $this->canEdit(ProfileFieldDefinition::fromModel($f), $actor, $target))
            ->map(fn (UserProfileField $f): string => $f->key)
            ->values()->all();
    }

    // -----------------------------------------------------------------
    // Writes (authorize + validate EVERYTHING before persisting anything)
    // -----------------------------------------------------------------

    /**
     * Dry-run: authorize + normalize + validate without persisting.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> normalized values
     */
    public function validate(Model $target, array $input, ?Authenticatable $actor, ?string $locale = null): array
    {
        return array_map(static fn (array $p): mixed => $p['value'], $this->plan($target, $input, $actor));
    }

    /**
     * Write a map of key => value atomically. Rejects unknown/forged keys and
     * unauthorized edits BEFORE any mutation (no partial application). An empty
     * value clears the field. Returns the actor-readable values afterwards.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function set(Model $target, array $input, ?Authenticatable $actor, ?string $locale = null): array
    {
        $plan = $this->plan($target, $input, $actor);
        $loc = $this->locale($locale);
        $actorId = $actor !== null ? (int) $actor->getAuthIdentifier() : null;

        DB::transaction(function () use ($plan, $target, $loc, $actorId): void {
            foreach ($plan as $key => $p) {
                /** @var UserProfileField $field */
                $field = $p['field'];
                /** @var ProfileFieldDefinition $def */
                $def = $p['def'];
                $value = apply_filters('cms.profile.value_data', $p['value'], $target, $def);

                do_action('cms.profile.value.updating', $target, $def, $value);

                if ($value === null) {
                    $this->deleteRow((int) $target->getKey(), (int) $field->getKey(), $loc);
                } else {
                    $this->persist($target, $field, $def, $value, $loc, $actorId);
                }

                do_action('cms.profile.value.updated', $target, $def, $value);
            }
        });

        return $this->values($target, $actor, $loc);
    }

    /** Clear a single field (authorization-checked). */
    public function forget(Model $target, string $key, ?Authenticatable $actor, ?string $locale = null): void
    {
        $field = $this->activeField($key);

        if ($field === null) {
            throw UnknownProfileFieldException::for($key);
        }

        if (! $this->canEdit(ProfileFieldDefinition::fromModel($field), $actor, $target)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Not allowed to edit \"{$key}\".");
        }

        $this->deleteRow((int) $target->getKey(), (int) $field->getKey(), $this->locale($locale));
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    private function canRead(ProfileFieldDefinition $def, ?Authenticatable $actor, Model $target): bool
    {
        return match ($def->visibility) {
            'public' => true,
            'authenticated' => $actor !== null,
            'owner' => $this->isOwner($actor, $target) || $this->isAdminReader($actor),
            'admin' => $this->isAdminReader($actor),
            default => false, // fail closed on an unexpected visibility
        };
    }

    private function canEdit(ProfileFieldDefinition $def, ?Authenticatable $actor, Model $target): bool
    {
        return match ($def->editability) {
            'owner' => $this->isOwner($actor, $target) || $this->canEditOthers($actor),
            'admin' => $this->canEditOthers($actor),
            // 'system' and 'immutable' are never writable through the public API.
            default => false,
        };
    }

    private function isOwner(?Authenticatable $actor, Model $target): bool
    {
        return $actor !== null && (int) $actor->getAuthIdentifier() === (int) $target->getKey();
    }

    private function isAdminReader(?Authenticatable $actor): bool
    {
        return $actor !== null && cms_can(self::PERM_VIEW_PRIVATE, $actor);
    }

    private function canEditOthers(?Authenticatable $actor): bool
    {
        return $actor !== null && cms_can(self::PERM_EDIT_OTHERS, $actor);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Resolve, authorize and validate the whole write before persistence.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{field: UserProfileField, def: ProfileFieldDefinition, value: mixed}>
     */
    private function plan(Model $target, array $input, ?Authenticatable $actor): array
    {
        $plan = [];

        foreach ($input as $key => $raw) {
            $field = $this->activeField((string) $key);

            if ($field === null) {
                throw UnknownProfileFieldException::for((string) $key); // forged/unknown → fail closed
            }

            $def = ProfileFieldDefinition::fromModel($field);

            if (! $this->canEdit($def, $actor, $target)) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Not allowed to edit \"{$field->key}\".");
            }

            $plan[$field->key] = [
                'field' => $field,
                'def' => $def,
                'value' => $this->caster->cast($def, $raw), // ValidationException on invalid
            ];
        }

        return $plan;
    }

    private function persist(Model $target, UserProfileField $field, ProfileFieldDefinition $def, mixed $value, string $loc, ?int $actorId): void
    {
        $store = $value;
        $index = $this->caster->indexValue($def, $value);

        if ($def->isEncrypted) {
            $store = ['enc' => Crypt::encryptString((string) json_encode($value))];
            $index = null; // encrypted values are never indexed/searchable
        }

        UserProfileValue::query()->updateOrCreate(
            ['user_id' => (int) $target->getKey(), 'field_id' => (int) $field->getKey(), 'locale' => $loc],
            ['field_key' => $field->key, 'value' => $store, 'value_index' => $index, 'updated_by' => $actorId],
        );
    }

    private function decode(UserProfileValue $row, ProfileFieldDefinition $def): mixed
    {
        $value = $row->value;

        if (! $def->isEncrypted) {
            return $value;
        }

        $cipher = is_array($value) ? ($value['enc'] ?? null) : null;

        if (! is_string($cipher)) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString($cipher), true);
        } catch (DecryptException $e) {
            // Fail closed: never surface ciphertext or raise through a read path.
            Log::warning('Profile value decrypt failed', ['field' => $def->key, 'exception' => $e::class]);

            return null;
        }
    }

    private function activeField(string $key): ?UserProfileField
    {
        $key = strtolower(trim($key));

        return UserProfileField::query()->where('key', $key)->where('is_active', true)->first();
    }

    /** @return Collection<int, UserProfileField> */
    private function activeFields(): Collection
    {
        return UserProfileField::query()->where('is_active', true)->orderBy('sort_order')->orderBy('key')->get();
    }

    private function valueRow(int $userId, int $fieldId, string $loc): ?UserProfileValue
    {
        $row = UserProfileValue::query()
            ->where('user_id', $userId)->where('field_id', $fieldId)->where('locale', $loc)->first();

        if ($row === null && $loc !== '') {
            $row = UserProfileValue::query()
                ->where('user_id', $userId)->where('field_id', $fieldId)->where('locale', '')->first();
        }

        return $row;
    }

    /** @param Collection<int, UserProfileValue> $rows */
    private function pickRow(Collection $rows, int $userId, int $fieldId, string $loc): ?UserProfileValue
    {
        $candidates = $rows->where('user_id', $userId)->where('field_id', $fieldId);

        return $candidates->firstWhere('locale', $loc) ?? ($loc !== '' ? $candidates->firstWhere('locale', '') : null);
    }

    private function deleteRow(int $userId, int $fieldId, string $loc): void
    {
        UserProfileValue::query()
            ->where('user_id', $userId)->where('field_id', $fieldId)->where('locale', $loc)->delete();
    }

    private function locale(?string $locale): string
    {
        return $locale ?? '';
    }
}
