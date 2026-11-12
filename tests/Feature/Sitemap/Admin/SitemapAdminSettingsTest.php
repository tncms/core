<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin;

use App\Filament\Admin\Pages\SitemapPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-SITEMAP-ADMIN-R1 — General settings and built-in content types:
 * persistence, public effect, authorization and server-side validation.
 */
final class SitemapAdminSettingsTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPost('Admin Post');
        $this->smRoutesWithAdmin();
    }

    public function test_defaults_preserve_the_current_behaviour(): void
    {
        $settings = app('cms.sitemap_settings');

        $this->assertTrue($settings->enabled());
        $this->assertSame([], $settings->excludedTypes());
        $this->assertSame([], $settings->excludedProviders());
        $this->assertContains('core-posts-vi-1', $this->indexNames());
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $this->get('/robots.txt')->getContent());
    }

    public function test_disabling_the_sitemap_hides_the_endpoints_and_the_robots_directive_only(): void
    {
        settings()->set('seo.sitemap_enabled', false, 'boolean');

        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/sitemaps/core-posts-vi-1.xml')->assertNotFound();

        $robots = $this->get('/robots.txt')->getContent();
        $this->assertStringNotContainsString('Sitemap:', $robots);
        $this->assertStringContainsString('Allow: /', $robots, 'the site itself stays crawlable');
        $this->assertTrue(app('cms.seo')->siteIndexable(), 'sitemap exposure is not an indexing policy');

        settings()->set('seo.sitemap_enabled', true, 'boolean');
        $this->assertContains('core-posts-vi-1', $this->indexNames());
    }

    public function test_excluding_a_type_removes_only_its_children_and_never_noindexes_pages(): void
    {
        $post = $this->get('/blog/admin-post');
        $post->assertOk();

        settings()->set('seo.sitemap_excluded_types', ['posts'], 'array');

        $names = $this->indexNames();
        $this->assertNotContains('core-posts-vi-1', $names);
        $this->assertNotContains('core-posts-en-1', $names);
        $this->assertContains('core-home-vi-1', $names, 'home is not a toggleable type');
        $this->get('/sitemaps/core-posts-vi-1.xml')->assertNotFound();

        $page = $this->get('/blog/admin-post')->assertOk();
        $this->assertStringNotContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
        $this->assertDoesNotMatchRegularExpression('/<meta name="robots" content="noindex/', $page->getContent());

        settings()->set('seo.sitemap_excluded_types', [], 'array');
        $this->assertContains('core-posts-vi-1', $this->indexNames(), 're-enabling restores');
    }

    public function test_the_deprecated_flat_projection_honours_type_exclusion(): void
    {
        settings()->set('seo.sitemap_excluded_types', ['posts'], 'array');

        $locs = array_column(app('cms.seo')->sitemap(), 'loc');
        $this->assertNotContains('http://localhost/blog/admin-post', $locs);
        $this->assertContains('http://localhost', array_map(static fn ($l) => rtrim($l, '/'), $locs), 'home kept');

        settings()->set('seo.sitemap_excluded_types', [], 'array');
        $this->assertContains('http://localhost/blog/admin-post', array_column(app('cms.seo')->sitemap(), 'loc'));
    }

    public function test_a_manager_saves_settings_through_the_page(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));

        Livewire::test(SitemapPage::class)
            ->assertOk()
            ->set('data.enabled', false)
            ->set('data.chunk_size', 1500)
            ->set('data.types.posts', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(settings('seo.sitemap_enabled'));
        $this->assertSame(1500, app('cms.sitemap')->chunkSize());
        $this->assertSame(['posts'], app('cms.sitemap_settings')->excludedTypes());
    }

    public function test_a_read_only_viewer_sees_the_page_but_cannot_save(): void
    {
        $this->actingAs($this->userWith('sitemap.view'));

        Livewire::test(SitemapPage::class)
            ->assertOk()
            ->set('data.enabled', false)
            ->call('save')
            ->assertForbidden();

        $this->assertTrue(settings('seo.sitemap_enabled'), 'nothing persisted (seeded default kept)');
    }

    public function test_users_without_sitemap_view_and_guests_are_refused(): void
    {
        $this->actingAs($this->userWithRole('editor'));
        Livewire::test(SitemapPage::class)->assertForbidden();

        $this->actingAs($this->userWithRole('author'));
        Livewire::test(SitemapPage::class)->assertForbidden();

        auth()->logout();
        Livewire::test(SitemapPage::class)->assertForbidden();
    }

    public function test_invalid_values_are_rejected_server_side(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));

        Livewire::test(SitemapPage::class)
            ->set('data.chunk_size', 100)
            ->call('save')
            ->assertHasErrors(['data.chunk_size']);
        $this->assertNull(settings('seo.sitemap_chunk_size'));

        $errors = app('cms.sitemap_settings')->validate([
            'enabled' => 'yes',
            'chunk_size' => 60000,
            'types' => ['posts' => true, 'home' => false, 'evil' => false],
            'providers' => ['core' => false, '../x' => false],
        ], []);
        $this->assertArrayHasKey('enabled', $errors);
        $this->assertArrayHasKey('chunk_size', $errors);
        $this->assertArrayHasKey('types', $errors);
        $this->assertArrayHasKey('providers', $errors);
    }

    public function test_permissions_follow_the_core_role_conventions(): void
    {
        $this->seedRbac();
        $slugs = ['sitemap.view', 'sitemap.manage', 'sitemap.diagnostics', 'sitemap.cache_refresh'];

        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        foreach ($slugs as $slug) {
            $this->assertTrue($admin->permissions()->where('slug', $slug)->exists(), "admin has {$slug}");
            foreach (['editor', 'author', 'subscriber'] as $role) {
                $this->assertFalse(Role::query()->where('slug', $role)->firstOrFail()->permissions()->where('slug', $slug)->exists(), "{$role} lacks {$slug}");
            }
        }
    }
}
