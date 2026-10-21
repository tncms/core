<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileValue;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;

/**
 * P1-A — Account Profile Field Engine database foundation.
 *
 * Verifies the four cms_user_profile_* tables, their indexes, unique keys,
 * the locale-neutral sentinel (CLARIFICATION-002: NOT NULL default ''), the
 * Core-table FK cascade on field deletion, the host-users ownership convention
 * (no FK to users, CLARIFICATION-003), that the users table is untouched, and
 * that user deletion cleans profile values without vetoing.
 */
class UserProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('cms_user_profile_field_groups'));
        $this->assertTrue(Schema::hasTable('cms_user_profile_fields'));
        $this->assertTrue(Schema::hasTable('cms_user_profile_values'));
        $this->assertTrue(Schema::hasTable('cms_user_profile_field_translations'));
    }

    public function test_core_tables_carry_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('cms_user_profile_field_groups', [
            'id', 'key', 'source', 'sort_order', 'is_active', 'is_locked', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('cms_user_profile_fields', [
            'id', 'key', 'group_id', 'type', 'options', 'validation', 'default_value',
            'visibility', 'editability', 'is_encrypted', 'source', 'sort_order',
            'is_active', 'is_locked', 'schema_revision', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('cms_user_profile_values', [
            'id', 'user_id', 'field_id', 'field_key', 'locale', 'value', 'value_index',
            'updated_by', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('cms_user_profile_field_translations', [
            'id', 'owner_type', 'owner_id', 'locale', 'label', 'help', 'options_labels',
        ]));
    }

    public function test_group_key_is_unique(): void
    {
        UserProfileFieldGroup::query()->create(['key' => 'social', 'source' => 'core']);
        $this->expectException(QueryException::class);
        UserProfileFieldGroup::query()->create(['key' => 'social', 'source' => 'core']);
    }

    public function test_field_key_is_unique(): void
    {
        $group = UserProfileFieldGroup::query()->create(['key' => 'social', 'source' => 'core']);
        UserProfileField::query()->create(['key' => 'social.linkedin', 'group_id' => $group->id, 'type' => 'url', 'source' => 'core']);
        $this->expectException(QueryException::class);
        UserProfileField::query()->create(['key' => 'social.linkedin', 'group_id' => $group->id, 'type' => 'url', 'source' => 'core']);
    }

    public function test_value_locale_neutral_sentinel_defaults_to_empty_string_and_is_unique(): void
    {
        [$user, $field] = $this->seedUserAndField();

        $row = UserProfileValue::query()->create([
            'user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 1],
        ]);

        // CLARIFICATION-002: locale stored as '' (neutral), never NULL.
        $this->assertSame('', $row->fresh()->locale);

        // Duplicate (user, field, '') must violate the unique key — proving NULLs
        // cannot slip past uniqueness via distinct-NULL semantics.
        $this->expectException(QueryException::class);
        UserProfileValue::query()->create([
            'user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'locale' => '', 'value' => ['x' => 2],
        ]);
    }

    public function test_distinct_locales_coexist_for_same_user_and_field(): void
    {
        [$user, $field] = $this->seedUserAndField();

        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'locale' => 'en', 'value' => ['v' => 'en']]);
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'locale' => 'vi', 'value' => ['v' => 'vi']]);
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'locale' => '', 'value' => ['v' => 'neutral']]);

        $this->assertSame(3, UserProfileValue::query()->where('user_id', $user->id)->count());
    }

    public function test_deleting_a_field_cascades_to_its_values(): void
    {
        [$user, $field] = $this->seedUserAndField();
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 1]]);

        $this->assertSame(1, UserProfileValue::query()->count());
        $field->delete(); // Core-owned FK cascadeOnDelete
        $this->assertSame(0, UserProfileValue::query()->count());
    }

    public function test_users_table_is_unchanged_by_this_slice(): void
    {
        // No profile columns leaked onto users; identity schema intact.
        $this->assertFalse(Schema::hasColumn('users', 'profile_values'));
        $this->assertFalse(Schema::hasColumn('users', 'custom_fields'));
        $this->assertTrue(Schema::hasColumns('users', ['id', 'name', 'email', 'username', 'bio', 'avatar']));
    }

    public function test_user_deletion_through_manager_succeeds_and_is_not_vetoed(): void
    {
        // P1-A adds the tables but registers NO pre-delete handler and makes NO
        // change to UserDeletionManager: the deletion contract is unaffected. The
        // engine never writes a value in P1-A, so orphans cannot arise in
        // production; the value-cleanup handler lands in P1-B with the write path.
        [$user, $field] = $this->seedUserAndField();

        $result = app('cms.user.deletion')->delete($user, null, UserDeletionContext::SOURCE_UNKNOWN);

        $this->assertTrue($result->wasDeleted());
        $this->assertFalse($result->wasVetoed());
        $this->assertNull(User::query()->find($user->id));
    }

    public function test_user_id_foreign_key_cascades_on_host_delete(): void
    {
        // P1-B (CLARIFICATION-003-REVISED): this privacy-sensitive owned-values
        // table now carries a real FK to users with ON DELETE CASCADE. A raw host
        // delete removes the value row at the engine layer. Detailed FK behaviour
        // is in UserProfileValueForeignKeyTest.
        [$user, $field] = $this->seedUserAndField();
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 1]]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertSame(0, UserProfileValue::query()->where('user_id', $user->id)->count());
    }

    /** @return array{0: User, 1: UserProfileField} */
    private function seedUserAndField(): array
    {
        $user = User::query()->forceCreate([
            'name' => 'Case', 'email' => 'case-'.uniqid().'@example.test', 'password' => bcrypt('secret-password'),
        ]);
        $group = UserProfileFieldGroup::query()->create(['key' => 'professional', 'source' => 'core']);
        $field = UserProfileField::query()->create([
            'key' => 'professional.job_title', 'group_id' => $group->id, 'type' => 'text', 'source' => 'core',
        ]);

        return [$user, $field];
    }
}
