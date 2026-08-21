<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Http\Middleware\RedirectToInstaller;
use TheNguyen\CMS\Services\InstallerManager;
use Tests\TestCase;

/**
 * CORE-INSTALLER-2 — first-run HTTP behaviors: pre-commit steps never touch .env,
 * DB secrets are never leaked, a fresh root leads to the wizard, and a locked site
 * cannot re-run the installer.
 */
final class InstallerHttpFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never resolve the (possibly faked) installer here — remove the marker by
        // its canonical path so a test that binds a fake cms.installer still cleans up.
        @unlink(storage_path('app'.DIRECTORY_SEPARATOR.'tncms-installed'));
        parent::tearDown();
    }

    #[Test]
    public function pre_commit_wizard_steps_do_not_modify_the_env(): void
    {
        $envPath = base_path('.env');
        $before = @file_get_contents($envPath);

        $this->get('/install')->assertSuccessful();
        $this->get('/install/requirements')->assertSuccessful();

        $this->assertSame($before, @file_get_contents($envPath), 'Welcome/requirements must not write .env.');
    }

    #[Test]
    public function the_database_test_neither_persists_env_nor_leaks_the_password(): void
    {
        $envPath = base_path('.env');
        $before = @file_get_contents($envPath);
        $password = 'S3cretDbPassw0rd!';

        // A connection-refused port fails fast without any persistence.
        $result = app(InstallerManager::class)->testDatabaseConnection([
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'x',
            'username' => 'u',
            'password' => $password,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString($password, (string) $result['error'], 'The DB error must never contain the password.');
        $this->assertSame($before, @file_get_contents($envPath), 'The DB test must not write .env.');
    }

    #[Test]
    public function the_env_file_is_not_served_over_http(): void
    {
        // Disable debug so a no-DB error page cannot echo environment values; the
        // webserver-level protection (public docroot + .htaccess) is proven live.
        config(['app.debug' => false]);

        $response = $this->get('/.env');

        // No application route serves the .env file as its contents.
        $this->assertNotSame(200, $response->getStatusCode(), '.env must never be served with 200.');
        $this->assertStringNotContainsString('APP_NAME=', (string) $response->getContent());
        $this->assertStringNotContainsString('base64:', (string) $response->getContent());
    }

    #[Test]
    public function a_locked_site_cannot_re_run_the_installer(): void
    {
        $installer = app(InstallerManager::class);
        @file_put_contents($installer->markerPath(), 'installed');

        $this->assertTrue($installer->isInstalled());

        // Every step except /finish is behind RedirectIfInstalled → home.
        $this->get('/install/database')->assertRedirect('/');
        $this->post('/install/run')->assertRedirect('/');
    }

    #[Test]
    public function a_fresh_extract_root_leads_to_the_installer(): void
    {
        $this->app->instance('cms.installer', new class
        {
            public function isInstalled(): bool
            {
                return false;
            }

            public function hasCommittedEnv(): bool
            {
                return false; // Fresh extract: no committed .env yet.
            }
        });

        $response = (new RedirectToInstaller)->handle(
            Request::create('/'),
            static fn (): Response => new Response('frontend'),
        );

        $this->assertTrue($response->isRedirect(), 'A fresh root must redirect.');
        $this->assertStringContainsString('/install', (string) $response->headers->get('Location'));
    }

    #[Test]
    public function a_configured_site_root_is_never_hijacked(): void
    {
        $this->app->instance('cms.installer', new class
        {
            public function isInstalled(): bool
            {
                return false;
            }

            public function hasCommittedEnv(): bool
            {
                return true; // Configured (committed .env, e.g. the test harness / a CLI install).
            }
        });

        $response = (new RedirectToInstaller)->handle(
            Request::create('/'),
            static fn (): Response => new Response('frontend'),
        );

        $this->assertFalse($response->isRedirect(), 'A configured site must pass through untouched.');
        $this->assertSame('frontend', $response->getContent());
    }
}
