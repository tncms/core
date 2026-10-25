<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Services\TrustedComponentRegistry;

/**
 * Disposable fixture plugins for CORE-EXTENSION-TRUST S2 tests.
 *
 * Like the S1 fixtures, every plugin is a real directory under a per-process
 * temp plugins root OUTSIDE the repository, with real class files, so owner and
 * call-site identity come from genuine realpaths (no identity test seam):
 *
 *   S2Fixture\{Studly}\Provider — a configurable TrustedComponentProvider
 *   S2Fixture\{Studly}\Plugin   — "the plugin's own code": registers and invokes
 *
 * Class files are written once per process (PHP cannot reload a class); the
 * provider is configured through its constructor instead.
 */
trait TrustedComponentFixtures
{
    /** A multipart, CSRF-protected fixture form (the GAP-4 reproduction shape). */
    public const FORM = '<section class="s2-form"><form method="post" action="/s2-fixture/submit" enctype="multipart/form-data">'
        .'<input type="hidden" name="_token" value="{csrf}">'
        .'<label for="s2-name">Name</label><input type="text" id="s2-name" name="name" required>'
        .'<label for="s2-file">File</label><input type="file" id="s2-file" name="attachment">'
        .'<label for="s2-msg">Message</label><textarea id="s2-msg" name="message" rows="3"></textarea>'
        .'<label for="s2-topic">Topic</label><select id="s2-topic" name="topic"><option value="a">A</option><option value="b" selected>B</option></select>'
        .'<button type="submit">Send</button></form></section>';

    /** The read-only rendering of the same form (preview / editor). */
    public const FORM_READONLY = '<section class="s2-form"><form method="post"><fieldset disabled>'
        .'<label for="s2-name">Name</label><input type="text" id="s2-name" name="name">'
        .'<button type="submit">Send</button></fieldset></form></section>';

    protected function componentFixtureRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s2-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function useComponentFixturePlugins(): void
    {
        config(['cms.paths.plugins' => $this->componentFixtureRoot()]);
        app('cms.extension')->flushRegistry();
    }

