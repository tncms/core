<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Models\UserProfileValue;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * P1-B Gate 1 — the real foreign key cms_user_profile_values.user_id → users.id
 * with ON DELETE CASCADE (CLARIFICATION-003-REVISED).
 *
 * The host User model hard-deletes (no SoftDeletes), so the DB cascade is the
 * complete cleanup — no application pre-delete handler is registered, preserving
 * UserDeletionManager's lean-path / zero-default-handler invariants. field_id
 * keeps its Core-table cascade; the (user_id, field_id, locale) uniqueness is
 * unaffected.
 */
class UserProfileValueForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_key_constraint_is_enforced_by_the_engine(): void
    {
        // On SQLite the test harness runs with foreign_key_constraints = true; a
        // value for a non-existent user must be refused at the DB layer.
        $field = $this->field();

        $this->expectException(QueryException::class);
        UserProfileValue::query()->create([
            'user_id' => 999999, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 1],
        ]);
    }

    public function test_hard_deleting_a_user_cascades_their_profile_values(): void
    {
        [$user, $field] = $this->userAndValue();
        $this->assertSame(1, UserProfileValue::query()->where('user_id', $user->id)->count());

        DB::table('users')->where('id', $user->id)->delete(); // raw hard delete → engine cascade

        $this->assertSame(0, UserProfileValue::query()->where('user_id', $user->id)->count());
    }

    public function test_user_deletion_manager_cascades_values_and_does_not_veto(): void
    {
        [$user, $field] = $this->userAndValue();

        $result = app('cms.user.deletion')->delete($user, null, UserDeletionContext::SOURCE_UNKNOWN);

        $this->assertTrue($result->wasDeleted());
        $this->assertFalse($result->wasVetoed());
        $this->assertNull(User::query()->find($user->id));
        $this->assertSame(0, UserProfileValue::query()->where('user_id', $user->id)->count());
    }

    public function test_a_veto_rolls_back_and_preserves_both_user_and_values(): void
    {
        [$user, $field] = $this->userAndValue();

        app('cms.user.deletion')->registerPreDelete(
            fn (UserDeletionContext $c): UserDeletionVeto => UserDeletionVeto::make('test.block', 'Blocked for test.'),
        );

        $result = app('cms.user.deletion')->delete($user, null, UserDeletionContext::SOURCE_UNKNOWN);

        $this->assertTrue($result->wasVetoed());
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertSame(1, UserProfileValue::query()->where('user_id', $user->id)->count());
    }

    public function test_deleting_one_user_does_not_touch_another_users_values(): void
    {
        [$userA, $field] = $this->userAndValue();
        $userB = $this->user();
        UserProfileValue::query()->create(['user_id' => $userB->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 2]]);

        app('cms.user.deletion')->delete($userA, null, UserDeletionContext::SOURCE_UNKNOWN);

        $this->assertSame(0, UserProfileValue::query()->where('user_id', $userA->id)->count());
        $this->assertSame(1, UserProfileValue::query()->where('user_id', $userB->id)->count());
    }

    public function test_field_cascade_and_locale_uniqueness_still_hold(): void
    {
        [$user, $field] = $this->userAndValue();

        // uniqueness retained
        try {
            UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'locale' => '', 'value' => ['x' => 9]]);
            $this->fail('Expected a unique-constraint violation.');
        } catch (QueryException) {
            // expected
        }

        // field FK cascade retained
        $field->delete();
        $this->assertSame(0, UserProfileValue::query()->where('field_id', $field->id)->count());
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'FK', 'email' => 'fk-'.uniqid().'@example.test', 'password' => bcrypt('secret-password'),
        ]);
    }

    private function field(): UserProfileField
    {
        $group = UserProfileFieldGroup::query()->firstOrCreate(['key' => 'professional'], ['source' => 'core']);

        return UserProfileField::query()->firstOrCreate(
            ['key' => 'professional.job_title'],
            ['group_id' => $group->id, 'type' => 'text', 'source' => 'core'],
        );
    }

    /** @return array{0: User, 1: UserProfileField} */
    private function userAndValue(): array
    {
        $user = $this->user();
        $field = $this->field();
        UserProfileValue::query()->create(['user_id' => $user->id, 'field_id' => $field->id, 'field_key' => $field->key, 'value' => ['x' => 1]]);

        return [$user, $field];
    }
}
