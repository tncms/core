<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gates F and G: operator transfer is a
 * Core-controlled, permissioned compare-and-set; every ownership mutation is
 * audited in the same transaction, with no duplicate rows on retry.
 */
final class OwnershipTransferAuditTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
        $this->guardedPlugin('uo-alpha');
        $this->guardedPlugin('uo-beta');
    }

    private function operatorArgs(string $email): array
    {
        return ['--actor' => $email, '--reason' => 'consolidating content'];
    }

    public function test_operator_transfer_is_permissioned_cas_and_audited(): void
    {
        $post = $this->coreArticle('Transfer');
        $plain = $this->userWithRole('admin');
        $operator = $this->userWithRole('admin', ['content.authority.recover']);

        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-alpha', '--fallback' => 'hide_404'] + $this->operatorArgs($plain->email))
            ->expectsOutputToContain('unauthorized_transfer')->assertFailed();
        $this->assertNull($post->refresh()->managed_by);

        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-alpha', '--fallback' => 'hide_404'] + $this->operatorArgs($operator->email))
            ->assertSuccessful();
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-beta', '--fallback' => 'core_render'] + $this->operatorArgs($operator->email))
            ->expectsOutputToContain('ownership_changed')->assertFailed(); // stale expectation: still thinks Core
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-alpha', '--fallback' => 'hide_404', '--from' => 'uo-alpha'] + $this->operatorArgs($operator->email))
            ->expectsOutputToContain('ownership_changed')->assertFailed(); // no-op refused, no second audit row
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-beta', '--fallback' => 'core_render', '--from' => 'uo-alpha'] + $this->operatorArgs($operator->email))
            ->assertSuccessful();
        $this->artisan('cms:content-owner', ['action' => 'release', 'content' => $post->id, '--from' => 'uo-beta'] + $this->operatorArgs($operator->email))
            ->assertSuccessful();

        $this->assertNull($post->refresh()->managed_by);
        $this->assertSame(0, DB::table('cms_content_owner_policies')->where('content_id', $post->id)->count(), 'release removes the policy');
        $this->assertSame([
            ['transferred', 'uo-alpha', null, 'uo-alpha', 'operator', $operator->id, 'consolidating content'],
            ['transferred', 'uo-beta', 'uo-alpha', 'uo-beta', 'operator', $operator->id, 'consolidating content'],
            ['released', 'uo-beta', 'uo-beta', null, 'operator', $operator->id, 'consolidating content'],
        ], array_map(static fn ($e) => [$e['action'], $e['owner'], $e['previous_owner'], $e['new_owner'], $e['origin'], (int) $e['actor_id'], $e['reason']], $this->events($post->id)));
    }

    public function test_transfer_marks_the_fallback_operator_controlled_and_refuses_the_front_page(): void
    {
        $operator = $this->userWithRole('admin', ['content.authority.recover']);
        $post = $this->coreArticle('Operator Fallback');
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $post->id, 'owner' => 'uo-alpha', '--fallback' => 'temporary_503'] + $this->operatorArgs($operator->email))->assertSuccessful();

        $this->actingAs($this->superAdmin());
        $this->assertSame('ownership:operator_controlled', $this->denial(fn () => $this->ownerCode('uo-alpha')->fallback($post->id, ContentFallbackPolicy::CoreRender)));

        $front = $this->makeContent(['title' => 'Front', 'type' => 'page', 'status' => 'published']);
        settings()->set('reading.homepage_page_id', $front->id, 'integer');
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $front->id, 'owner' => 'uo-alpha', '--fallback' => 'core_render'] + $this->operatorArgs($operator->email))
            ->expectsOutputToContain('front_page')->assertFailed();
    }

    public function test_plugin_code_and_web_requests_cannot_reach_operator_transfer(): void
    {
        $post = $this->coreArticle('Not For Plugins');
        $this->actingAs($this->userWithRole('admin', ['content.authority.recover']));

        $this->assertSame('ownership:operator_only', $this->denial(fn () => $this->ownerCode('uo-alpha')->transfer($post->id, 'uo-alpha')));
        $this->assertSame('logic', $this->denial(fn () => $this->ownerCode('uo-alpha')->operatorContext($this->superAdmin())), 'plugins cannot mint an operator context');
        $this->assertNull($post->refresh()->managed_by);
    }

    public function test_transfer_away_from_a_missing_owner_is_possible_and_ownership_survives_deactivation(): void
    {
        $operator = $this->userWithRole('admin', ['content.authority.recover']);
        $this->actingAs($this->superAdmin());
        $post = $this->ownerCode('uo-alpha')->own(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Orphan', 'content' => 'x']);

        app('cms.extension')->deactivatePlugin('uo-alpha');
        app('cms.extension')->flushRegistry();
        $this->assertSame('uo-alpha', $post->refresh()->managed_by, 'deactivation never clears ownership');

        $this->artisan('cms:content-owner', ['action' => 'release', 'content' => $post->id, '--from' => 'uo-alpha'] + $this->operatorArgs($operator->email))
            ->assertSuccessful();
        $this->assertNull($post->refresh()->managed_by);
    }

    public function test_owner_release_is_audited_and_a_retry_writes_nothing(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = $this->ownerCode('uo-alpha');
        $post = $owner->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Release', 'content' => 'x']);

        $owner->release($post->id);
        $this->assertSame('ownership:not_owner', $this->denial(fn () => $owner->release($post->id)));

        $this->assertSame(['created', 'released'], array_column($this->events($post->id), 'action'));
        $this->assertSame([null, 'extension'], [$this->events($post->id)[1]['new_owner'], $this->events($post->id)[1]['origin']]);
    }

    public function test_one_http_request_shares_one_correlation_id_and_audit_holds_no_payload(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = $this->ownerCode('uo-alpha');
        \Illuminate\Support\Facades\Route::middleware('web')->post('/__uo/pair', static function () use ($owner) {
            $a = $owner->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'A secret=hunter2', 'content' => 'body-secret']);
            $b = $owner->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'B', 'content' => 'x']);

            return [$a->id, $b->id];
        })->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        app('router')->getRoutes()->refreshNameLookups();

        [$a, $b] = $this->post('/__uo/pair')->assertOk()->json();
        $ca = $this->events($a)[0]['correlation_id'];
        $this->assertSame($ca, $this->events($b)[0]['correlation_id']);

        [$c] = $this->post('/__uo/pair')->assertOk()->json();
        $this->assertNotSame($ca, $this->events($c)[0]['correlation_id'], 'a new request gets a new id');

        $row = (string) json_encode(DB::table('cms_content_authority_events')->get());
        $this->assertStringNotContainsString('hunter2', $row);
        $this->assertStringNotContainsString('body-secret', $row);
    }

    public function test_audit_and_ownership_roll_back_together(): void
    {
        $this->guardClass('uo-alpha')::$mode = 'deny';
        $this->actingAs($this->superAdmin());
        $before = DB::table('cms_content_authority_events')->count();

        $this->assertSame('guard_denied.locked', $this->denial(fn () => $this->ownerCode('uo-alpha')->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Vetoed', 'content' => 'x'])));
        $this->assertSame($before, DB::table('cms_content_authority_events')->count());
        $this->assertSame(0, DB::table('cms_contents')->where('managed_by', 'uo-alpha')->count());
        $this->assertSame(0, DB::table('cms_content_owner_policies')->count());
    }
}
