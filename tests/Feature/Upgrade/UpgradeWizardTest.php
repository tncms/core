<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\EnsureUpgradeAccess;
use TheNguyen\CMS\Http\Controllers\UpgradeController;
use TheNguyen\CMS\Upgrade\UpgradeLock;
use TheNguyen\CMS\Upgrade\UpgradeManager;
use TheNguyen\CMS\Upgrade\UpgradeState;
use TheNguyen\CMS\Upgrade\UpgradeStateStore;

/**
 * CORE-UPGRADE-1 — /upgrade wizard: authorization, CSRF, lifecycle and the full
 * HTTP flow (§8/§9/§10/§11/§48).
 *
 * The destructive apply is driven over real HTTP but against a TEMP fixture root
 * (a rebound cms.upgrade manager), never the running worktree, so the wizard,
 * routing, middleware, CSRF and backup gate are exercised end-to-end safely.
 */
final class UpgradeWizardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsUpgradeFixtures;

    private string $tmp;

    /** @var list<string> */
    public array $cleanup = [];

    private string $siteRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/tncms-wiz-'.bin2hex(random_bytes(5));
        @mkdir($this->tmp, 0775, true);
        $this->cleanup[] = $this->tmp;
        $this->siteRoot = $this->tmp.'/site';

        // Register the /upgrade routes for the test (at boot they are installed-gated;
        // the suite boots as not-installed). The file self-registers its group;
        // refresh the name lookup so route() resolves the freshly added routes.
        require base_path('packages/thenguyen/cms-core/routes/upgrade.php');
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    // ---- authorization (§10) -------------------------------------------

    #[Test]
    public function middleware_blocks_uninstalled_site(): void
    {
        $this->bindInstalled(false);
        $res = (new EnsureUpgradeAccess)->handle(Request::create('/upgrade'), fn () => response('ok'));
        $this->assertSame(302, $res->getStatusCode());
    }

    #[Test]
    public function middleware_redirects_anonymous_and_forbids_non_super_admin(): void
    {
        $this->bindInstalled(true);

        // Anonymous → redirect to login.
        $res = (new EnsureUpgradeAccess)->handle(Request::create('/upgrade'), fn () => response('ok'));
        $this->assertSame(302, $res->getStatusCode());

        // Authenticated non-super-admin → 403.
        $this->bindSuperAdmin(false);
        $this->actingAs($this->makeUser());
        try {
            (new EnsureUpgradeAccess)->handle(Request::create('/upgrade'), fn () => response('ok'));
            $this->fail('non-super-admin must be forbidden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function middleware_allows_installed_super_admin(): void
    {
        $this->bindInstalled(true);
        $this->bindSuperAdmin(true);
        $this->actingAs($this->makeUser());

        $passed = false;
        (new EnsureUpgradeAccess)->handle(Request::create('/upgrade'), function () use (&$passed) {
            $passed = true;

            return response('ok');
        });
        $this->assertTrue($passed);
    }

    // ---- lifecycle separation (§9) -------------------------------------

    #[Test]
    public function upgrade_route_is_not_registered_when_uninstalled_at_boot(): void
    {
        // A fresh application (not the test-registered routes) reflects boot-time
        // gating: the suite boots as not-installed, so a real /upgrade GET 404s.
        $freshApp = $this->createApplication();
        $this->assertFalse(
            $freshApp['router']->getRoutes()->hasNamedRoute('cms.upgrade.index'),
            'the /upgrade route must not be registered on an uninstalled site',
        );
    }

    // ---- CSRF (§11) ----------------------------------------------------

    #[Test]
    public function state_changing_posts_are_protected_by_csrf(): void
    {
        // The framework bypasses CSRF while running unit tests, so assert it
        // structurally: every state-changing route runs in the "web" group, and
        // the web group carries the CSRF middleware — so CSRF applies in production.
        $router = $this->app['router'];
        $csrf = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;
        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
        $this->assertContains($csrf, $webGroup, 'the web group must include CSRF protection');

        foreach (['cms.upgrade.package', 'cms.upgrade.backup.run', 'cms.upgrade.start', 'cms.upgrade.recover'] as $name) {
            $route = $router->getRoutes()->getByName($name);
            $this->assertNotNull($route, "route $name must exist");
            $this->assertContains('web', $route->middleware(), "route $name must be in the web group (CSRF)");
        }
    }

    // ---- full HTTP flow against a temp fixture root (§48) --------------

    #[Test]
    public function full_wizard_flow_upgrades_a_fixture_site_over_http(): void
    {
        $this->bindInstalled(true);
        $this->bindSuperAdmin(true);
        $this->actingAs($this->makeUser());

        // Fixture site + package, and a cms.upgrade manager rooted at the fixture.
        $this->buildLiveTree($this->siteRoot, '1.0.0-beta.7.1.15', withObsolete: true, withRecordedOwnership: true);
        $pkgZip = $this->tmp.'/pkg.zip';
        $this->buildPackage($pkgZip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');
        $this->bindFixtureManager();

        // The wizard advances step-by-step over real HTTP POSTs (the GET view
        // renders are checked directly below; a boot-time frontend catch-all
        // otherwise shadows test-registered GET routes but never the POSTs).
        // 1) Package → verify.
        $upload = new \Illuminate\Http\UploadedFile($pkgZip, 'tncms-upgrade.zip', 'application/zip', null, true);
        $this->post('/upgrade/package', ['package' => $upload])
            ->assertRedirect(route('cms.upgrade.systemCheck'));

        // 2) System check passes and advances.
        $this->post('/upgrade/system-check')->assertRedirect(route('cms.upgrade.backup'));

        // 3) Backup — hard gate.
        $this->post('/upgrade/backup')->assertRedirect(route('cms.upgrade.ready'));

        // 4) Ready GET renders, then start.
        $ready = app(UpgradeController::class)->ready();
        $this->assertSame('upgrade.ready', $ready->name());
        $this->post('/upgrade/start')->assertRedirect(route('cms.upgrade.finish'));

        // 5) Finish GET renders completion; the site is upgraded + preserved.
        $finish = app(UpgradeController::class)->finish();
        $this->assertStringContainsString('Upgrade complete', $finish->render());

        $this->assertStringContainsString('1.0.0-beta.7.2.0', file_get_contents($this->siteRoot.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php'));
        $this->assertStringContainsString('keepme', file_get_contents($this->siteRoot.'/.env'));
        $this->assertFileExists($this->siteRoot.'/storage/app/media/photo.txt');
        $this->assertFileExists($this->siteRoot.'/plugins/acme/plugin.json');
        $this->assertFileExists($this->siteRoot.'/themes/company/theme.json');
        $this->assertFileDoesNotExist($this->siteRoot.'/app/Obsolete.php');
    }

    #[Test]
    public function start_is_refused_without_a_verified_backup(): void
    {
        $this->bindInstalled(true);
        $this->bindSuperAdmin(true);
        $this->actingAs($this->makeUser());

        $this->buildLiveTree($this->siteRoot, '1.0.0-beta.7.1.15');
        $pkgZip = $this->tmp.'/pkg.zip';
        $this->buildPackage($pkgZip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');
        $mgr = $this->bindFixtureManager();

        // Upload + verify only (NO backup).
        $upload = new \Illuminate\Http\UploadedFile($pkgZip, 'tncms-upgrade.zip', 'application/zip', null, true);
        $this->post('/upgrade/package', ['package' => $upload]);

        // Directly POST start — must not mutate; state stays pre-backup.
        $this->post('/upgrade/start')->assertRedirect(route('cms.upgrade.finish'));
        $latest = $mgr->latest();
        $this->assertNotSame(UpgradeState::COMPLETED, $latest['status'] ?? null);
        // The live Core file was NOT replaced.
        $this->assertStringContainsString('1.0.0-beta.7.1.15', file_get_contents($this->siteRoot.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php'));
    }

    // ---- helpers --------------------------------------------------------

    private function makeUser(): object
    {
        $model = config('auth.providers.users.model', \App\Models\User::class);

        return $model::query()->create([
            'name' => 'Root', 'email' => 'root'.bin2hex(random_bytes(3)).'@example.test', 'password' => 'password123',
        ]);
    }

    private function bindInstalled(bool $installed): void
    {
        $mock = \Mockery::mock(\TheNguyen\CMS\Services\InstallerManager::class)->makePartial();
        $mock->shouldReceive('isInstalled')->andReturn($installed);
        $this->app->instance('cms.installer', $mock);
    }

    private function bindSuperAdmin(bool $is): void
    {
        $mock = \Mockery::mock(\TheNguyen\CMS\Services\PermissionManager::class)->makePartial();
        $mock->shouldReceive('isSuperAdmin')->andReturn($is);
        $this->app->instance('cms.permission', $mock);
    }

    /** Bind cms.upgrade to a manager rooted at the fixture, DB-free + noop seams. */
    private function bindFixtureManager(): UpgradeManager
    {
        $root = $this->siteRoot;
        $mgr = new class($root) extends UpgradeManager {
            public function __construct(string $root)
            {
                $store = new UpgradeStateStore($root.'/storage/app/upgrades');
                $lock = new UpgradeLock($root.'/storage/app/upgrades');
                // Use the app's (sqlite) connection: reachable so preflight/health
                // pass, but non-dumpable so no MySQL dump is attempted in-test.
                parent::__construct($root, $store, $lock, static fn () => \Illuminate\Support\Facades\DB::connection()->getPdo());
            }

            public function isInstalled(): bool
            {
                return true;
            }

            protected function enterMaintenance(): void {}

            protected function exitMaintenance(): void {}

            protected function runMigrations(): void {}

            protected function refreshCaches(array $model): void {}

            protected function healthChecksDatabase(): bool
            {
                return false;
            }
        };
        $this->app->instance('cms.upgrade', $mgr);

        return $mgr;
    }
}
