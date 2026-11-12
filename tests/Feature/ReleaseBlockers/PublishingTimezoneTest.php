<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-RELEASE-BLOCKERS-R1 (TD-PUB-TZ): the publishing timezone contract (doc 07).
 *
 *  - published_at is stored as a wall-clock time in config('app.timezone') and compared in that timezone;
 *  - app.timezone is the only timezone that decides visibility: Settings → General → Timezone
 *    (general.timezone) is stored for display only and never moves a publication time;
 *  - the behaviour across a daylight-saving transition is deterministic;
 *  - a legacy NULL publication time stays public in every timezone;
 *  - changing app.timezone on a populated site reinterprets every stored time (no silent conversion):
 *    that is an operator action with a checklist, not something Core does on its own.
 */
final class PublishingTimezoneTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCxLanguages();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        parent::tearDown();
    }

    private function useTimezone(string $tz): void
    {
        config(['app.timezone' => $tz]);
        date_default_timezone_set($tz);
    }

    private function visible(Content $post): bool
    {
        $fresh = Content::query()->findOrFail($post->id);

        return $fresh->isPubliclyPublished() && Content::query()->published()->whereKey($post->id)->exists();
    }

    private function storedRaw(Content $post): ?string
    {
        return DB::table('cms_contents')->where('id', $post->id)->value('published_at');
    }

    public function test_the_boundary_is_exact_across_a_spring_forward_transition(): void
    {
        $this->useTimezone('America/New_York');   // 2026-03-08 02:00 EST -> 03:00 EDT
        Carbon::setTestNow(Carbon::parse('2026-03-08 01:00:00', 'America/New_York'));
        $post = $this->corePost('DST Edition', ['published_at' => Carbon::parse('2026-03-08 03:30:00', 'America/New_York')]);

        $this->assertStringStartsWith('2026-03-08 03:30:00', (string) $this->storedRaw($post), 'stored as app-timezone wall-clock');

        Carbon::setTestNow(Carbon::parse('2026-03-08 01:59:59', 'America/New_York'));
        $this->assertFalse($this->visible($post));
        Carbon::setTestNow(Carbon::parse('2026-03-08 03:29:59', 'America/New_York'));   // 1 real second earlier than the time
        $this->assertFalse($this->visible($post));
        Carbon::setTestNow(Carbon::parse('2026-03-08 03:30:00', 'America/New_York'));
        $this->assertTrue($this->visible($post));
    }

    public function test_the_boundary_is_exact_across_a_fall_back_transition(): void
    {
        $this->useTimezone('America/New_York');   // 2026-11-01 02:00 EDT -> 01:00 EST
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'America/New_York'));
        $post = $this->corePost('Fall Edition', ['published_at' => Carbon::parse('2026-11-01 02:30:00', 'America/New_York')]);

        Carbon::setTestNow(Carbon::parse('2026-11-01 02:29:59', 'America/New_York'));
        $this->assertFalse($this->visible($post));
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:30:00', 'America/New_York'));
        $this->assertTrue($this->visible($post));
    }

    public function test_the_settings_timezone_never_moves_a_publication_time(): void
    {
        $this->useTimezone('UTC');
        app('cms.settings')->set('general.timezone', 'Asia/Ho_Chi_Minh', 'string');
        app('cms.settings')->clearCache();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $post = $this->corePost('UTC Edition', ['published_at' => Carbon::parse('2026-11-10 10:00:00', 'UTC')]);

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:59:59', 'UTC'));   // 16:59:59 in Ho Chi Minh City
        $this->assertFalse($this->visible($post));
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'UTC'));
        $this->assertTrue($this->visible($post));
    }

    public function test_a_legacy_null_publication_time_is_public_in_every_timezone(): void
    {
        $post = $this->corePost('Legacy Edition', ['published_at' => null]);
        DB::table('cms_contents')->where('id', $post->id)->update(['published_at' => null]);

        foreach (['UTC', 'Asia/Ho_Chi_Minh', 'America/New_York', 'Pacific/Kiritimati'] as $tz) {
            $this->useTimezone($tz);
            $this->assertTrue($this->visible($post), $tz);
        }
    }

    public function test_changing_the_application_timezone_reinterprets_stored_times_without_converting_them(): void
    {
        $this->useTimezone('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', 'UTC'));
        $post = $this->corePost('Shifted Edition', ['published_at' => Carbon::parse('2026-11-10 10:00:00', 'UTC')]);
        $raw = $this->storedRaw($post);

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:30:00', 'UTC'));
        $this->assertFalse($this->visible($post), 'UTC: 30 minutes before its time');

        // The operator changes APP_TIMEZONE; the same instant is now 16:30 in Ho Chi Minh City and the stored
        // wall-clock "10:00" is read as 10:00 +07, so the post becomes public 7 hours early.
        $this->useTimezone('Asia/Ho_Chi_Minh');
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:30:00', 'UTC')->setTimezone('Asia/Ho_Chi_Minh'));
        $this->assertTrue($this->visible($post), 'documented hazard: a timezone change shifts every stored time');
        $this->assertSame($raw, $this->storedRaw($post), 'Core never rewrites stored times on its own');
    }
}
