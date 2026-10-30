<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Disposable fixtures for CORE-PLATFORM-SEAMS-R1.
 *
 * Plugins are real directories under a per-process temp plugins root OUTSIDE
 * the repository, booted through Core's real ExtensionManager, so identity
 * comes from genuine realpaths. Themes follow the existing suite convention
 * (themes/{slug} under the app, deleted in tearDown).
 *
 * A fixture plugin (namespace Plugins\{Studly}) has:
 *   - resources/views/public/{page,card}.blade.php   (own views)
 *   - a provider that, when `routes` is set, registers a PluginRouteDictionarySource
 *     from plugin code in register() and routes generated from the composed
 *     Dictionary in routes/web.php (the Lottery P05 shape).
 */
trait PlatformSeamFixtures
{
    /** @var list<string> */
    private array $seamThemes = [];

    protected function seamRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-seam-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function useSeamPluginsRoot(): void
    {
        config(['cms.paths.plugins' => $this->seamRoot()]);
        app('cms.extension')->flushRegistry();
    }

    /**
     * @param  array<string, array<string, string>>  $segments  route key => [locale => segment]
     */
    protected function writeSeamPlugin(string $slug, array $segments = [], ?string $claimAs = null): void
    {
        $dir = $this->seamRoot().DIRECTORY_SEPARATOR.$slug;
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir.'/src');
        File::ensureDirectoryExists($dir.'/resources/views/public');
        File::ensureDirectoryExists($dir.'/routes');

        $studly = Str::studly($slug);
        $ns = 'Plugins\\'.$studly;

        File::put($dir.'/plugin.json', (string) json_encode([
            'name' => 'Seam '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Seam Tests',
            'providers' => [$ns.'\\'.$studly.'ServiceProvider'],
        ], JSON_UNESCAPED_SLASHES));

        File::put($dir.'/resources/views/public/page.blade.php', 'PLUGIN-PAGE|'.$slug.'|{{ app()->getLocale() }}|@include("'.$slug.'::public.card")');
        File::put($dir.'/resources/views/public/card.blade.php', 'PLUGIN-CARD|'.$slug);

        $payload = var_export($segments, true);
        $sourceId = var_export($claimAs ?? $slug, true);
        File::put($dir.'/src/OwnerGuard.php', \Tests\Support\ContentFixtures::allowAllGuardSource($ns));

        File::put($dir.'/src/'.$studly.'ServiceProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;

final class {$studly}ServiceProvider extends ServiceProvider
{
    public const SEGMENTS = {$payload};

    public static ?string \$registrationError = null;

    /** Plugin code creating owned content (CORE-CONTENT-EXT-R1 creation context). */
    public static function own(array \$data): \TheNguyen\CMS\Models\Content
    {
        return app('cms.content_ownership')->createOwned(\$data);
    }

    /** Owned content is governed by the owner's guard (CORE-UNIFIED-OWNERSHIP-R1). */
    public function boot(): void
    {
        require_once __DIR__.'/OwnerGuard.php';
        \$this->app->make('cms.content.guards')->register(new OwnerGuard);
    }

    public function register(): void
    {
        if (self::SEGMENTS === []) {
            return;
        }

        try {
            \$this->app->make(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource({$sourceId}, self::SEGMENTS));
        } catch (\Throwable \$e) {
            self::\$registrationError = \$e->getMessage();
        }
    }
}

PHP);

