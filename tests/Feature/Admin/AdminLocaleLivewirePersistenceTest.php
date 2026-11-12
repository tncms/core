<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1, Track C: the Admin UI locale must hold on
 * Livewire update requests (POST /livewire-xxx/update), not only on the full page
 * load. Driven over real HTTP (route middleware + Livewire PersistentMiddleware),
 * because Livewire::test() bypasses middleware entirely.
 */
final class AdminLocaleLivewirePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $languages = app('cms.language');
        $languages->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true]);
        $languages->create(['code' => 'en', 'name' => 'English', 'is_active' => true]);
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder::class);
    }

    private function admin(?string $adminLocale): User
    {
        $user = User::factory()->create(['admin_locale' => $adminLocale]);
        Role::query()->where('slug', 'admin')->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();

        return $user;
    }

    /** @return array{snapshot: string, html: string} */
    private function firstPageComponent(TestResponse $page, string $class): array
    {
        $html = $page->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        foreach ($m[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);
            $decoded = json_decode($snapshot, true);
            if (($decoded['memo']['name'] ?? null) !== null && str_contains((string) json_encode($decoded['memo']), str_replace('\\', '\\\\', $class))) {
                return ['snapshot' => $snapshot, 'html' => $html];
            }
        }
        $this->fail('component snapshot not found for '.$class);
    }

    private function livewireUpdate(string $snapshot, array $calls = []): TestResponse
    {
        return $this->withHeaders(['X-Livewire' => 'true', 'Accept' => 'application/json'])
            ->postJson(Livewire::getUpdateUri(), [
                '_token' => csrf_token(),
                'components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => $calls]],
            ]);
    }

    private function updatedHtml(TestResponse $response): string
    {
        $response->assertOk();

        return (string) ($response->json('components.0.effects.html') ?? '');
    }

    public function test_an_english_admin_stays_english_across_a_livewire_update_on_a_vietnamese_site(): void
    {
        $user = $this->admin('en');
        $this->actingAs($user);

        $page = $this->get('/admin/xml-sitemap');
        $page->assertOk();
        app('cms.language')->setCurrent('en');
        $english = tn_trans('XML Sitemap');
        app('cms.language')->setCurrent('vi');
        $vietnamese = tn_trans('XML Sitemap');
        $this->assertNotSame($english, $vietnamese, 'fixture: the label is translated');
        $page->assertSee($english);

        $component = $this->firstPageComponent($page, \App\Filament\Admin\Pages\SitemapPage::class);
        $html = $this->updatedHtml($this->livewireUpdate($component['snapshot'], [['path' => '', 'method' => '$refresh', 'params' => []]]));

        $this->assertStringContainsString($english, $html, 'the re-render keeps the admin locale');
        $this->assertStringNotContainsString($vietnamese, $html);
    }

    public function test_a_vietnamese_admin_stays_vietnamese_and_sessions_do_not_leak(): void
    {
        $en = $this->admin('en');
        $vi = $this->admin('vi');

        $this->actingAs($en);
        $enPage = $this->get('/admin/xml-sitemap');
        $enSnapshot = $this->firstPageComponent($enPage, \App\Filament\Admin\Pages\SitemapPage::class)['snapshot'];

        $this->flushSession();
        $this->actingAs($vi);
        $viPage = $this->get('/admin/xml-sitemap');
        $viSnapshot = $this->firstPageComponent($viPage, \App\Filament\Admin\Pages\SitemapPage::class)['snapshot'];
        $viHtml = $this->updatedHtml($this->livewireUpdate($viSnapshot, [['path' => '', 'method' => '$refresh', 'params' => []]]));

        app('cms.language')->setCurrent('vi');
        $this->assertStringContainsString(tn_trans('XML Sitemap'), $viHtml);
        $this->assertSame('vi', $vi->fresh()->admin_locale);
        $this->assertSame('en', $en->fresh()->admin_locale, 'another user is never rewritten');
        $this->assertNotSame('', $enSnapshot);
    }

    public function test_a_livewire_update_never_persists_a_locale_change(): void
    {
        $user = $this->admin('en');
        $this->actingAs($user);
        $snapshot = $this->firstPageComponent($this->get('/admin/xml-sitemap'), \App\Filament\Admin\Pages\SitemapPage::class)['snapshot'];

        // A forged ?lang on the Livewire endpoint itself must not switch or persist anything:
        // only the explicit ?lang switch on a real admin page does.
        $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri().'?lang=vi', [
                '_token' => csrf_token(),
                'components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => []]],
            ])->assertOk();

        $this->assertSame('en', $user->fresh()->admin_locale);
    }

    public function test_the_admin_locale_does_not_change_the_public_site_locale(): void
    {
        $this->actingAs($this->admin('en'));
        $this->get('/admin/xml-sitemap')->assertOk();

        $home = $this->get('/');
        $this->assertStringContainsString('lang="vi"', (string) $home->getContent());
    }
}
