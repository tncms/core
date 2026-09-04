<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORE-FRONTEND-1 — themed frontend error pages, end-to-end.
 *
 * Drives real HTTP requests through the FrontendErrorResponder render callback
 * registered in bootstrap/app.php. Proves the hard 404 status invariant, themed
 * branded output, the noindex SEO policy, safe (non-leaking) content, and strict
 * isolation of API/JSON, admin, installer and upgrade responses.
 */
class FrontendErrorPageTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = '/this-page-should-never-exist-abc123';

    public function test_missing_frontend_url_returns_http_404(): void
    {
        $this->get(self::MISSING)->assertStatus(404);
    }

    public function test_404_is_never_a_redirect_to_homepage(): void
    {
        $response = $this->get(self::MISSING);

        $response->assertStatus(404);
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_404_renders_themed_branded_page_with_controls(): void
    {
        $response = $this->get(self::MISSING);

        $response->assertStatus(404);
        // The default theme's SPECIALIZED errors/404 view wins over the generic tier.
        $response->assertSee('tn-error-404', false);
        // Status is surfaced, a canonical Search action and a home link are present.
        $response->assertSee('404');
        $response->assertSee('name="q"', false);
        $response->assertSee('role="search"', false);
        $response->assertSee(route('cms.home'), false);
        $response->assertSee(route('cms.search'), false);
    }

    public function test_404_is_noindex(): void
    {
        $this->get(self::MISSING)
            ->assertStatus(404)
            ->assertSee('name="robots"', false)
            ->assertSee('noindex', false);
    }

    public function test_404_does_not_leak_exception_internals(): void
    {
        $response = $this->get(self::MISSING);

        $response->assertStatus(404);
        $response->assertDontSee('NotFoundHttpException');
        $response->assertDontSee('vendor\\laravel');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('FrontendController');
    }

    public function test_json_request_gets_json_404_not_themed_html(): void
    {
        $response = $this->getJson(self::MISSING);

        $response->assertStatus(404);
        // Framework JSON error contract is preserved — no themed HTML markup.
        $response->assertHeader('content-type', 'application/json');
        $response->assertDontSee('role="search"', false);
    }

    public function test_admin_path_404_is_not_themed(): void
    {
        // A missing admin URL must stay with the framework/Filament handler.
        $response = $this->get('/admin/this-admin-page-does-not-exist-xyz');

        $response->assertStatus(404);
        $response->assertDontSee('tn-error-title', false);
        $response->assertDontSee('Search this site');
    }

    public function test_installer_path_404_is_not_themed(): void
    {
        $response = $this->get('/install/this-install-step-does-not-exist');

        $response->assertStatus(404);
        $response->assertDontSee('tn-error-title', false);
    }

    public function test_upgrade_path_404_is_not_themed(): void
    {
        $response = $this->get('/upgrade/this-upgrade-step-does-not-exist');

        $response->assertStatus(404);
        $response->assertDontSee('tn-error-title', false);
    }
}
