<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\LegacyOwnershipReconciler;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gate J, paths D–G: a development database with
 * the unreleased CONTENT-EXT `cms_content_ownership` table is folded into the
 * single authority; conflicts abort with a report and change nothing; an
 * inactive owner and existing audit rows are preserved; re-runs are no-ops.
 */
final class LegacyOwnershipMigrationTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();

        // The exact Line A schema (2026_10_29_000001, never released).
        Schema::create('cms_content_ownership', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_id')->primary();
            $table->string('owner', 64)->index();
            $table->string('fallback_policy', 32);
            $table->string('availability', 32)->default('available');
            $table->unsignedInteger('retry_after')->nullable();
            $table->boolean('operator_assigned')->default(false);
            $table->timestamps();
        });
    }

    private function legacy(int $id, string $owner, string $fallback = 'hide_404', string $availability = 'available'): void
    {
        DB::table('cms_content_ownership')->insert([
            'content_id' => $id, 'owner' => $owner, 'fallback_policy' => $fallback, 'availability' => $availability,
            'retry_after' => null, 'operator_assigned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function migration(): object
    {
        return require base_path('packages/thenguyen/cms-core/database/migrations/2026_10_30_000003_reconcile_legacy_content_ownership.php');
    }

    public function test_path_d_maps_legacy_rows_idempotently_with_audit_and_keeps_the_table(): void
    {
        $a = $this->coreArticle('Legacy A');
        $b = $this->coreArticle('Legacy B');
        $c = $this->coreArticle('Already Consistent');
        $this->legacy($a->id, 'uo-gone', 'hide_404', 'unavailable');   // owner plugin absent (path F)
        $this->legacy($b->id, 'uo-alpha', 'core_render');
        $this->legacy($c->id, 'uo-alpha', 'temporary_503');
        DB::table('cms_contents')->where('id', $c->id)->update(['managed_by' => 'uo-alpha']);
        DB::table('cms_content_authority_events')->insert(['content_id' => $c->id, 'action' => 'claimed', 'owner' => 'uo-alpha', 'actor_id' => null, 'revision' => 0, 'reason' => null, 'created_at' => now()]); // path G

        $plan = (new LegacyOwnershipReconciler)->plan();
        $this->assertSame(['legacy_rows' => 3, 'to_adopt' => 2, 'already_consistent' => 1, 'conflicts' => []], $plan);

        $this->migration()->up();
        $this->migration()->up(); // idempotent

        $this->assertSame(['uo-gone', 'uo-alpha', 'uo-alpha'], [$a->refresh()->managed_by, $b->refresh()->managed_by, $c->refresh()->managed_by]);
        $this->assertSame('unavailable', DB::table('cms_content_owner_policies')->where('content_id', $a->id)->value('availability'));
        $this->assertSame('temporary_503', DB::table('cms_content_owner_policies')->where('content_id', $c->id)->value('fallback_policy'));
        $this->assertSame(1, DB::table('cms_content_authority_events')->where('content_id', $a->id)->where('action', 'reconciled')->count(), 'one audit row, none on re-run');
        $this->assertSame(['claimed'], DB::table('cms_content_authority_events')->where('content_id', $c->id)->pluck('action')->all(), 'existing events preserved, consistent rows not re-audited');
        $this->assertSame('reconcile', DB::table('cms_content_authority_events')->where('content_id', $a->id)->value('origin'));

        $this->assertTrue(Schema::hasTable('cms_content_ownership'), 'legacy table kept until an operator drops it');
        $this->assertSame([], (new LegacyOwnershipReconciler)->verify());

        $this->artisan('cms:content-ownership:reconcile', ['--drop-legacy' => true])->expectsOutputToContain('--confirm-backup')->assertFailed();
        $this->artisan('cms:content-ownership:reconcile', ['--drop-legacy' => true, '--confirm-backup' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('cms_content_ownership'));
    }

    public function test_path_e_conflicting_owner_aborts_with_a_report_and_changes_nothing(): void
    {
        $a = $this->coreArticle('Contested');
        $b = $this->coreArticle('Clean');
        $this->legacy($a->id, 'uo-alpha');
        $this->legacy($b->id, 'uo-alpha');
        DB::table('cms_contents')->where('id', $a->id)->update(['managed_by' => 'uo-beta']);
        $before = [DB::table('cms_contents')->orderBy('id')->get()->toJson(), DB::table('cms_content_authority_events')->count()];

        try {
            $this->migration()->up();
            $this->fail('the migration must abort on conflicts');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
        }

        $this->assertSame($before, [DB::table('cms_contents')->orderBy('id')->get()->toJson(), DB::table('cms_content_authority_events')->count()]);
        $this->assertSame(0, DB::table('cms_content_owner_policies')->count());
        $report = (new LegacyOwnershipReconciler)->plan();
        $this->assertSame([['content_id' => $a->id, 'legacy_owner' => 'uo-alpha', 'managed_by' => 'uo-beta', 'reason' => 'managed_by_other']], $report['conflicts']);

        $this->artisan('cms:content-ownership:reconcile')->expectsOutputToContain('conflicts=1')->assertFailed();
        $this->artisan('cms:content-ownership:reconcile', ['--apply' => true])->assertFailed();
        $this->assertNull($b->refresh()->managed_by, 'no winner chosen, nothing applied');
        $this->assertSame(['uo-alpha', 'uo-alpha'], DB::table('cms_content_ownership')->orderBy('content_id')->pluck('owner')->all(), 'legacy data untouched');
    }

    public function test_invalid_legacy_values_and_orphans_are_conflicts(): void
    {
        $a = $this->coreArticle('Bad Policy');
        $this->legacy($a->id, 'uo-alpha', 'whatever');
        $this->legacy(999999, 'uo-alpha');
        $this->legacy($this->coreArticle('Bad Slug')->id, '../evil');

        $reasons = array_column((new LegacyOwnershipReconciler)->plan()['conflicts'], 'reason');
        sort($reasons);
        $this->assertSame(['content_missing', 'invalid_owner', 'invalid_policy'], $reasons);
    }

    public function test_dropping_refuses_while_rows_are_unverified(): void
    {
        $a = $this->coreArticle('Unmapped');
        $this->legacy($a->id, 'uo-alpha');

        $this->artisan('cms:content-ownership:reconcile', ['--drop-legacy' => true, '--confirm-backup' => true])
            ->expectsOutputToContain('not reflected')->assertFailed();
        $this->assertTrue(Schema::hasTable('cms_content_ownership'));
    }

    public function test_installations_without_the_legacy_table_are_untouched(): void
    {
        Schema::drop('cms_content_ownership');
        $before = DB::table('cms_contents')->get()->toJson();

        $this->migration()->up();

        $this->assertSame($before, DB::table('cms_contents')->get()->toJson());
        $this->artisan('cms:content-ownership:reconcile')->expectsOutputToContain('nothing to reconcile')->assertSuccessful();
    }
}
