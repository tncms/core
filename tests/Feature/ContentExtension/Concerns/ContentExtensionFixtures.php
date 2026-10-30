<?php

declare(strict_types=1);

namespace Tests\Feature\ContentExtension\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Models\Content;

/**
 * Disposable fixture plugins for CORE-CONTENT-EXT-R1 tests.
 *
 * Each plugin is a real directory under a per-process temp plugins root
 * OUTSIDE the repository, with real class files, so call-site and provider
 * ownership come from genuine realpaths (no identity test seam):
 *
 *   CxFixture\{Studly}\Plugin      — "the plugin's own code"
 *   CxFixture\{Studly}\SeoProvider — a configurable ContentSeoProvider
 *
 * Class files are written once per process (PHP cannot reload a class).
 */
trait ContentExtensionFixtures
{
    protected function cxRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-cx-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    /** Point Core at the fixture root and write + activate the given plugins. */
    protected function useCxPlugins(string ...$slugs): void
    {
        config(['cms.paths.plugins' => $this->cxRoot()]);

        foreach ($slugs as $slug) {
            $this->writeCxPlugin($slug);
        }

        app('cms.extension')->flushRegistry();

        foreach ($slugs as $slug) {
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        }

        app('cms.extension')->flushRegistry();

        // CORE-UNIFIED-OWNERSHIP-R1: owned content is governed by its owner's
        // guard (registered by the plugin's own code, before any request) and
        // ownership changes need a signed-in actor.
        foreach ($slugs as $slug) {
            $this->cxPlugin($slug)->registerGuard();
        }

        \Tests\Support\ContentFixtures::signInOwnerActor();
    }

    protected function writeCxPlugin(string $slug): void
    {
        $dir = $this->cxRoot().DIRECTORY_SEPARATOR.$slug;
        $src = $dir.DIRECTORY_SEPARATOR.'src';
        File::ensureDirectoryExists($src);

        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', (string) json_encode([
            'name' => 'CX Fixture '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'CX Tests',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        $ns = $this->cxNamespace($slug);

        if (! File::exists($src.DIRECTORY_SEPARATOR.'OwnerGuard.php')) {
            File::put($src.DIRECTORY_SEPARATOR.'OwnerGuard.php', \Tests\Support\ContentFixtures::allowAllGuardSource($ns));
        }

        if (! File::exists($src.DIRECTORY_SEPARATOR.'Plugin.php')) {
            File::put($src.DIRECTORY_SEPARATOR.'Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentSeoProvider;

/** Stands in for the plugin's own code. */
final class Plugin
{
    public function create(array \$data, ?ContentFallbackPolicy \$fallback = null): \TheNguyen\CMS\Models\Content
    {
        return \$fallback === null
            ? app('cms.content_ownership')->createOwned(\$data)
            : app('cms.content_ownership')->createOwned(\$data, \$fallback);
    }

    public function availability(int \$id, ContentAvailability \$state, ?int \$retry = null): void
    {
        app('cms.content_ownership')->setAvailability(\$id, \$state, \$retry);
    }

    public function fallback(int \$id, ContentFallbackPolicy \$fallback): void
    {
        app('cms.content_ownership')->setFallback(\$id, \$fallback);
    }

    public function release(int \$id): void
    {
        app('cms.content_ownership')->release(\$id);
    }

    public function registerSeo(ContentSeoProvider \$provider): bool
    {
        return app('cms.content_seo')->register(\$provider);
    }

    public function registerGuard(): bool
    {
        require_once __DIR__.'/OwnerGuard.php';

        return app('cms.content.guards')->register(new OwnerGuard);
    }

    /** Takeover attempt through the operator service from plugin code. */
    public function adminAssign(int \$id, string \$owner): void
    {
        app('cms.content_ownership_admin')->assign(\$id, \$owner, ContentFallbackPolicy::CoreRender, null, auth()->user());
    }

    /** The owner edits its own content (CORE-UNIFIED-OWNERSHIP-R1: managed content changes only through its owner). */
    public function update(\TheNguyen\CMS\Models\Content \$content, array \$data): \TheNguyen\CMS\Models\Content
    {
        return app('cms.content')->update(\$content, \$data, app('cms.content')->revisionOf(\$content), \TheNguyen\CMS\ContentAuthority\ContentWriteContext::extension());
    }

    public function delete(\TheNguyen\CMS\Models\Content \$content): bool
    {
        return app('cms.content')->delete(\$content, app('cms.content')->revisionOf(\$content), \TheNguyen\CMS\ContentAuthority\ContentWriteContext::extension());
    }
}

PHP);
        }

        if (! File::exists($src.DIRECTORY_SEPARATOR.'SeoProvider.php')) {
            File::put($src.DIRECTORY_SEPARATOR.'SeoProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\ContentSeoProvider;
use TheNguyen\CMS\Content\Extension\ContentSeoRequest;

final class SeoProvider implements ContentSeoProvider
{
    /** @var list<array{int, string, string}> */
    public array \$calls = [];

    public function __construct(private ?ContentSeoOverride \$override = null, private string \$behaviour = '') {}

    public function seoFor(ContentSeoRequest \$request): ?ContentSeoOverride
    {
        \$this->calls[] = [\$request->contentId, \$request->contentType, \$request->locale];

        return match (\$this->behaviour) {
            'throw' => throw new \RuntimeException('CX-SECRET-MESSAGE db_password=hunter2'),
            'locale' => new ContentSeoOverride(title: 'Owner title '.\$request->locale),
            default => \$this->override,
        };
    }
}

PHP);
        }
    }

    protected function cxNamespace(string $slug): string
    {
        return 'CxFixture\\'.Str::studly($slug);
    }

    private function cxClass(string $slug, string $class): string
    {
        $fqcn = $this->cxNamespace($slug).'\\'.$class;

        if (! class_exists($fqcn, false)) {
            require_once $this->cxRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$class.'.php';
        }

        return $fqcn;
    }

    /** The fixture plugin's own code object. */
    protected function cxPlugin(string $slug): object
    {
        $class = $this->cxClass($slug, 'Plugin');

        return new $class;
    }

    protected function cxSeoProvider(string $slug, ?ContentSeoOverride $override = null, string $behaviour = ''): object
    {
        $class = $this->cxClass($slug, 'SeoProvider');

        return new $class($override, $behaviour);
    }

    /** A published post created by $slug (owned) through Core's creation context. */
    protected function ownedPost(string $slug, string $title, array $extra = []): Content
    {
        return $this->cxPlugin($slug)->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>'.$title.' body</p>',
        ] + $extra);
    }

    protected function corePost(string $title, array $extra = []): Content
    {
        return app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>'.$title.' body</p>',
        ] + $extra, \Tests\Support\ContentFixtures::context());
    }

    protected function setUpCxLanguages(bool $bilingual = false): void
    {
        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);

        if ($bilingual) {
            $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        }

        app('cms.taxonomy')->ensureCoreTaxonomies();
        $language->setCurrent('en');
        app()->setLocale('en');
    }
}
