<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CORE-SITEMAP-R1: SettingsManager::withinReadScope() memoizes reads for one
 * bounded batch only; outside a scope every read still sees the shared store
 * (the R2 stale-worker rule), and writes inside a scope are visible at once.
 */
final class SettingsReadScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_are_memoized_inside_the_scope_only(): void
    {
        settings()->set('sm.flag', 'one', 'string', ['autoload' => false]);

        $inside = settings()->withinReadScope(function (): array {
            $first = settings('sm.flag');
            DB::table('cms_settings')->where('group', 'sm')->where('key', 'flag')->update(['value' => 'two']);

            return [$first, settings('sm.flag'), settings('sm.missing', 'dflt')];
        });

        $this->assertSame(['one', 'one', 'dflt'], $inside, 'memoized for the batch');
        $this->assertSame('two', settings('sm.flag'), 'no leak after the scope');
    }

    public function test_autoload_changes_made_elsewhere_are_seen_outside_a_scope(): void
    {
        settings()->set('sm.auto', 'a', 'string');
        settings()->withinReadScope(fn () => settings('sm.auto'));

        // Another process writes and clears the shared cache.
        DB::table('cms_settings')->where('group', 'sm')->where('key', 'auto')->update(['value' => 'b']);
        cache()->forget('cms.settings.autoload');
        app('cms.settings')->clearCache();

        $this->assertSame('b', settings('sm.auto'));
    }

    public function test_writes_inside_a_scope_are_visible_immediately_and_nesting_restores_state(): void
    {
        settings()->set('sm.auto', 'a', 'string');

        $seen = settings()->withinReadScope(function (): array {
            $before = settings('sm.auto');
            settings()->set('sm.auto', 'b', 'string');
            $nested = settings()->withinReadScope(fn () => settings('sm.auto'));

            return [$before, settings('sm.auto'), $nested];
        });

        $this->assertSame(['a', 'b', 'b'], $seen);
        $this->assertSame('b', settings('sm.auto'));
    }
}
