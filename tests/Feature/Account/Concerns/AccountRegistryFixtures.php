<?php

declare(strict_types=1);

namespace Tests\Feature\Account\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Disposable fixture plugins for CORE-P12-2/3 account registry tests.
 *
 * Plugins are written to a per-process temp root OUTSIDE the repository, which
 * the test points `cms.paths.plugins` at. Each fixture has a real class file
 * (`P12AccFixture\{Studly}\Nav`), so the registering plugin is derived from a
 * genuine on-disk call site: there is no identity test seam.
 */
trait AccountRegistryFixtures
{
    protected function fixtureRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-p12acc-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function useFixturePlugins(): void
    {
        config(['cms.paths.plugins' => $this->fixtureRoot()]);
        app('cms.extension')->flushRegistry();
    }

    protected function writePlugin(string $slug): void
    {
        $dir = $this->fixtureRoot().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'src');
        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', json_encode([
            'name' => 'P12 account fixture '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'P12 Tests',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        $class = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Nav.php';

        if (! File::exists($class)) {
            $namespace = 'P12AccFixture\\'.Str::studly($slug);
            File::put($class, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class Nav
{
    /** @param  array<string, mixed>  \$descriptor */
    public function register(array \$descriptor): bool
    {
        return app('cms.account.extensions')->registerNavigation(\$descriptor);
    }

    /** Same call laundered through the container, so vendor frames sit in between. */
    public function registerViaContainer(array \$descriptor): bool
    {
        return app()->call([app('cms.account.extensions'), 'registerNavigation'], ['descriptor' => \$descriptor]);
    }

    /** Runs code it did not define (e.g. a theme or host listener inside this plugin's hook). */
    public function run(callable \$callback): mixed
    {
        return \$callback();
    }

    /** A legacy filter callback owned by this plugin. */
    public function filter(callable \$callback): void
    {
        add_filter('cms.account.navigation_items', \$callback, 10, 2);
    }
}

PHP);
        }

        app('cms.extension')->flushRegistry();
    }

    protected function nav(string $slug): object
    {
        $class = 'P12AccFixture\\'.Str::studly($slug).'\\Nav';

        if (! class_exists($class, false)) {
            require_once $this->fixtureRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Nav.php';
        }

        return new $class;
    }

    protected function activate(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $this->writePlugin($slug);
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        }

        app('cms.extension')->flushRegistry();
    }

    protected function deactivate(string $slug): void
    {
        $this->assertTrue(app('cms.extension')->deactivatePlugin($slug), "deactivate {$slug}");
        app('cms.extension')->flushRegistry();
    }

    /**
     * Plugin-style account routes: canonical + `.localized` twin, behind Core's account
     * middleware, like Toplist's. `{name}.open` is a public route (no cms.auth).
     */
    protected function accountRoutes(string $name, string $path): void
    {
        $define = static function (string $suffix) use ($name, $path): void {
            Route::middleware(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'])->group(static function () use ($suffix, $name, $path): void {
                Route::get($path, static fn () => view('acctprobe::page'))->name($name.$suffix);
                Route::get($path.'/{id}/edit', static fn () => 'edit')->name($name.'.edit'.$suffix);
                Route::get($path.'/restricted', static function () {
                    abort_unless(cms_can('posts.create'), 403);

                    return 'restricted';
                })->name($name.'.restricted'.$suffix);
            });
            Route::middleware(['web', 'cms.locale'])->get($path.'/open', static fn () => 'open')->name($name.'.open'.$suffix);
        };

        \Illuminate\Support\Facades\View::addNamespace('acctprobe', base_path('tests/Fixtures/account-plugin-probe'));
        $define('');
        Route::prefix('{locale}')->where(['locale' => 'en'])->middleware('cms.canonical-locale')->group(static fn () => $define('.localized'));
        // Like a plugin's routes, these must match before Core's frontend {slug} catch-all,
        // which is already registered in a booted test app: put them first.
        $router = app('router');
        $all = $router->getRoutes()->getRoutes();
        $mine = array_filter($all, static fn ($r) => str_starts_with((string) $r->getName(), $name));
        $ordered = new \Illuminate\Routing\RouteCollection;
        foreach ([...$mine, ...array_diff_key($all, $mine)] as $route) {
            $ordered->add($route);
        }
        $router->setRoutes($ordered);
    }
}
