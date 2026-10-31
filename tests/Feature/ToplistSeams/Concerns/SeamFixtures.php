<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-38-TOPLIST-SEAMS-R1 fixtures: vi (default) + en, and disposable plugins
 * in a per-process temp root OUTSIDE the repository. Each plugin's own class
 * files register its SEO provider, so call-site and provider ownership come
 * from genuine realpaths, exactly as for a real content-owning plugin.
 *
 * Provider classes (one file each, loaded only when a test uses it, so the
 * classes built on new contracts never load on a baseline without them):
 *   SeoOnly     — ContentSeoProvider + ContentSitemapPolicy (existing contracts)
 *   Probe       — + ContentAvailabilityProbe
 *   LocalePolicy — + LocalizedContentSitemapPolicy
 */
trait SeamFixtures
{
    protected function sxRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-seams38-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function setUpSeamSite(): void
    {
        $language = app('cms.language');
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $language->setCurrent('vi');
        app()->setLocale('vi');

        config(['cms.paths.plugins' => $this->sxRoot()]);
        app('cms.extension')->flushRegistry();
    }

    /**
     * Write and activate a fixture plugin. $providers are the class names the
     * manifest declares (a missing class simulates an unloadable owner).
     *
     * @param  list<string>  $providers
     */
    protected function sxPlugin(string $slug, array $providers = []): void
    {
        $dir = $this->sxRoot().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.'/src');
        File::put($dir.'/plugin.json', (string) json_encode([
            'name' => 'Seams Fixture '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Seams Tests',
            'providers' => $providers,
        ], JSON_UNESCAPED_SLASHES)."\n");

        $ns = $this->sxNamespace($slug);
        $files = [
            'Plugin' => $this->pluginSource($ns),
            'SeoOnly' => $this->providerSource($ns, 'SeoOnly', []),
            'Probe' => $this->providerSource($ns, 'Probe', ['ContentAvailabilityProbe']),
            'LocalePolicy' => $this->providerSource($ns, 'LocalePolicy', ['LocalizedContentSitemapPolicy']),
        ];
        // Runtime providers live where Core's plugin autoloader maps them (Plugins\{Studly}\).
        $runtime = $this->sxRuntimeNamespace($slug);
        $files['ThrowingServiceProvider'] = $this->serviceProviderSource($runtime, 'ThrowingServiceProvider', "throw new \RuntimeException('SX-SECRET boot failure');");
        $files['HealthyServiceProvider'] = $this->serviceProviderSource($runtime, 'HealthyServiceProvider', '');
        foreach ($files as $class => $source) {
            $path = $dir.'/src/'.$class.'.php';
            if (! File::exists($path)) {
                File::put($path, $source);
            }
        }
        if (! File::exists($dir.'/src/OwnerGuard.php')) {
            File::put($dir.'/src/OwnerGuard.php', \Tests\Support\ContentFixtures::allowAllGuardSource($ns));
        }

        app('cms.extension')->flushRegistry();
        $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        app('cms.extension')->flushRegistry();

