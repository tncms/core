<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\ProfileFieldFrozenException;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages\EditProfileFieldGroup;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages\EditProfileField;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileValue;

/**
 * P2-R1 browser-UX closure (F1/F2/F3). The backend guards are proven in
 * ProfileFieldAdminServiceTest; here we assert the Filament UI now REFLECTS them:
 *  - F1: the header DeleteAction is hidden for definitions that are not
 *        administratively deletable, and the server still fails closed.
 *  - F2: the destructive-confirmation modal shows the real impacted-value count
 *        (no literal {count}/:count placeholder), singular/plural correct.
 *  - F3: properties the backend forbids changing render disabled; permitted
 *        edits stay enabled.
 *
 * Roles are intentionally NOT seeded: Core RBAC then fails open so an
 * authenticated user holds profile_fields.manage (matches the existing
 * ProfileFieldBuilderResourceTest baseline), isolating these assertions to the
 * source/has-values predicates under test.
 */
class ProfileFieldBuilderUxR1Test extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function group(string $key, string $source): UserProfileFieldGroup
    {
        return UserProfileFieldGroup::query()->create([
            'key' => $key,
            'source' => $source,
            'is_active' => true,
            'is_locked' => $source !== 'admin',
        ]);
    }

    private function field(UserProfileFieldGroup $group, string $key, string $source): UserProfileField
    {
        return UserProfileField::query()->create([
            'key' => $key,
            'group_id' => $group->id,
            'type' => 'text',
            'visibility' => 'public',
            'editability' => 'owner',
            'is_encrypted' => false,
            'source' => $source,
            'sort_order' => 0,
            'is_active' => true,
            'is_locked' => $source !== 'admin',
            'schema_revision' => 1,
        ]);
    }

    private function addValue(UserProfileField $field, User $user, string $locale = ''): void
    {
        UserProfileValue::query()->create([
            'user_id' => $user->id,
            'field_id' => $field->id,
            'field_key' => $field->key,
            'locale' => $locale,
            'value' => ['v' => 'x'],
        ]);
    }

    // ---------------- F1: locked-field delete UX + fail-closed ----------------

    public function test_delete_action_is_hidden_for_a_locked_field(): void
    {
        $this->manager();
        $field = $this->field($this->group('employment', 'plugin:directory'), 'directory.department', 'plugin:directory');

        Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_delete_action_is_visible_for_an_admin_field(): void
    {
        $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');

        Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->assertActionVisible('delete');
    }

    public function test_locked_field_delete_fails_closed_server_side(): void
    {
        $admin = $this->manager();
        $field = $this->field($this->group('employment', 'plugin:directory'), 'directory.department', 'plugin:directory');

        $this->assertFalse(ProfileFieldResource::canDelete($field));

        try {
            app('cms.user_profile_fields.admin')->deleteField($field, $admin);
            $this->fail('A locked field must not be deletable.');
        } catch (ProfileFieldFrozenException) {
            // expected
        }

        $this->assertNotNull($field->fresh(), 'The locked field must survive.');
    }

    public function test_group_delete_hidden_for_locked_group(): void
    {
        $this->manager();
        $group = $this->group('employment', 'plugin:directory');

        Livewire::test(EditProfileFieldGroup::class, ['record' => $group->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_group_delete_hidden_when_group_still_has_fields(): void
    {
        $this->manager();
        $group = $this->group('social', 'admin');
        $this->field($group, 'custom.headline', 'admin');

        Livewire::test(EditProfileFieldGroup::class, ['record' => $group->getKey()])
            ->assertActionHidden('delete');
    }

    public function test_group_delete_visible_for_empty_admin_group(): void
    {
        $this->manager();
        $group = $this->group('empty_group', 'admin');

        Livewire::test(EditProfileFieldGroup::class, ['record' => $group->getKey()])
            ->assertActionVisible('delete');
    }

    // ---------------- F2: real impacted-value count in the modal ----------------

    /** Evaluate the mounted delete action's modal description for a field. */
    private function deleteModalDescription(UserProfileField $field): string
    {
        $action = Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->mountAction('delete')
            ->instance()
            ->getMountedAction();

        return (string) $action->getModalDescription();
    }

    public function test_delete_modal_shows_plural_count_not_placeholder(): void
    {
        $admin = $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');
        $this->addValue($field, $admin);
        $this->addValue($field, User::factory()->create());

        $description = $this->deleteModalDescription($field);

        $this->assertStringContainsString('2 stored values', $description);
        $this->assertStringNotContainsString('{count}', $description);
        $this->assertStringNotContainsString(':count', $description);
    }

    public function test_delete_modal_shows_singular_count_for_one_value(): void
    {
        $admin = $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');
        $this->addValue($field, $admin);

        $description = $this->deleteModalDescription($field);

        $this->assertStringContainsString('1 stored value.', $description);
        $this->assertStringNotContainsString('{count}', $description);
    }

    public function test_delete_modal_counts_multiple_locales_for_one_user(): void
    {
        $admin = $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');
        $this->addValue($field, $admin, '');
        $this->addValue($field, $admin, 'vi');

        $this->assertSame(2, app('cms.user_profile_fields.admin')->impactedValueCount($field));
        $this->assertStringContainsString('2 stored values', $this->deleteModalDescription($field));
    }

    public function test_delete_modal_shows_zero_when_no_values(): void
    {
        $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');

        $description = $this->deleteModalDescription($field);

        $this->assertStringContainsString('0 stored values', $description);
        $this->assertStringNotContainsString('{count}', $description);
    }

    // ---------------- F3: immutability reflected in the form ----------------

    public function test_locked_field_disables_owner_governed_properties(): void
    {
        $this->manager();
        $field = $this->field($this->group('employment', 'plugin:directory'), 'directory.department', 'plugin:directory');

        Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->assertFormFieldIsDisabled('key')
            ->assertFormFieldIsDisabled('group_id')
            ->assertFormFieldIsDisabled('type')
            ->assertFormFieldIsDisabled('visibility')
            ->assertFormFieldIsDisabled('editability')
            ->assertFormFieldIsDisabled('is_encrypted')
            ->assertFormFieldIsDisabled('label')
            ->assertFormFieldIsEnabled('is_active')
            ->assertFormFieldIsEnabled('sort_order');
    }

    public function test_admin_field_with_values_freezes_data_shape_only(): void
    {
        $admin = $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');
        $this->addValue($field, $admin);

        Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->assertFormFieldIsDisabled('type')
            ->assertFormFieldIsDisabled('is_encrypted')
            ->assertFormFieldIsDisabled('group_id')
            ->assertFormFieldIsEnabled('visibility')
            ->assertFormFieldIsEnabled('editability')
            ->assertFormFieldIsEnabled('is_active')
            ->assertFormFieldIsEnabled('label');
    }

    public function test_admin_field_without_values_allows_shape_edits(): void
    {
        $this->manager();
        $field = $this->field($this->group('social', 'admin'), 'custom.headline', 'admin');

        Livewire::test(EditProfileField::class, ['record' => $field->getKey()])
            ->assertFormFieldIsDisabled('key')
            ->assertFormFieldIsDisabled('group_id')
            ->assertFormFieldIsEnabled('type')
            ->assertFormFieldIsEnabled('is_encrypted')
            ->assertFormFieldIsEnabled('visibility');
    }
}