        // Routes generated from the composed Dictionary (Lottery P05 shape): one
        // route per public locale, default unprefixed, others under /{locale}.
        File::put($dir.'/routes/web.php', <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

\$keys = array_keys({$ns}\\{$studly}ServiceProvider::SEGMENTS);
if (\$keys === []) {
    return;
}
\$languages = app('cms.language');
\$dictionary = app('cms.localization.dictionary');
foreach (\$languages->getPublicLocales() ?: [\$languages->defaultCode()] as \$locale) {
    \$segment = \$dictionary->projectSegment(RouteKey::of(\$keys[0]), \$locale);
    \$prefixed = \$locale !== \$languages->defaultCode();
    \$group = Route::middleware(['cms.locale', 'cms.canonical-locale']);
    if (\$prefixed) {
        \$group = \$group->prefix('{locale}')->whereIn('locale', [\$locale]);
    }
    \$group->group(function () use (\$segment, \$locale) {
        Route::get(\$segment.'/{region}.html', fn () => response(view('{$slug}::public.page')->render()))
            ->name('{$slug}.region.'.\$locale);
    });
}

PHP);
    }

    /**
     * A fixture plugin whose provider runs arbitrary register()/boot() bodies
     * (CORE-PLATFORM-SEAMS-R2 lifecycle tests). Bodies may use $app and append to
     * the provider's static `$log`; routes/web.php logs 'routes' and adds one route.
     */
    protected function writeLifecyclePlugin(string $slug, string $registerBody, string $bootBody = ''): void
    {
        $dir = $this->seamRoot().DIRECTORY_SEPARATOR.$slug;
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir.'/src');
        File::ensureDirectoryExists($dir.'/routes');
        $studly = Str::studly($slug);
        $ns = 'Plugins\\'.$studly;
        $class = $studly.'ServiceProvider';

        File::put($dir.'/plugin.json', (string) json_encode([
            'name' => 'Lifecycle '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Seam Tests',
            'providers' => [$ns.'\\'.$class],
        ], JSON_UNESCAPED_SLASHES));

        File::put($dir.'/src/'.$class.'.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;

final class {$class} extends ServiceProvider
{
    /** @var list<string> */
    public static array \$log = [];

    public function register(): void
    {
        \$app = \$this->app;
        {$registerBody}
    }

    public function boot(): void
    {
        \$app = \$this->app;
        {$bootBody}
    }
}

PHP);

        File::put($dir.'/routes/web.php', <<<PHP
<?php

use Illuminate\Support\Facades\Route;

{$ns}\\{$class}::\$log[] = 'routes';
Route::get('lifecycle-{$slug}', fn () => 'ROUTE-{$slug}')->name('lifecycle.{$slug}');

PHP);
    }

    protected function bootSeamPlugins(string ...$slugs): void
    {
        $ext = app('cms.extension');
        $ext->flushRegistry();

        foreach ($slugs as $slug) {
            $this->assertTrue($ext->activatePlugin($slug), "activate {$slug}");
        }

        $ext->flushRegistry();
        $ext->bootActivePlugins();
        \Tests\Support\ContentFixtures::signInOwnerActor();
    }

    /**
     * Create (and optionally activate) a fixture theme with plugin overrides.
     *
     * @param  array<string, string>  $files  path under views/ => contents
     */
    protected function makeSeamTheme(string $slug, array $files = [], ?string $parent = null, bool $activate = true): string
    {
        $dir = base_path('themes/'.$slug);
        $this->seamThemes[] = $slug;
        File::deleteDirectory($dir);

        foreach (['layouts/master', 'pages/page', 'posts/post', 'archives/index'] as $view) {
            File::ensureDirectoryExists(dirname($dir.'/views/'.$view));
            File::put($dir.'/views/'.$view.'.blade.php', strtoupper($slug).' '.$view);
        }

        File::put($dir.'/theme.json', (string) json_encode(array_filter([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't', 'parent' => $parent,
        ])));

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($dir.'/views/'.$path));
            File::put($dir.'/views/'.$path, $contents);
        }

        if ($activate) {
            app('cms.settings')->set('theme.active', $slug);
            settings()->clearCache();
            app('cms.theme')->flushRegistry();
            app('cms.theme')->registerViews();
        }

        return $dir;
    }

    protected function tearDownSeamThemes(): void
    {
        foreach ($this->seamThemes as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }
    }
}
