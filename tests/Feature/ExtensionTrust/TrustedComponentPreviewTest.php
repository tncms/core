<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Support\TrustedComponents\ReadOnlyRenderGuard;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * CORE-EXTENSION-TRUST S2 — signed preview renders components read-only with
 * no side effects (brief §3.4, matrix T15, acceptance S2-7).
 */
final class TrustedComponentPreviewTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private object $code;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        Schema::create('s2_fixture_writes', function ($table) {
            $table->id();
            $table->unsignedInteger('v');
        });

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-prev');
        $this->activateComponentPlugins('s2-prev');
        $this->code = $this->pluginCode('s2-prev');
    }

    private function register(string $key, string $html, array $contexts, string $behaviour = ''): object
    {
        $provider = $this->provider('s2-prev', $key, $html, $contexts, ['@host'], null, [], $behaviour);
        $this->assertTrue($this->code->register($provider));
        $this->code->shortcode('s2_'.str_replace('-', '_', $key), 's2-prev/'.$key);

        return $provider;
    }

    private function draft(string $shortcode): Content
    {
        return app('cms.content')->create(['type' => 'page', 'status' => 'draft', 'locale' => 'en', 'title' => 'Draft '.$shortcode, 'slug' => 'draft-'.str_replace('_', '-', $shortcode), 'content' => '<p>Draft body</p>['.$shortcode.']']);
    }

    private function preview(Content $content): \Illuminate\Testing\TestResponse
    {
        $url = app('cms.preview')->temporaryUrl($content);

        return $this->get($url);
    }

    private function reasons(): array
    {
        return array_map(fn (array $d) => $d['reason'], $this->components()->diagnostics());
    }

    public function test_preview_renders_the_read_only_form_with_preview_headers(): void
    {
        $provider = $this->register('ro', self::FORM_READONLY, ['public', 'preview']);

        $response = $this->preview($this->draft('s2_ro'))->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<fieldset disabled>', $html);
        $this->assertStringContainsString('<input type="text" id="s2-name" name="name">', $html);
        $this->assertStringNotContainsString('TNCMSSLOT', $html);
        // Preview's own stronger header is kept, not replaced.
        $this->assertSame('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertSame('preview', $provider->calls[0]['mode']);
    }

    public function test_preview_rejects_a_submittable_form(): void
    {
        $this->register('live', self::FORM, ['public', 'preview']);

        $html = (string) $this->preview($this->draft('s2_live'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
    }

    public function test_preview_provider_database_write_is_blocked_before_it_executes(): void
    {
        $this->register('writer', self::FORM_READONLY, ['public', 'preview'], 'write');

        $html = (string) $this->preview($this->draft('s2_writer'))->assertOk()->getContent();

        $this->assertSame(0, DB::table('s2_fixture_writes')->count());
        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        $this->assertStringNotContainsString('<fieldset', $html);
    }

    public function test_the_same_provider_may_write_on_a_public_page_contrast(): void
    {
        $this->register('writer', '<p>public write ok</p>', ['public', 'preview'], 'write');
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Pub', 'slug' => 'pub', 'content' => '[s2_writer]']);

        $html = (string) $this->get('/pub')->assertOk()->getContent();

        $this->assertStringContainsString('<p>public write ok</p>', $html);
        $this->assertSame(1, DB::table('s2_fixture_writes')->count());
    }

    public function test_preview_provider_http_call_is_blocked(): void
    {
        $this->register('caller', self::FORM_READONLY, ['public', 'preview'], 'http');

        $html = (string) $this->preview($this->draft('s2_caller'))->assertOk()->getContent();

        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        Http::assertNothingSent();
    }

    public function test_preview_http_call_is_blocked_by_the_guard_even_when_the_client_would_answer(): void
    {
        // A faked client WOULD answer; only the read-only guard can stop the call.
        Http::fake(['*' => Http::response('ok')]);
        $this->register('caller', self::FORM_READONLY, ['public', 'preview'], 'http');

        $html = (string) $this->preview($this->draft('s2_caller'))->assertOk()->getContent();

        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        Http::assertNothingSent();

        // Outside a guarded render the same client works.
        Http::get('https://s2-fixture.invalid/after');
        Http::assertSentCount(1);
    }

    public function test_sql_read_classifier(): void
    {
        foreach (['select 1', '  SELECT * FROM x', '(select 1)', 'with t as (select 1) select * from t', 'explain select 1', 'show tables', 'describe x', 'pragma table_info(x)'] as $sql) {
            $this->assertTrue(ReadOnlyRenderGuard::isRead($sql), $sql);
        }

        foreach (['select 1; pragma query_only = 0', 'select 1; select 2', 'select 1; delete from users', 'EXPLAIN ANALYZE DELETE FROM users', "select * into outfile '/x' from t", "select setval('s',1)", 'pragma table_info(t); drop table t', 'with recursive a as (select 1) select 1; truncate t', 'select * from t for update', 'select 1 -- ; drop', 'select pg_sleep(5)', 'insert into x values (1)', 'update x set a=1', 'delete from x', 'replace into x values (1)', 'create table y (a int)', 'drop table x', 'alter table x add b int', 'truncate x', 'with t as (select 1) delete from x', 'pragma foreign_keys=off', 'vacuum', 'begin', 'set names utf8', 'lock tables x write', '/* c */ insert into x values (1)'] as $sql) {
            $this->assertFalse(ReadOnlyRenderGuard::isRead($sql), $sql);
        }
    }

    public function test_preview_write_on_a_connection_first_opened_during_the_render_is_blocked(): void
    {
        config(['database.connections.s2second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->register('second', self::FORM_READONLY, ['public', 'preview'], 'write-second');

        $html = (string) $this->preview($this->draft('s2_second'))->assertOk()->getContent();

        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        $this->assertFalse(Schema::connection('s2second')->hasTable('s2_second'));
    }

    public function test_public_only_component_is_not_rendered_in_preview(): void
    {
        $provider = $this->register('pubonly', '<p>public only</p>', ['public']);

        $html = (string) $this->preview($this->draft('s2_pubonly'))->assertOk()->getContent();

        $this->assertStringNotContainsString('public only', $html);
        $this->assertSame([], $provider->calls);
    }

    public function test_read_only_guard_is_released_after_the_render(): void
    {
        $this->register('writer', self::FORM_READONLY, ['public', 'preview'], 'write');
        $this->preview($this->draft('s2_writer'))->assertOk();

        // Writes outside a component render are unaffected.
        DB::table('s2_fixture_writes')->insert(['v' => 7]);
        $this->assertSame(1, DB::table('s2_fixture_writes')->count());
    }
}
