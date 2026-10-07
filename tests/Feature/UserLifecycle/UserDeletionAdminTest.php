<?php

declare(strict_types=1);

namespace Tests\Feature\UserLifecycle;

use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * CORE-USER-LIFECYCLE-1 — certifies the real Filament UserResource delete paths
 * route through the Core user-deletion authority: a normal delete succeeds, an
 * expected veto becomes a safe danger notification (no 500 / no false success /
 * record kept), and an unexpected handler failure fails closed. All handlers are
 * synthetic, test-only extensions — Core carries no plugin knowledge.
 */
final class UserDeletionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret-password'),
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function makeTarget(string $email = 'target@example.test'): User
    {
        return User::query()->create([
            'name' => 'Target',
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);
    }

    // --- Normal delete (§9 #17) ----------------------------------------------

    public function test_list_delete_action_removes_user(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeTarget();

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target);

        $this->assertModelMissing($target);
    }

    public function test_edit_page_delete_action_removes_user(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeTarget('edit-target@example.test');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->callAction('delete');

        $this->assertModelMissing($target);
    }

    // --- Veto (§9 #18–#21) ---------------------------------------------------

    public function test_veto_shows_notification_and_keeps_user(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeTarget('vetoed@example.test');

        app('cms.user.deletion')->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('test.admin', 'Reassign the owner first.'),
        );

        // No exception (no 500); the action halts gracefully and renders the
        // extension's safe message (resolved exactly like the action does, so
        // the assertion is locale-agnostic). #19 safe notification.
        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target)
            ->assertNotified(tn_trans('This account cannot be deleted'));

        $this->assertModelExists($target); // #20 user remains
    }

    public function test_veto_does_not_report_a_success(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeTarget('nofalse@example.test');

        app('cms.user.deletion')->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('test.admin', 'Nope.'),
        );

        // The default DeleteAction success notification title must NOT appear. #21
        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target)
            ->assertNotNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelExists($target);
    }

    // --- Unexpected handler failure (§9 #22) ---------------------------------

    public function test_unexpected_handler_failure_does_not_delete(): void
    {
        $this->actingAsAdmin();
        $target = $this->makeTarget('boom@example.test');

        app('cms.user.deletion')->registerPreDelete(function (UserDeletionContext $ctx): void {
            throw new RuntimeException('unexpected admin boom');
        });

        $threw = null;
        try {
            Livewire::test(ListUsers::class)->callTableAction('delete', $target);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        // Fails closed: the failure surfaces through normal error handling
        // (propagated, not swallowed as a success) and the user is NOT deleted.
        $this->assertNotNull($threw, 'An unexpected handler failure must not be silently swallowed.');
        $this->assertModelExists($target);
    }

    // --- Authorization is unchanged (§9 #34, §10) ----------------------------

    public function test_authorization_guards_are_intact(): void
    {
        $self = $this->actingAsAdmin();

        // Never delete yourself; no bulk delete path exists.
        $this->assertFalse(UserResource::canDelete($self));
        $this->assertFalse(UserResource::canDeleteAny());

        // Another account may be deleted (cms_can fails open without seeded RBAC).
        $other = $this->makeTarget('other@example.test');
        $this->assertTrue(UserResource::canDelete($other));
    }
}
