<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Services\TrustedExternalScriptRegistry;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptDeclaration;

/**
 * Disposable fixture plugins for CORE-EXTENSION-TRUST S1 tests.
 *
 * Plugins are written to a per-process temp root OUTSIDE the repository, which
 * the test points `cms.paths.plugins` at. Each fixture gets a real class file
 * (`S1Fixture\{Studly}\Caller`) so identity is derived from a genuine on-disk
 * realpath — there is no identity test seam. Class files are written once per
 * process (PHP cannot reload a class); manifests are rewritten freely.
 */
trait TrustedScriptFixtures
{
    public const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    protected function fixtureRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s1-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function useFixturePlugins(): void
    {
        config(['cms.paths.plugins' => $this->fixtureRoot()]);
        app('cms.extension')->flushRegistry();
    }

    /** A valid Turnstile-like declaration array. */
    protected function turnstileEntry(array $overrides = []): array
    {
        return array_replace([
            'url' => self::TURNSTILE,
            'position' => 'footer',
            'attributes' => ['async' => true, 'defer' => true],
            'csp' => [
                'script-src' => ['https://challenges.cloudflare.com'],
                'frame-src' => ['https://challenges.cloudflare.com'],
            ],
            'purpose' => 'Cloudflare Turnstile challenge for contact forms',
        ], $overrides);
    }

    /**
     * Write (or rewrite) a fixture plugin. $trustedScripts may be an array (JSON
     * encoded) or a raw JSON fragment string; null omits the block.
     */
    protected function writePlugin(string $slug, array|string|null $trustedScripts = [], ?string $manifestSlug = null, ?string $directory = null): string
    {
        $dir = $this->fixtureRoot().DIRECTORY_SEPARATOR.($directory ?? $slug);
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'src');

        $base = [
            'name' => 'S1 Fixture '.$slug,
            'slug' => $manifestSlug ?? $slug,
            'version' => '1.0.0',
            'author' => 'S1 Tests',
        ];

        $json = rtrim((string) json_encode($base, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "}\n\r ");

        if ($trustedScripts !== null) {
            $fragment = is_string($trustedScripts)
                ? $trustedScripts
                : (string) json_encode($trustedScripts === [] ? new \stdClass : $trustedScripts, JSON_UNESCAPED_SLASHES);
            $json .= ",\n    \"trusted_scripts\": ".$fragment;
        }

        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', $json."\n}\n");

        $class = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Caller.php';

        if (! File::exists($class)) {
            $namespace = 'S1Fixture\\'.Str::studly(str_replace('.', '-', basename($dir)));
            File::put($class, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class Caller
{
    public function enqueue(string \$key): bool
    {
        return cms_enqueue_trusted_script(\$this, \$key);
    }

    /** Plugin code handing ANOTHER plugin's object to the registry (spoof attempt). */
    public function enqueueAs(object \$foreign, string \$key): bool
    {
        return app('cms.trusted_scripts')->enqueue(\$foreign, \$key);
    }

    /** Same spoof, laundered through the container so vendor frames sit in between. */
    public function enqueueAsViaContainer(object \$foreign, string \$key): bool
    {
        return app()->call([app('cms.trusted_scripts'), 'enqueue'], ['caller' => \$foreign, 'key' => \$key]);
    }

    /** Legitimate: this plugin triggers ANOTHER plugin's own code, which enqueues for itself. */
    public function triggerOther(object \$other, string \$key): bool
    {
        return \$other->enqueue(\$key);
    }
}

PHP);
        }

        app('cms.extension')->flushRegistry();

        return $dir;
    }

    /** Instantiate a fixture plugin's Caller (loaded from the fixture's own file). */
    protected function caller(string $slug, ?string $directory = null): object
    {
        $dir = $this->fixtureRoot().DIRECTORY_SEPARATOR.($directory ?? $slug);
        $class = 'S1Fixture\\'.Str::studly(str_replace('.', '-', basename($dir))).'\\Caller';

        if (! class_exists($class, false)) {
            require_once $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Caller.php';
        }

        return new $class;
    }

    protected function activate(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        }

        app('cms.extension')->flushRegistry();
    }

    /** Simulate the start of a new HTTP request (fresh request-scoped state). */
    protected function freshRequest(string $uri = '/'): void
    {
        app()->instance('request', Request::create($uri));
    }

    protected function registry(): TrustedExternalScriptRegistry
    {
        return app('cms.trusted_scripts');
    }

    protected function declarationFor(string $owner, string $key): TrustedScriptDeclaration
    {
        $declaration = $this->registry()->declaration($owner, $key);
        $this->assertNotNull($declaration, "declaration {$owner}/{$key}");

        return $declaration;
    }

    protected function approve(string $owner, string $key, ?int $actor = null): void
    {
        app('cms.trusted_script_approvals')->approve($this->declarationFor($owner, $key), $actor);
    }

    protected function cleanFixturePlugins(): void
    {
        $root = $this->fixtureRoot();

        foreach (File::directories($root) as $dir) {
            File::delete($dir.DIRECTORY_SEPARATOR.'plugin.json');
        }
    }

    /**
     * Remove every fixture directory this process created. Junctions/symlinks are
     * unlinked as links first (never followed), then the real trees are deleted.
     * Classes already loaded stay loaded, so later tests simply rewrite files.
     */
    #[\PHPUnit\Framework\Attributes\After]
    protected function removeFixtureFilesystem(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s1-plugins-'.getmypid();
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s1-outside-'.getmypid();

        if (is_dir($root)) {
            foreach (scandir($root) ?: [] as $entry) {
                $path = $root.DIRECTORY_SEPARATOR.$entry;

                if ($entry !== '.' && $entry !== '..' && (is_link($path) || $this->isJunction($path))) {
                    @rmdir($path) || @unlink($path);
                }
            }

            File::deleteDirectory($root);
        }

        if (is_dir($outside)) {
            File::deleteDirectory($outside);
        }
    }

    private function isJunction(string $path): bool
    {
        $real = realpath($path);

        return is_dir($path) && $real !== false
            && strtolower(str_replace('\\', '/', $real)) !== strtolower(str_replace('\\', '/', $path));
    }
}