    /** Write a fixture plugin (manifest + Provider + Plugin classes) and return its dir. */
    protected function writeComponentPlugin(string $slug, array $manifest = []): string
    {
        $dir = $this->componentFixtureRoot().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'src');

        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', (string) json_encode([
            'name' => 'S2 Fixture '.$slug,
            'slug' => $slug,
            'version' => '1.0.0',
            'author' => 'S2 Tests',
        ] + $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        $namespace = $this->componentNamespace($slug);
        $provider = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Provider.php';
        $plugin = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Plugin.php';

        if (! File::exists($provider)) {
            File::put($provider, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TheNguyen\CMS\Contracts\TrustedComponentProvider;
use TheNguyen\CMS\Support\TrustedComponents\TrustedComponentPolicy;
use TheNguyen\CMS\Support\TrustedComponents\TrustedFormTarget;
use TheNguyen\CMS\Support\TrustedComponents\TrustedRenderContext;

class Provider implements TrustedComponentProvider
{
    /** @var list<array{mode: string, invoker: string, props: array}> */
    public array \$calls = [];

    public function __construct(
        private string \$key = 'form',
        private string \$html = '<p>fixture</p>',
        private array \$contexts = ['public'],
        private array \$invokers = [],
        private ?string \$permission = null,
        private array \$data = [],
        private string \$behaviour = '',
        private array \$targets = [],
    ) {}

    public function key(): string
    {
        return \$this->key;
    }

    public function policy(): TrustedComponentPolicy
    {
        \$args = [
            'contexts' => \$this->contexts,
            'invokers' => \$this->invokers,
            'permission' => \$this->permission,
            'dataAttributes' => \$this->data,
        ];

        // S2-R1 form targets: [route, methods, parameters] specs or raw values.
        // Skipped where the contract does not exist (the RED base).
        if (\$this->targets !== [] && class_exists(TrustedFormTarget::class)) {
            \$args['formTargets'] = array_map(
                fn (\$t) => is_array(\$t) && array_is_list(\$t) && is_string(\$t[0] ?? null) ? new TrustedFormTarget(...\$t) : \$t,
                \$this->targets,
            );
        }

        return new TrustedComponentPolicy(...\$args);
    }

    public function render(TrustedRenderContext \$context, array \$props): string
    {
        \$this->calls[] = ['mode' => \$context->mode, 'invoker' => \$context->invoker, 'props' => \$props];

        switch (\$this->behaviour) {
            case 'throw':
                throw new \RuntimeException('S2-SECRET-EXCEPTION-MESSAGE db_password=hunter2');
            case 'write':
                DB::table('s2_fixture_writes')->insert(['v' => 1]);
                break;
            case 'write-second':
                DB::connection('s2second')->statement('create table s2_second (a integer)');
                break;
            case 'http':
                Http::get('https://s2-fixture.invalid/side-effect');
                break;
            case 'nested':
                return cms_trusted_component(\$context->componentId);
            case 'enqueue':
                cms_enqueue_trusted_script(\$this, 'turnstile');
                break;
            case 'context':
                return '<p class="ctx">'.e(json_encode([\$context->mode, \$context->invoker, \$context->locale, \$context->isAuthenticated, \$context->userId])).'</p>';
        }

        \$out = strtr(\$this->html, ['{csrf}' => csrf_token(), '{mode}' => \$context->mode, '{locale}' => \$context->locale]);

        \$out = (string) preg_replace_callback('/\{url:([A-Za-z0-9_.:-]+)\}/', fn (\$m) => e(route(\$m[1])), \$out);
        \$out = (string) preg_replace_callback('/\{old:([a-z_]+)\}/', fn (\$m) => e((string) old(\$m[1], '')), \$out);
        \$out = (string) preg_replace_callback('/\{error:([a-z_]+)\}/', fn (\$m) => e((string) (session('errors')?->first(\$m[1]) ?? '')), \$out);

        foreach (\$props as \$name => \$value) {
            if (is_scalar(\$value)) {
                \$out = str_replace('{prop:'.\$name.'}', e((string) \$value), \$out);
            }
        }

        return \$out;
    }
}

PHP);
        }

        if (! File::exists($plugin)) {
            File::put($plugin, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use TheNguyen\CMS\Contracts\TrustedComponentProvider;

/** Stands in for the plugin's own code (service provider, shortcode, filter). */
final class Plugin
{
    public function register(TrustedComponentProvider \$provider): bool
    {
        return app('cms.trusted_components')->register(\$provider);
    }

    /** Spoof attempt laundered through the container (vendor frames in between). */
    public function registerViaContainer(TrustedComponentProvider \$provider): bool
    {
        return app()->call([app('cms.trusted_components'), 'register'], ['provider' => \$provider]);
    }

    public function invoke(string \$id, array \$props = [], ?string \$mode = null): string
    {
        return cms_trusted_component(\$id, \$props, \$mode);
    }

    public function invokeViaContainer(string \$id): string
    {
        return app()->call([app('cms.trusted_components'), 'render'], ['component' => \$id]);
    }

    /**
     * A web route owned by THIS plugin: its action closure is defined in this
     * file, so Core derives the route owner from the plugin directory.
     */
    public function route(array \$methods, string \$uri, string \$name, \Closure \$handler, array \$middleware = ['web']): \Illuminate\Routing\Route
    {
        \$route = \Illuminate\Support\Facades\Route::match(\$methods, \$uri, fn (\Illuminate\Http\Request \$request) => \$handler(\$request))
            ->middleware(\$middleware)
            ->name(\$name);
        app('router')->getRoutes()->refreshNameLookups();

        return \$route;
    }

    /** Typical public seam: a shortcode whose callback requests the component. */
    public function shortcode(string \$tag, string \$id): void
    {
        add_shortcode(\$tag, fn (\$atts = []) => cms_trusted_component(\$id, is_array(\$atts) ? \$atts : []));
    }

    /** Typical PB-like seam: append the component from the body filter. */
    public function bodyFilter(string \$id, string \$onlyTitle): void
    {
        add_filter('cms.content.body', function (\$body, \$content) use (\$id, \$onlyTitle) {
            return \$content->translatedTitle(app()->getLocale()) === \$onlyTitle || \$content->translatedTitle('en') === \$onlyTitle
                ? \$body.cms_trusted_component(\$id)
                : \$body;
        }, 10, 2);
    }
}

PHP);
        }

        // S2-R1: controller route handlers owned by this plugin (one real, one inherited from vendor code).
        $controller = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Controller.php';
        $inherited = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Inherited.php';

        if (! File::exists($controller)) {
            File::put($controller, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/** A route handler whose code lives in this plugin. */
final class Controller
{
    public function __invoke(): mixed
    {
        return response('ok');
    }

    public function store(): mixed
    {
        return redirect('/contact');
    }
}

PHP);
        }

        if (! File::exists($inherited)) {
            File::put($inherited, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/** Declared in this plugin, but its __invoke() is vendor code. */
class Inherited extends \Illuminate\Routing\RedirectController
{
}

PHP);
        }

        app('cms.extension')->flushRegistry();

        return $dir;
    }

    protected function componentNamespace(string $slug): string
    {
        return 'S2Fixture\\'.Str::studly(str_replace('.', '-', $slug));
    }

    private function loadComponentClass(string $slug, string $class): string
    {
        $fqcn = $this->componentNamespace($slug).'\\'.$class;

        if (! class_exists($fqcn, false)) {
            require_once $this->componentFixtureRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$class.'.php';
        }

        return $fqcn;
    }

    /** A fixture plugin's Provider instance (owned by $slug through its class file). */
    protected function provider(string $slug, mixed ...$args): object
    {
        $fqcn = $this->loadComponentClass($slug, 'Provider');

        return new $fqcn(...$args);
    }

    /** A fixture plugin's own code. */
    protected function pluginCode(string $slug): object
    {
        $fqcn = $this->loadComponentClass($slug, 'Plugin');

        return new $fqcn;
    }

    protected function activateComponentPlugins(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        }

        app('cms.extension')->flushRegistry();
    }

    protected function components(): TrustedComponentRegistry
    {
        return app('cms.trusted_components');
    }

    /**
     * Put a fresh request in the container and switch on its slot resolver, as
     * the `cms.trusted_slots` middleware does at the start of a request.
     */
    protected function freshSlotRequest(string $uri = '/', array $attributes = []): Request
    {
        $request = Request::create($uri);

        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        app()->instance('request', $request);
        \TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault::forRequest($request)->activate();

        return $request;
    }

    /**
     * Register a GET test route AHEAD of the frontend catch-all `/{slug}` (which
     * would otherwise swallow any GET route added after boot).
     *
     * @param  list<string>  $middleware
     */
    protected function firstGetRoute(string $uri, \Closure $action, array $middleware = ['web', 'cms.trusted_slots']): void
    {
        $this->promoteRoute(app('router')->get($uri, $action)->middleware($middleware));
    }

    /** Move an already-registered route ahead of every other route (and the catch-all). */
    protected function promoteRoute(\Illuminate\Routing\Route $route): void
    {
        $router = app('router');
        $collection = new \Illuminate\Routing\RouteCollection;
        $collection->add($route);

        foreach ($router->getRoutes() as $existing) {
            if ($existing !== $route) {
                $collection->add($existing);
            }
        }

        $router->setRoutes($collection);
    }

    /** Extract the 32-hex token of every exact placeholder in a string. */
    protected function slotTokens(string $html): array
    {
        preg_match_all('#<div class="tncms-slot">TNCMSSLOT([0-9a-f]{32})</div>#', $html, $m);

        return $m[1];
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeComponentFixtureFilesystem(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s2-plugins-'.getmypid();

        // Classes already loaded stay loaded; later tests simply rewrite the files.
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}
