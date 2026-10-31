<?php

declare(strict_types=1);

namespace Tests\Feature\ContentActionAuthorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ContentActionAuthorization\Concerns\ActionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;

/**
 * CORE-CONTENT-ACTION-AUTHORIZATION-R1 — decisions are read-only: no row,
 * revision, audit, slug, cache, hook, event or log change, only SELECT
 * statements, no transaction or lock, and no session/auth change.
 */
final class ContentActionSideEffectTest extends TestCase
{
    use ActionFixtures;
    use RefreshDatabase;

    public function test_decisions_change_nothing_and_only_read(): void
    {
        $this->seedActionSite();
        $actors = [null, $this->noPermissionUser(), $this->userWithRole('author'), $this->userWithRole('editor'), $this->superAdmin()];
        $contents = [
            $this->makeContent(['status' => 'draft'], $actors[2]),
            $this->makeContent(['status' => 'published'], $actors[3]),
            $this->makeContent(['type' => 'page', 'status' => 'pending']),
        ];
        $revisions = array_map(static fn ($c): int => (int) $c->fresh()->revision, $contents);
        auth()->logout();

        $before = $this->databaseFingerprint();
        $hooks = 0;
        add_action('cms.content.saving', static function () use (&$hooks): void {
            $hooks++;
        });
        add_action('cms.content.saved', static function () use (&$hooks): void {
            $hooks++;
        });
        $events = [];
        Event::listen('*', static function (string $name) use (&$events): void {
            $events[] = $name;
        });
        Cache::spy();
        Log::spy();
        $level = DB::transactionLevel();
        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($actors as $actor) {
            foreach ($contents as $content) {
                foreach (ContentAction::cases() as $action) {
                    foreach ([null, 'vi', 'en', 'fr', 'bad!'] as $locale) {
                        $this->decide($actor, $action, $content, $locale);
                        $this->decide($actor, $action, $content->id, $locale);
                    }
                }
                $this->decide($actor, 'edit_layout', $content);
            }
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $decisionEvents = $events;

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\s/i', $query['query'], 'only reads');
            $this->assertStringNotContainsStringIgnoringCase('for update', $query['query']);
            $this->assertStringNotContainsStringIgnoringCase('lock in share mode', $query['query']);
        }

        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->databaseFingerprint());
        $this->assertSame($revisions, array_map(static fn ($c): int => (int) $c->fresh()->revision, $contents));
        $this->assertSame(0, $hooks);
        $unexpected = array_values(array_filter($decisionEvents, static fn (string $name): bool => preg_match(
            // Only the active-language lookup (LanguageManager) reads through a model.
            '/^(eloquent\\.(retrieved|booting|booted): TheNguyen\\\\CMS\\\\Models\\\\Language|Illuminate\\\\Database\\\\Events\\\\(QueryExecuted|StatementPrepared|ConnectionEstablished))$/',
            $name,
        ) !== 1));
        $this->assertSame([], array_values(array_unique($unexpected)), 'no domain events');
        Cache::shouldNotHaveReceived('put');
        Cache::shouldNotHaveReceived('forever');
        Cache::shouldNotHaveReceived('forget');
        Cache::shouldNotHaveReceived('flush');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        $this->assertFalse(auth()->check(), 'deciding for an actor never signs them in');

        // Positive control: the same probes do observe a real Core write.
        $this->actingAs($actors[4]);
        $this->contents()->update($contents[0], ['locale' => 'en', 'title' => 'Real write'], (int) $contents[0]->fresh()->revision, \TheNguyen\CMS\ContentAuthority\ContentWriteContext::interactive());
        $this->assertSame(2, $hooks);
        $this->assertNotSame($before, $this->databaseFingerprint());
        $this->assertContains('eloquent.saved: '.\TheNguyen\CMS\Models\Content::class, $events);
    }

    public function test_a_decision_does_not_modify_the_callers_model(): void
    {
        $this->seedActionSite();
        $editor = $this->userWithRole('editor');
        $post = $this->makeContent(['status' => 'draft'], $editor);
        $post->status = 'published'; // unsaved caller change
        $dirty = $post->getDirty();

        $this->decide($editor, ContentAction::Edit, $post, 'en');
        $this->actions()->decision($editor, ContentAction::Publish, $post, ContentActionContext::none());

        $this->assertSame($dirty, $post->getDirty());
        $this->assertFalse($post->relationLoaded('translations'));
    }
}