        $this->sxCode($slug)->registerGuard();
        \Tests\Support\ContentFixtures::signInOwnerActor();
    }

    /** Namespace Core's plugin PSR-4 autoloader maps to the plugin's src/. */
    protected function sxRuntimeNamespace(string $slug): string
    {
        return 'Plugins\\'.Str::studly($slug);
    }

    protected function sxNamespace(string $slug): string
    {
        return 'SxFixture\\'.Str::studly($slug);
    }

    protected function sxClass(string $slug, string $class): string
    {
        $fqcn = $this->sxNamespace($slug).'\\'.$class;

        if (! class_exists($fqcn, false)) {
            require_once $this->sxRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$class.'.php';
        }

        return $fqcn;
    }

    protected function sxCode(string $slug): object
    {
        $class = $this->sxClass($slug, 'Plugin');

        return new $class;
    }

    /** Register (from the plugin's own code) a fresh provider of $class with static config reset. */
    protected function sxProvider(string $slug, string $class = 'SeoOnly'): string
    {
        $fqcn = $this->sxClass($slug, $class);
        $fqcn::reset();
        $this->assertTrue($this->sxCode($slug)->registerSeo(new $fqcn), "register {$class} for {$slug}");

        return $fqcn;
    }

    /** A published bilingual post owned by $slug, created through Core's extension context. */
    protected function sxOwnedPost(string $slug, string $title, array $extra = [], ?ContentFallbackPolicy $fallback = null): Content
    {
        $post = $this->sxCode($slug)->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>'.$title.' body</p>',
        ] + $extra, $fallback);

        return $this->sxCode($slug)->update($post, [
            'type' => 'post', 'locale' => 'en', 'title' => $title.' EN', 'slug' => Str::slug($title).'-en', 'content' => '<p>EN body</p>',
        ]);
    }

    protected function sxCorePost(string $title, array $extra = []): Content
    {
        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>'.$title.' body</p>',
        ] + $extra, \Tests\Support\ContentFixtures::context());

        return app('cms.content')->update($post, [
            'locale' => 'en', 'title' => $title.' EN', 'slug' => Str::slug($title).'-en', 'content' => '<p>EN body</p>',
        ], null, \Tests\Support\ContentFixtures::context());
    }

    /**
     * TEST-ONLY fixture shortcut: mark existing Core content as owned by a
     * plugin that has no loadable code (equivalent to a prior Core claim).
     */
    protected function sxMarkOwned(Content $content, string $owner, ContentFallbackPolicy $fallback): void
    {
        DB::table('cms_contents')->where('id', $content->id)->update(['managed_by' => $owner]);
        DB::table('cms_content_owner_policies')->insert([
            'content_id' => $content->id, 'fallback_policy' => $fallback->value, 'availability' => 'available',
            'operator_assigned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function sxPath(Content $content, string $locale = 'vi'): string
    {
        return (string) parse_url(content_url($content->fresh('translations'), $locale), PHP_URL_PATH);
    }

    /** Snapshot of every table a GET must never write. */
    protected function sxStateHash(): string
    {
        $rows = [];
        foreach (['cms_contents', 'cms_content_translations', 'cms_content_owner_policies', 'cms_settings'] as $table) {
            $rows[$table] = DB::table($table)->orderBy(DB::raw('1'))->get()->map(static fn ($r) => (array) $r)->all();
        }

        return hash('sha256', (string) json_encode($rows));
    }

    private function pluginSource(string $ns): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;

/** Stands in for the plugin's own code. */
final class Plugin
{
    public function create(array \$data, ?ContentFallbackPolicy \$fallback = null): \TheNguyen\CMS\Models\Content
    {
        return \$fallback === null
            ? app('cms.content_ownership')->createOwned(\$data)
            : app('cms.content_ownership')->createOwned(\$data, \$fallback);
    }

    public function update(\TheNguyen\CMS\Models\Content \$content, array \$data): \TheNguyen\CMS\Models\Content
    {
        return app('cms.content')->update(\$content, \$data, app('cms.content')->revisionOf(\$content), \TheNguyen\CMS\ContentAuthority\ContentWriteContext::extension());
    }

    public function availability(int \$id, ContentAvailability \$state): void
    {
        app('cms.content_ownership')->setAvailability(\$id, \$state);
    }

    public function registerSeo(object \$provider): bool
    {
        return app('cms.content_seo')->register(\$provider);
    }

    public function registerGuard(): bool
    {
        require_once __DIR__.'/OwnerGuard.php';

        return app('cms.content.guards')->register(new OwnerGuard);
    }
}

PHP;
    }

    /** @param  list<string>  $extra  additional Content\Extension interfaces */
    private function providerSource(string $ns, string $class, array $extra): string
    {
        $implements = implode(', ', ['ContentSeoProvider', 'ContentSitemapPolicy', ...$extra]);
        $uses = implode("\n", array_map(static fn (string $i): string => "use TheNguyen\\CMS\\Content\\Extension\\{$i};", ['ContentAvailability', 'ContentSeoOverride', 'ContentSeoProvider', 'ContentSeoRequest', 'ContentSitemapPolicy', ...$extra]));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

{$uses}

/** Configurable owner provider (static state: one instance per registration). */
final class {$class} implements {$implements}
{
    public static ?ContentSeoOverride \$override = null;

    /** ''|throw */
    public static string \$seo = '';

    /** null = no opinion, a ContentAvailability, 'throw', 'invalid' or 'write' (probe answer) */
    public static mixed \$probe = null;

    /** @var list<int> excluded in every locale */
    public static array \$exclude = [];

    /** @var array<string, list<int>> locale => excluded ids */
    public static array \$excludeByLocale = [];

    /** @var list<string> */
    public static array \$calls = [];

    public static function reset(): void
    {
        self::\$override = null;
        self::\$seo = '';
        self::\$probe = null;
        self::\$exclude = [];
        self::\$excludeByLocale = [];
        self::\$calls = [];
    }

    public function seoFor(ContentSeoRequest \$request): ?ContentSeoOverride
    {
        self::\$calls[] = 'seo:'.\$request->contentId.':'.\$request->locale;

        if (self::\$seo === 'throw') {
            throw new \RuntimeException('SX-SECRET-MESSAGE db_password=hunter2');
        }

        return self::\$override;
    }

    public function sitemapExcludedIds(array \$contentIds): array
    {
        self::\$calls[] = 'policy:'.implode(',', \$contentIds);

        return self::\$exclude;
    }

    public function sitemapExcludedIdsForLocale(array \$contentIds, string \$locale): array
    {
        self::\$calls[] = 'locale-policy:'.\$locale.':'.implode(',', \$contentIds);

        return array_values(array_merge(self::\$exclude, self::\$excludeByLocale[\$locale] ?? []));
    }

    /** 'invalid' returns a non-enum value: PHP's return type rejects it (TypeError). */
    public function availabilityFor(ContentSeoRequest \$request): ?ContentAvailability
    {
        self::\$calls[] = 'probe:'.\$request->contentId.':'.\$request->locale;

        return match (true) {
            self::\$probe === 'throw' => throw new \RuntimeException('SX-SECRET-PROBE db_password=hunter2'),
            self::\$probe === 'invalid' => 'available',
            self::\$probe === 'write' => \$this->write(\$request),
            self::\$probe === 'commit' => \$this->commitWrite(\$request),
            self::\$probe === 'open-throw' => \$this->openAndThrow(),
            default => self::\$probe,
        };
    }

    /** Escapes Core's guard by committing its transaction (unbalanced). */
    private function commitWrite(ContentSeoRequest \$request): ?ContentAvailability
    {
        \Illuminate\Support\Facades\DB::commit();

        return null;
    }

    /** Opens its own transaction and throws without unwinding it. */
    private function openAndThrow(): ?ContentAvailability
    {
        \Illuminate\Support\Facades\DB::beginTransaction();

        throw new \RuntimeException('SX-SECRET unwound');
    }

    /** A misbehaving probe that tries to persist from a GET. */
    private function write(ContentSeoRequest \$request): ?ContentAvailability
    {
        \Illuminate\Support\Facades\DB::table('cms_content_owner_policies')
            ->where('content_id', \$request->contentId)
            ->update(['availability' => ContentAvailability::Unavailable->value]);

        return null;
    }
}

PHP;
    }

    private function serviceProviderSource(string $ns, string $class, string $register): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

/** A plugin runtime provider; a throwing register() stands for a broken dependency. */
final class {$class} extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        {$register}
    }
}

PHP;
    }
}
