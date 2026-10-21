<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ProfileFieldCollisionException;
use TheNguyen\CMS\Exceptions\ProfileFieldFrozenException;
use TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;
use TheNguyen\CMS\Models\UserProfileValue;
use TheNguyen\CMS\Profile\ProfileFieldAdminService;
use TheNguyen\CMS\Profile\UserProfileManager;

/**
 * P2 Slice B — ProfileFieldAdminService, the audited admin write authority.
 * This is the server-side security boundary for the Field Builder; it holds
 * even if the Filament UI is bypassed.
 */
class ProfileFieldAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ProfileFieldAdminService
    {
        return app('cms.user_profile_fields.admin');
    }

    public function test_unauthorized_actor_cannot_create_a_group(): void
    {
        $this->member(); // RBAC initialized → fail-open off
        $this->expectException(AuthorizationException::class);
        $this->svc()->createGroup(['key' => 'custom_group'], $this->member());
    }

    public function test_guest_cannot_write(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->svc()->createField(['key' => 'custom.x', 'group' => 'g', 'type' => 'text'], null);
    }

    public function test_admin_creates_group_and_field_with_translations(): void
    {
        // Make the default locale deterministic (fresh DB falls back to 'vi'),
        // so the default-locale and the explicit VI rows are distinct.
        \TheNguyen\CMS\Models\Language::query()->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        \TheNguyen\CMS\Models\Language::query()->create(['code' => 'vi', 'name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true]);
        $this->assertSame('en', default_locale());

        $admin = $this->admin();
        $group = $this->svc()->createGroup(['key' => 'Professional', 'label' => 'Professional', 'label_vi' => 'Nghề nghiệp'], $admin);

        $this->assertSame('professional', $group->key);
        $this->assertSame('admin', $group->source);
        $this->assertFalse((bool) $group->is_locked);

        $field = $this->svc()->createField([
            'key' => 'custom.job_title', 'group' => 'professional', 'type' => 'text',
            'visibility' => 'public', 'editability' => 'owner',
            'label' => 'Job title', 'label_vi' => 'Chức danh',
        ], $admin);

        $this->assertSame('custom.job_title', $field->key);
        $this->assertSame('admin', $field->source);
        $this->assertFalse((bool) $field->is_locked);
        $this->assertSame('public', $field->visibility);

        $this->assertSame('Job title', UserProfileFieldTranslation::query()
            ->where('owner_type', 'field')->where('owner_id', $field->id)->where('locale', default_locale())->value('label'));
        $this->assertSame('Chức danh', UserProfileFieldTranslation::query()
            ->where('owner_type', 'field')->where('owner_id', $field->id)->where('locale', 'vi')->value('label'));
    }

    public function test_admin_field_must_use_reserved_custom_namespace(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $this->expectException(ReservedProfileFieldKeyException::class);
        $this->svc()->createField(['key' => 'acme.thing', 'group' => 'misc', 'type' => 'text'], $admin);
    }

    public function test_cannot_hijack_core_namespace(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $this->expectException(ReservedProfileFieldKeyException::class);
        $this->svc()->createField(['key' => 'core.secret', 'group' => 'misc', 'type' => 'text'], $admin);
    }

    public function test_duplicate_field_key_is_a_collision(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $this->svc()->createField(['key' => 'custom.dup', 'group' => 'misc', 'type' => 'text'], $admin);
        $this->expectException(ProfileFieldCollisionException::class);
        $this->svc()->createField(['key' => 'custom.dup', 'group' => 'misc', 'type' => 'text'], $admin);
    }

    public function test_unknown_type_and_group_are_rejected(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);

        try {
            $this->svc()->createField(['key' => 'custom.a', 'group' => 'misc', 'type' => 'quantum'], $admin);
            $this->fail('expected unknown type rejection');
        } catch (InvalidProfileFieldDefinitionException) {
        }

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->svc()->createField(['key' => 'custom.b', 'group' => 'nope', 'type' => 'text'], $admin);
    }

    public function test_arbitrary_php_in_options_is_rejected(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->svc()->createField([
            'key' => 'custom.evil', 'group' => 'misc', 'type' => 'select',
            'options' => ['choices' => [fn () => 'x']],
        ], $admin);
    }

    public function test_locked_core_field_cannot_be_retyped_or_deleted(): void
    {
        $admin = $this->admin();
        $group = UserProfileFieldGroup::query()->create(['key' => 'coreg', 'source' => 'core', 'is_locked' => true]);
        $field = UserProfileField::query()->create(['key' => 'core.badge', 'group_id' => $group->id, 'type' => 'text', 'source' => 'core', 'is_locked' => true]);

        // type change is ignored; only sort/active apply.
        $this->svc()->updateField($field, ['type' => 'number', 'sort_order' => 5], $admin);
        $this->assertSame('text', $field->fresh()->type);
        $this->assertSame(5, (int) $field->fresh()->sort_order);

        $this->expectException(ProfileFieldFrozenException::class);
        $this->svc()->deleteField($field->fresh(), $admin);
    }

    public function test_data_shape_freezes_once_values_exist(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $field = $this->svc()->createField(['key' => 'custom.frozen', 'group' => 'misc', 'type' => 'text'], $admin);

        $user = $this->member();
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => 'x']);

        // visibility change (not data-shape) is allowed even with values.
        $this->svc()->updateField($field->fresh(), ['visibility' => 'public'], $admin);
        $this->assertSame('public', $field->fresh()->visibility);

        // type change (data-shape) is frozen.
        $this->expectException(ProfileFieldFrozenException::class);
        $this->svc()->updateField($field->fresh(), ['type' => 'number'], $admin);
    }

    public function test_delete_admin_field_cascades_values_and_translations(): void
    {
        $admin = $this->admin();
        $this->svc()->createGroup(['key' => 'misc'], $admin);
        $field = $this->svc()->createField(['key' => 'custom.gone', 'group' => 'misc', 'type' => 'text', 'label' => 'Gone'], $admin);
        $user = $this->member();
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => 'x']);

        $this->assertSame(1, $this->svc()->impactedValueCount($field));
        $this->svc()->deleteField($field, $admin);

        $this->assertNull(UserProfileField::query()->find($field->id));
        $this->assertSame(0, UserProfileValue::query()->where('field_id', $field->id)->count());
        $this->assertSame(0, UserProfileFieldTranslation::query()->where('owner_type', 'field')->where('owner_id', $field->id)->count());
    }

    public function test_group_with_fields_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $group = $this->svc()->createGroup(['key' => 'misc'], $admin);
        $this->svc()->createField(['key' => 'custom.keep', 'group' => 'misc', 'type' => 'text'], $admin);

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->svc()->deleteGroup($group->fresh(), $admin);
    }

    public function test_empty_admin_group_deletes(): void
    {
        $admin = $this->admin();
        $group = $this->svc()->createGroup(['key' => 'temp', 'label' => 'Temp'], $admin);
        $this->svc()->deleteGroup($group, $admin);
        $this->assertNull(UserProfileFieldGroup::query()->find($group->id));
    }

    // ---- helpers ----

    private function user(): User
    {
        return User::query()->forceCreate(['name' => 'U', 'email' => 'u-'.uniqid().'@example.test', 'password' => bcrypt('secret-password')]);
    }

    private function member(): User
    {
        $u = $this->user();
        $u->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['slug' => 'member'], ['name' => 'Member'])->id]);

        return $u;
    }

    private function admin(): User
    {
        app('cms.permission')->syncDefaults();
        $this->member();
        $u = $this->user();
        $role = Role::query()->firstOrCreate(['slug' => 'pf-admin'], ['name' => 'PF Admin']);
        $role->permissions()->syncWithoutDetaching(Permission::query()->where('slug', UserProfileManager::PERM_MANAGE)->pluck('id')->all());
        $u->roles()->syncWithoutDetaching([$role->id]);

        return $u;
    }
}
