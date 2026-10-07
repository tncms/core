<?php

declare(strict_types=1);

namespace Tests\Feature\UserLifecycle;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\UserDeletionVetoException;
use TheNguyen\CMS\Services\UserDeletionManager;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionResult;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * CORE-USER-LIFECYCLE-1 — certifies the Core-owned vetoable user-deletion
 * lifecycle at the service level: baseline deletion, typed veto, fail-closed
 * unexpected failures, the documented transaction boundary, and atomic batch
 * semantics. Core carries NO Business Reviews / plugin knowledge — every handler
 * here is a synthetic, test-only extension.
 */
final class UserDeletionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private UserDeletionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app('cms.user.deletion');

        // Synthetic dependent table with a real cascade FK to users. Stands in
        // for any plugin's user-owned rows so the cascade + rollback can be
        // proven without depending on a plugin schema.
        Schema::create('lifecycle_test_dependents', function ($table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('note')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lifecycle_test_dependents');

        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test '.$email,
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);
    }

    private function dependentCountFor(int $userId): int
    {
        return DB::table('lifecycle_test_dependents')->where('user_id', $userId)->count();
    }

    // --- Baseline (§9 #1–#4) -------------------------------------------------

    public function test_no_handler_allows_normal_deletion(): void
    {
        $user = $this->makeUser('baseline@example.test');

        $this->assertFalse($this->manager->hasPreDeleteHandlers());

        $result = $this->manager->delete($user);

        $this->assertInstanceOf(UserDeletionResult::class, $result);
        $this->assertTrue($result->wasDeleted());
        $this->assertFalse($result->wasVetoed());
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_fk_cascade_remains_correct_on_allow(): void
    {
        $user = $this->makeUser('cascade@example.test');
        DB::table('lifecycle_test_dependents')->insert(['user_id' => $user->id, 'note' => 'owned']);

        $this->assertSame(1, $this->dependentCountFor($user->id));

        $this->manager->delete($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, $this->dependentCountFor($user->id), 'FK cascade should remove dependent rows.');
    }

    public function test_normal_eloquent_lifecycle_still_fires(): void
    {
        $fired = [];
        User::deleting(function () use (&$fired): void {
            $fired[] = 'deleting';
        });
        User::deleted(function () use (&$fired): void {
            $fired[] = 'deleted';
        });

        $user = $this->makeUser('eloquent@example.test');
        $this->manager->delete($user);

        $this->assertSame(['deleting', 'deleted'], $fired, 'Manager must delete through the normal Eloquent path.');

        // Avoid leaking listeners into other tests.
        User::flushEventListeners();
    }

    public function test_plugin_absent_allows_normal_deletion(): void
    {
        // This worktree carries no Business Reviews plugin — proving Core deletes
        // normally with no plugin tables and no plugin class on the autoloader.
        $this->assertFalse(class_exists(\Plugins\BusinessReviews\Services\MembershipService::class));

        $user = $this->makeUser('noplugin@example.test');
        $result = $this->manager->delete($user);

        $this->assertTrue($result->wasDeleted());
    }

    // --- Veto (§9 #5–#12) ----------------------------------------------------

    public function test_veto_keeps_user_and_dependent_rows(): void
    {
        $user = $this->makeUser('veto@example.test');
        DB::table('lifecycle_test_dependents')->insert(['user_id' => $user->id, 'note' => 'owned']);

        $this->manager->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('test.blocked', 'Blocked for a good reason.', ['business' => 7]),
        );

        $result = $this->manager->delete($user);

        $this->assertTrue($result->wasVetoed());
        $this->assertFalse($result->wasDeleted());
        $this->assertDatabaseHas('users', ['id' => $user->id]);                   // #5 user remains
        $this->assertSame(1, $this->dependentCountFor($user->id));                // #6 dependents remain
        $this->assertSame('test.blocked', $result->veto()->code());               // #8 code propagates
        $this->assertSame('Blocked for a good reason.', $result->veto()->message()); // #8 message propagates
        $this->assertSame(['business' => 7], $result->veto()->metadata());
    }

    public function test_handler_runs_before_destructive_mutation(): void
    {
        $user = $this->makeUser('before@example.test');
        $existedAtHandlerTime = null;

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$existedAtHandlerTime) {
            // The row must still be present while the handler decides (#7).
            $existedAtHandlerTime = User::query()->whereKey($ctx->userId())->exists();

            return UserDeletionVeto::make('test.before', 'Not yet.');
        });

        $this->manager->delete($user);

        $this->assertTrue($existedAtHandlerTime, 'Handler must run before the row is deleted.');
    }

    public function test_one_of_several_handlers_vetoes_and_stops_the_chain(): void
    {
        $user = $this->makeUser('chain@example.test');
        $laterHandlerRan = false;

        $this->manager->registerPreDelete(
            fn (UserDeletionContext $ctx) => null, // allow
            priority: 10,
        );
        $this->manager->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('test.second', 'Second handler vetoes.'),
            priority: 20,
        );
        $this->manager->registerPreDelete(
            function (UserDeletionContext $ctx) use (&$laterHandlerRan) {
                $laterHandlerRan = true; // #10 must never run after a veto

                return null;
            },
            priority: 30,
        );

        $result = $this->manager->delete($user);

        $this->assertTrue($result->wasVetoed());                 // #9
        $this->assertSame('test.second', $result->veto()->code());
        $this->assertFalse($laterHandlerRan);                    // #10 no later work after veto
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_throwing_veto_exception_is_treated_as_veto(): void
    {
        $user = $this->makeUser('throwveto@example.test');

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx): void {
            throw new UserDeletionVetoException(UserDeletionVeto::make('test.thrown', 'Thrown veto.'));
        });

        $result = $this->manager->delete($user);

        $this->assertTrue($result->wasVetoed());
        $this->assertSame('test.thrown', $result->veto()->code());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_unexpected_exception_fails_closed_and_is_not_a_veto(): void
    {
        $user = $this->makeUser('boom@example.test');
        DB::table('lifecycle_test_dependents')->insert(['user_id' => $user->id, 'note' => 'owned']);

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx): void {
            throw new RuntimeException('unexpected boom');
        });

        $caught = null;
        try {
            $this->manager->delete($user);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);          // #11/#12 propagates as-is
        $this->assertNotInstanceOf(UserDeletionVetoException::class, $caught); // #12 not a business veto
        $this->assertSame('unexpected boom', $caught->getMessage());
        $this->assertDatabaseHas('users', ['id' => $user->id]);            // #11 no deletion
        $this->assertSame(1, $this->dependentCountFor($user->id));         // no cascade
    }

    // --- Transactions (§9 #13–#15) -------------------------------------------

    public function test_handler_executes_inside_a_transaction(): void
    {
        $user = $this->makeUser('tx@example.test');
        $levelInsideHandler = 0;

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$levelInsideHandler) {
            $levelInsideHandler = DB::transactionLevel();

            return null;
        });

        $this->manager->delete($user);

        $this->assertGreaterThan(0, $levelInsideHandler, 'Handlers must run inside the deletion transaction.');
    }

    public function test_handler_mutation_rolls_back_on_veto(): void
    {
        $user = $this->makeUser('rollback@example.test');

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) {
            // A handler writes inside the transaction, then vetoes — the write
            // must roll back with the aborted deletion (#14).
            DB::table('lifecycle_test_dependents')->insert(['user_id' => $ctx->userId(), 'note' => 'provisional']);

            return UserDeletionVeto::make('test.rollback', 'Rolling back.');
        });

        $result = $this->manager->delete($user);

        $this->assertTrue($result->wasVetoed());
        $this->assertSame(0, $this->dependentCountFor($user->id), 'In-handler mutation must roll back on veto.');
    }

    public function test_handler_mutation_rolls_back_on_unexpected_failure(): void
    {
        $user = $this->makeUser('rollback2@example.test');

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx): void {
            DB::table('lifecycle_test_dependents')->insert(['user_id' => $ctx->userId(), 'note' => 'provisional']);

            throw new RuntimeException('boom after write');
        });

        try {
            $this->manager->delete($user);
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, $this->dependentCountFor($user->id), 'In-handler mutation must roll back on unexpected failure.');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_context_targets_the_reloaded_row(): void
    {
        $user = $this->makeUser('reload@example.test');
        $seenId = null;
        $seenSource = null;

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$seenId, &$seenSource) {
            $seenId = $ctx->userId();
            $seenSource = $ctx->source();

            return UserDeletionVeto::make('test.reload', 'stop');
        });

        $this->manager->delete($user, null, UserDeletionContext::SOURCE_ADMIN);

        $this->assertSame($user->id, $seenId);                                 // #15 target resolved from committed row
        $this->assertSame(UserDeletionContext::SOURCE_ADMIN, $seenSource);
    }

    public function test_absent_target_is_idempotent_noop(): void
    {
        $user = $this->makeUser('ghost@example.test');
        $id = $user->id;
        User::query()->whereKey($id)->delete(); // vanish out of band

        $ran = false;
        $this->manager->registerPreDelete(function () use (&$ran) {
            $ran = true;

            return null;
        });

        $result = $this->manager->delete($user);

        $this->assertFalse($result->wasDeleted());
        $this->assertFalse($result->wasVetoed());
        $this->assertFalse($ran, 'No handler should run for an already-absent target.');
    }

    // --- Context / actor semantics -------------------------------------------

    public function test_actor_is_exposed_and_null_actor_is_supported(): void
    {
        $actor = $this->makeUser('actor@example.test');
        $target = $this->makeUser('target@example.test');

        $seenActorId = 'unset';
        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$seenActorId) {
            $seenActorId = $ctx->actorId();

            return null; // allow — we only assert what the actor exposes
        });

        $result = $this->manager->delete($target, $actor);
        $this->assertSame($actor->id, $seenActorId);
        $this->assertTrue($result->wasDeleted());

        // Null actor (CLI/programmatic): actorId() is null, deletion still runs.
        $target2 = $this->makeUser('target2@example.test');
        $result = $this->manager->delete($target2, null);
        $this->assertNull($seenActorId);
        $this->assertTrue($result->wasDeleted());
    }

    // --- Atomic batch (§9 #23–#27) -------------------------------------------

    public function test_batch_all_allowed_succeeds(): void
    {
        $a = $this->makeUser('a@example.test');
        $b = $this->makeUser('b@example.test');

        $results = $this->manager->deleteMany([$a, $b]);

        $this->assertTrue($results[$a->id]->wasDeleted());
        $this->assertTrue($results[$b->id]->wasDeleted());
        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertDatabaseMissing('users', ['id' => $b->id]);
    }

    public function test_batch_single_veto_aborts_everything_atomically(): void
    {
        $a = $this->makeUser('a2@example.test');
        $b = $this->makeUser('b2@example.test');
        $c = $this->makeUser('c2@example.test');

        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use ($b) {
            return $ctx->userId() === $b->id
                ? UserDeletionVeto::make('test.batch', 'B cannot go.')
                : null;
        });

        $results = $this->manager->deleteMany([$a, $b, $c]);

        $this->assertTrue($results[$b->id]->wasVetoed());
        $this->assertFalse($results[$a->id]->wasDeleted());
        $this->assertFalse($results[$c->id]->wasDeleted());
        // Atomic: nothing deleted.
        $this->assertDatabaseHas('users', ['id' => $a->id]);
        $this->assertDatabaseHas('users', ['id' => $b->id]);
        $this->assertDatabaseHas('users', ['id' => $c->id]);
    }

    public function test_batch_invokes_lifecycle_for_every_target_in_ascending_order(): void
    {
        $a = $this->makeUser('ord-a@example.test');
        $b = $this->makeUser('ord-b@example.test');
        $c = $this->makeUser('ord-c@example.test');

        $order = [];
        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$order) {
            $order[] = $ctx->userId();

            return null;
        });

        // Pass out of order; the manager must process ascending by key.
        $this->manager->deleteMany([$c, $a, $b]);

        $this->assertSame([$a->id, $b->id, $c->id], $order, 'Deterministic ascending-key ordering + every target invoked.');
    }

    public function test_multiple_vetoes_are_atomic(): void
    {
        $a = $this->makeUser('mv-a@example.test');
        $b = $this->makeUser('mv-b@example.test');

        $this->manager->registerPreDelete(
            fn (UserDeletionContext $ctx) => UserDeletionVeto::make('test.all', 'nobody goes'),
        );

        $results = $this->manager->deleteMany([$a, $b]);

        $this->assertTrue($results[$a->id]->wasVetoed());
        $this->assertTrue($results[$b->id]->wasVetoed());
        $this->assertDatabaseHas('users', ['id' => $a->id]);
        $this->assertDatabaseHas('users', ['id' => $b->id]);
    }

    // --- Compatibility / discovery (§9 #29–#32) ------------------------------

    public function test_handler_count_is_discoverable_without_exposing_callbacks(): void
    {
        $this->assertSame(0, $this->manager->preDeleteHandlerCount());
        $this->manager->registerPreDelete(fn () => null);
        $this->assertSame(1, $this->manager->preDeleteHandlerCount());
        $this->assertTrue($this->manager->hasPreDeleteHandlers());
    }

    public function test_no_handler_issues_no_extra_queries_beyond_the_delete(): void
    {
        $user = $this->makeUser('queries@example.test');

        DB::enableQueryLog();
        $this->manager->delete($user);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Reload-lock + delete (+ cascade handled by the DB engine). No plugin
        // introspection, no global scans.
        $this->assertLessThanOrEqual(3, count($queries), 'No-handler deletion must stay query-lean.');
    }
}
