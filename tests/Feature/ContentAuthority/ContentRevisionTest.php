<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentRevisionConflict;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — shared revision, expected-revision
 * enforcement, write units, nested rollback and after-commit semantics
 * (T-C1, T-C2, T-X3, T-X6, T-X8). Single-connection proofs; the cross-process
 * locking proof is ContentRevisionMySqlConcurrencyTest.
 */
final class ContentRevisionTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->actingAs($this->superAdmin());
    }

    private function ctx(): ContentWriteContext
    {
        return ContentWriteContext::interactive();
    }

    private function term(string $slug): int
    {
        app('cms.taxonomy')->ensureCoreTaxonomies();

        return (int) app('cms.taxonomy')->createTerm('category', ['name' => $slug, 'slug' => $slug, 'locale' => 'en'])->id;
    }

    public function test_every_kind_of_write_bumps_the_shared_revision_once(): void
    {
        $post = $this->makeContent(['title' => 'Rev']);
        $r0 = $post->revision;

        $post = $this->contents()->update($post, ['locale' => 'en', 'title' => 'Row+translation'], $r0, $this->ctx());
        $this->assertSame($r0 + 1, $post->revision);

        $post = $this->contents()->update($post, ['locale' => 'vi', 'title' => 'Bản dịch'], $post->revision, $this->ctx());
        $this->assertSame($r0 + 2, $post->revision, 'a translation write bumps the content revision');

        $post = $this->contents()->update($post, ['term_ids' => [$this->term('news')]], $post->revision, $this->ctx());
        $this->assertSame($r0 + 3, $post->revision, 'a term write bumps the content revision');

        $post = $this->contents()->changeStatus($post, 'pending', $post->revision, $this->ctx());
        $this->assertSame($r0 + 4, $post->revision);
    }

    public function test_a_stale_expected_revision_is_a_conflict_and_nothing_is_overwritten(): void
    {
        $post = $this->makeContent(['title' => 'Original']);
        $opened = $post->revision;

        // Another editor saves first.
        $this->contents()->update($post->replicate()->setRawAttributes($post->getAttributes(), true), ['locale' => 'en', 'title' => 'Theirs'], $opened, $this->ctx());
        $state = $this->contentState($post->id);

        try {
            $this->contents()->update($post, ['locale' => 'en', 'title' => 'Mine'], $opened, $this->ctx());
            $this->fail('A stale write was accepted.');
        } catch (ContentRevisionConflict $e) {
            $this->assertSame('revision_conflict', $e->errorCode());
            $this->assertSame($opened, $e->expected());
            $this->assertSame($opened + 1, $e->actual());
        }

        foreach ([
            fn () => $this->contents()->changeStatus($post, 'pending', $opened, $this->ctx()),
            fn () => $this->contents()->delete($post, $opened, $this->ctx()),
            fn () => $this->contents()->update($post, ['term_ids' => []], $opened, $this->ctx()),
        ] as $stale) {
            try {
                $stale();
                $this->fail('A stale write was accepted.');
            } catch (ContentRevisionConflict) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($state, $this->contentState($post->id));
    }

    public function test_interactive_writes_must_carry_a_revision(): void
    {
        $post = $this->makeContent(['title' => 'Needs token']);

        try {
            $this->contents()->update($post, ['locale' => 'en', 'title' => 'No token'], null, $this->ctx());
            $this->fail('An interactive write without a revision was accepted.');
        } catch (ContentWriteDenied $e) {
            $this->assertSame('revision_required', $e->errorCode());
        }

        // The pre-R1 signature on ORDINARY content stays source-compatible.
        $before = $post->revision;
        $legacy = $this->contents()->update($post, ['locale' => 'en', 'title' => 'Legacy caller']);
        $this->assertSame($before + 1, $legacy->revision);
    }

    public function test_a_legacy_call_from_plugin_code_must_carry_the_revision(): void
    {
        $this->useAuthorityFixturePlugins();
        $this->activateAuthorityPlugins('ca-legacy');
        $plugin = $this->pluginCode('ca-legacy');
        $post = $this->makeContent(['title' => 'Ordinary']);
        $opened = $post->revision;

        try {
            $plugin->legacyUpdate($post, ['locale' => 'en', 'title' => 'From plugin, no revision']);
            $this->fail('A plugin legacy write without a revision was accepted.');
        } catch (ContentWriteDenied $e) {
            $this->assertSame('revision_required', $e->errorCode());
        }

        $this->assertSame($opened, $this->contents()->revisionOf($post));
        $this->assertSame($opened + 1, $plugin->legacyUpdate($post, ['locale' => 'en', 'title' => 'From plugin'], $opened)->revision);
    }

    public function test_the_lock_reads_the_database_not_the_callers_stale_model(): void
    {
        $author = $this->userWithRole('author');
        $post = $this->makeContent(['title' => 'Draft by author'], $author);
        $stale = $post->replicate()->setRawAttributes($post->getAttributes(), true);

        // Someone publishes it; the author's in-memory copy still says draft.
        $post = $this->contents()->changeStatus($post, 'published', $post->revision, $this->ctx());

        $this->actingAs($author);

        try {
            $this->contents()->update($stale, ['locale' => 'en', 'title' => 'Author edit'], $post->revision, $this->ctx());
            $this->fail('The stale in-memory status was trusted.');
        } catch (ContentWriteDenied $e) {
            $this->assertSame('unauthorized_publish', $e->errorCode());
        }
    }

    public function test_a_write_unit_checks_once_bumps_once_and_runs_after_commit_only_on_success(): void
    {
        $plugin = (function () {
            $this->useAuthorityFixturePlugins();

            return $this->guardedPlugin('ca-unit', 'own-only');
        })();
        $admin = auth()->user();
        $post = $plugin->createManaged(['type' => 'post', 'locale' => 'en', 'title' => 'Unit', 'status' => 'draft'], $admin);
        $start = $post->revision;

        $committed = [];
        add_action('cms.content.committed', function ($id, $operation) use (&$committed): void {
            $committed[] = [$id, $operation];
        }, 10, 2);

        $plugin->publishUnit($post, $start, [
            ['locale' => 'en', 'title' => 'Unit EN', 'status' => 'published'],
            ['locale' => 'vi', 'title' => 'Unit VI'],
        ], $admin);

        $post->refresh();
        $this->assertSame($start + 1, $post->revision, 'one unit = one revision');
        $this->assertSame('published', $post->status);
        $this->assertSame(['en', 'vi'], $post->translations()->orderBy('locale')->pluck('locale')->all());
        $this->assertCount(2, $committed);

        // A failing unit rolls back content, translations, terms, revision and marker.
        $state = $this->contentState($post->id);
        $committed = [];

        try {
            $plugin->publishUnit($post, $post->revision, [
                ['locale' => 'en', 'title' => 'Will vanish', 'term_ids' => [$this->term('gone')]],
            ], $admin, function () {
                DB::table('cms_contents')->where('id', func_get_arg(0)->id)->update(['managed_by' => null]);
                throw new RuntimeException('extension snapshot failed');
            });
            $this->fail('The failing unit did not throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('extension snapshot failed', $e->getMessage());
        }

        $this->assertSame($state, $this->contentState($post->id));
        $this->assertSame([], $committed, 'no after-commit callback for a rolled-back unit');
    }

    public function test_a_stale_unit_writes_nothing(): void
    {
        $this->useAuthorityFixturePlugins();
        $plugin = $this->guardedPlugin('ca-unit2', 'own-only');
        $post = $plugin->createManaged(['type' => 'post', 'locale' => 'en', 'title' => 'Unit', 'status' => 'draft'], auth()->user());
        $state = $this->contentState($post->id);

        $this->expectException(ContentRevisionConflict::class);

        try {
            $plugin->publishUnit($post, $post->revision + 1, [['locale' => 'en', 'title' => 'Stale']], auth()->user());
        } finally {
            $this->assertSame($state, $this->contentState($post->id));
        }
    }
}
