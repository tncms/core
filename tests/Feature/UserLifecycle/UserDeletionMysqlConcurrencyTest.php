<?php

declare(strict_types=1);

namespace Tests\Feature\UserLifecycle;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TheNguyen\CMS\Services\UserDeletionManager;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * CORE-USER-LIFECYCLE-1 — MySQL (InnoDB) certification of the Core-owned
 * guarantees SQLite cannot prove (§11): the pre-delete lifecycle runs inside a
 * real transaction, a veto rolls back real row locks, a concurrent supported
 * delete cannot bypass the lifecycle's row lock, batch ordering is deterministic,
 * and a batch veto leaves no partial Core-owned state.
 *
 * Scope is deliberately Core-only. It does NOT claim any plugin invariant (e.g.
 * the Business Reviews last-active-Owner rule) is race-safe — that belongs to the
 * later plugin phase with the plugin's own shared lock protocol.
 *
 * Skipped unless run against MySQL (so the default SQLite suite is unaffected).
 * Uses DatabaseMigrations (no wrapping transaction) so rows are committed and
 * visible to a second connection.
 */
final class UserDeletionMysqlConcurrencyTest extends TestCase
{
    private UserDeletionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip FIRST — before any DB work — so the default SQLite suite is
        // unaffected (no migration trait runs here on purpose).
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency certification.');
        }

        // Fresh committed schema each test (no wrapping transaction) so a second
        // connection can see the rows and real row locks apply.
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->manager = app('cms.user.deletion');

        // A second, independent connection to the same MySQL database so we can
        // hold a lock on one connection and probe it from another.
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        DB::purge('mysql_second');

        Schema::create('lifecycle_mysql_dependents', function ($table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::dropIfExists('lifecycle_mysql_dependents');
        }

        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        return User::query()->create([
            'name' => 'MySQL '.$email,
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);
    }

    public function test_veto_rolls_back_on_innodb(): void
    {
        $user = $this->makeUser('veto-mysql@example.test');
        DB::table('lifecycle_mysql_dependents')->insert(['user_id' => $user->id]);

        $level = 0;
        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$level) {
            $level = DB::transactionLevel(); // #13 inside a real transaction

            return UserDeletionVeto::make('mysql.veto', 'Blocked on InnoDB.');
        });

        $result = $this->manager->delete($user);

        $this->assertGreaterThan(0, $level);
        $this->assertTrue($result->wasVetoed());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, DB::table('lifecycle_mysql_dependents')->where('user_id', $user->id)->count());
    }

    public function test_concurrent_delete_cannot_bypass_the_row_lock(): void
    {
        $target = $this->makeUser('race@example.test');

        // Connection A grabs the row lock the manager relies on and holds it.
        $connA = DB::connection('mysql');
        $connA->beginTransaction();
        $connA->table('users')->where('id', $target->id)->lockForUpdate()->first();

        // Connection B, with a short lock-wait timeout, cannot acquire the same
        // row lock — proving a concurrent supported delete is serialized, not
        // allowed to slip past the lifecycle.
        $connB = DB::connection('mysql_second');
        $connB->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;
        try {
            $connB->beginTransaction();
            $connB->table('users')->where('id', $target->id)->lockForUpdate()->first();
            $connB->commit();
        } catch (QueryException $e) {
            $blocked = true;
            $connB->rollBack();
        }

        $this->assertTrue($blocked, 'A concurrent locker must block on the manager row lock.');

        // Release A; the row is intact and a supported delete now succeeds.
        $connA->rollBack();

        $result = $this->manager->delete($target);
        $this->assertTrue($result->wasDeleted());
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_batch_veto_leaves_no_partial_state_on_innodb(): void
    {
        $a = $this->makeUser('batch-a@example.test');
        $b = $this->makeUser('batch-b@example.test');
        $c = $this->makeUser('batch-c@example.test');

        $order = [];
        $this->manager->registerPreDelete(function (UserDeletionContext $ctx) use (&$order, $c) {
            $order[] = $ctx->userId();

            return $ctx->userId() === $c->id
                ? UserDeletionVeto::make('mysql.batch', 'C blocks the batch.')
                : null;
        });

        $results = $this->manager->deleteMany([$c, $a, $b]);

        // Deterministic ascending order, every target visited.
        $this->assertSame([$a->id, $b->id, $c->id], $order);
        // Atomic: one veto => nothing deleted.
        $this->assertTrue($results[$c->id]->wasVetoed());
        $this->assertDatabaseHas('users', ['id' => $a->id]);
        $this->assertDatabaseHas('users', ['id' => $b->id]);
        $this->assertDatabaseHas('users', ['id' => $c->id]);
    }

    public function test_batch_all_allowed_commits_on_innodb(): void
    {
        $a = $this->makeUser('ok-a@example.test');
        $b = $this->makeUser('ok-b@example.test');

        $results = $this->manager->deleteMany([$a, $b]);

        $this->assertTrue($results[$a->id]->wasDeleted());
        $this->assertTrue($results[$b->id]->wasDeleted());
        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertDatabaseMissing('users', ['id' => $b->id]);
    }
}
