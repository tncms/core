<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-SITEMAP-R1 fixtures: vi (default) + en languages, Core content helpers,
 * and disposable plugins whose own code registers a sitemap provider, SEO
 * policy and routes — so call-site identity, route ownership and lifecycle are
 * exercised exactly as for a real plugin.
 */
trait SitemapFixtures
{
    protected function smRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-sitemap-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function setUpSitemapSite(bool $english = true): void
    {
        $language = app('cms.language');
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);

        if ($english) {
            $language->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        }

        app('cms.taxonomy')->ensureCoreTaxonomies();
        config(['cms.paths.plugins' => $this->smRoot()]);
        app('cms.extension')->flushRegistry();
    }

    /** A published post with a vi translation and, optionally, en. */
    protected function smPost(string $title, bool $english = true, array $extra = [], string $type = 'post'): Content
    {
        $content = app('cms.content')->create([
            'type' => $type, 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>'.$title.'</p>',
        ] + $extra, \Tests\Support\ContentFixtures::context());

        if ($english) {
            $content = app('cms.content')->update($content, [
                'locale' => 'en', 'title' => $title.' EN', 'slug' => Str::slug($title).'-en', 'content' => '<p>EN</p>',
            ], null, \Tests\Support\ContentFixtures::context());
        }

        return $content;
    }

    /** Write (or rewrite) and activate a fixture plugin. */
    protected function smPlugin(string $slug): void
    {
        $dir = $this->smRoot().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.'/src');
        File::put($dir.'/plugin.json', (string) json_encode([
            'name' => 'Sitemap Fixture '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Sitemap Tests',
        ], JSON_UNESCAPED_SLASHES)."\n");

        $ns = $this->smNamespace($slug);

        File::put($dir.'/src/Plugin.php', $this->pluginSource($ns));
        File::put($dir.'/src/Provider.php', $this->providerSource($ns, $slug));
        File::put($dir.'/src/SeoPolicy.php', $this->policySource($ns));
        File::put($dir.'/src/SeoOnly.php', $this->seoOnlySource($ns));

        File::put($dir.'/src/OwnerGuard.php', \Tests\Support\ContentFixtures::allowAllGuardSource($ns));

        app('cms.extension')->flushRegistry();
        $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        app('cms.extension')->flushRegistry();

        // CORE-UNIFIED-OWNERSHIP-R1: the owner's guard, registered by its own code.
        $this->smCode($slug)->registerGuard();
        \Tests\Support\ContentFixtures::signInOwnerActor();
    }

    protected function smNamespace(string $slug): string
    {
        return 'SmFixture\\'.Str::studly($slug);
    }

    /** Fully-qualified class of a fixture plugin file, loading it on first use. */
    protected function smClass(string $slug, string $class): string
    {
        $fqcn = $this->smNamespace($slug).'\\'.$class;

        if (! class_exists($fqcn, false)) {
            require_once $this->smRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$class.'.php';
        }

        return $fqcn;
    }

    /** The fixture plugin's own code object (calls from here carry its identity). */
    protected function smCode(string $slug): object
    {
        $class = $this->smClass($slug, 'Plugin');

        return new $class;
    }

    /**
     * Configure the plugin provider's dataset: type => [key => slug].
     *
     * @param  array<string, array<int, string>>  $items
     */
    protected function smItems(string $slug, array $items, string $mode = ''): void
    {
        $class = $this->smClass($slug, 'Provider');
        $class::$items = $items;
        $class::$mode = $mode;
        $class::$extra = [];
    }

    /**
     * Rebuild the router in real order: Core web routes, each plugin's routes
     * inside the wrapper ExtensionManager applies, then Core's catch-alls.
     */
    protected function smRoutes(string ...$slugs): void
    {
        app('router')->setRoutes(new RouteCollection);
        require base_path('packages/thenguyen/cms-core/routes/web.php');

        $others = array_values(array_diff(app('cms.language')->getPublicLocales(), [app('cms.language')->defaultCode()]));

        foreach ($slugs as $slug) {
            Route::middleware(['web', 'cms.plugin-active:'.$slug])->group(static function () use ($slug, $others): void {
                Route::get('lib-'.$slug.'/{type}/{item}', static fn () => 'OK-'.$slug)->name($slug.'.show');

                if ($others !== []) {
                    Route::get('{locale}/lib-'.$slug.'/{type}/{item}', static fn () => 'OK-'.$slug)
                        ->whereIn('locale', $others)
                        ->name($slug.'.show.localized');
                }
            });
        }

        require base_path('packages/thenguyen/cms-core/routes/frontend.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /** @return list<string> child sitemap names listed by the index */
    protected function indexNames(): array
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        return array_map(
            static fn (string $loc): string => basename((string) parse_url($loc, PHP_URL_PATH), '.xml'),
            $this->xmlLocs($xml, 'sitemapindex'),
        );
    }

    /** @return list<string> */
    protected function chunkLocs(string $name): array
    {
        return $this->xmlLocs($this->get('/sitemaps/'.$name.'.xml')->assertOk()->streamedContent(), 'urlset');
    }

    /** Every loc across every child of the index. @return list<string> */
    protected function allLocs(): array
    {
        $out = [];
        foreach ($this->indexNames() as $name) {
            array_push($out, ...$this->chunkLocs($name));
        }

        return $out;
    }

    /** @return list<string> */
    protected function xmlLocs(string $xml, string $root): array
    {
        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml, LIBXML_NONET), 'well-formed XML');
        $this->assertSame($root, $doc->documentElement->localName);
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $doc->documentElement->namespaceURI);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locs = [];
        foreach ($xpath->query('//s:loc') as $node) {
            $locs[] = $node->textContent;
        }

        return $locs;
    }

    private function pluginSource(string $ns): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

/** Stands in for the plugin's own code. */
final class Plugin
{
    public function registerSitemap(?object \$provider = null): bool
    {
        return app('cms.sitemap_providers')->register(\$provider ?? new Provider);
    }

    public function registerSeo(object \$policy): bool
    {
        return app('cms.content_seo')->register(\$policy);
    }

    public function registerGuard(): bool
    {
        require_once __DIR__.'/OwnerGuard.php';

        return app('cms.content.guards')->register(new OwnerGuard);
    }

    public function own(array \$data): \TheNguyen\CMS\Models\Content
    {
        return app('cms.content_ownership')->createOwned(\$data);
    }

    public function availability(int \$id, \TheNguyen\CMS\Content\Extension\ContentAvailability \$state): void
    {
        app('cms.content_ownership')->setAvailability(\$id, \$state);
    }

    public function invalidate(): void
    {
        app('cms.sitemap')->invalidate();
    }
}

PHP;
    }

    private function providerSource(string $ns, string $slug): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Sitemap\SitemapEntry;
use TheNguyen\CMS\Sitemap\SitemapProvider;

/** Neutral dataset-driven provider; \$mode injects hostile behaviour. */
final class Provider implements SitemapProvider
{
    /** @var array<string, array<int, string>> */
    public static array \$items = [];

    public static string \$mode = '';

    /** @var array<string, mixed> */
    public static array \$extra = [];

    public static int \$entryCalls = 0;

    public function types(): array
    {
        return array_keys(self::\$items);
    }

    public function keyAt(string \$type, string \$locale, ?int \$after, int \$offset): ?int
    {
        if (self::\$mode === 'throw-keys') {
            throw new \RuntimeException('SM-SECRET keyAt');
        }

        \$keys = array_keys(self::\$items[\$type] ?? []);
        sort(\$keys);

        if (self::\$mode === 'descending' && \$after !== null) {
            return 1;
        }

        \$keys = array_values(array_filter(\$keys, static fn (int \$k) => \$after === null || \$k > \$after));

        return \$keys[\$offset] ?? null;
    }

    public function entries(string \$type, string \$locale, ?int \$after, ?int \$until, int \$limit): iterable
    {
        self::\$entryCalls++;

        if (self::\$mode === 'throw-entries' && \$after !== null) {
            throw new \RuntimeException('SM-SECRET entries');
        }

        if (self::\$mode === 'endless') {
            for (\$i = 1; ; \$i++) {
                yield new SitemapEntry(\$i, 'https://evil.example/'.\$i);
            }
        }

        \$items = self::\$items[\$type] ?? [];
        ksort(\$items);
        \$n = 0;

        foreach (\$items as \$key => \$item) {
            if ((\$after !== null && \$key <= \$after) || (\$until !== null && \$key > \$until) || \$n++ >= \$limit) {
                continue;
            }

            \$loc = \$this->url(\$type, \$item, \$locale);
            \$alternates = [];
            foreach (app('cms.language')->getPublicLocales() as \$code) {
                \$alternates[\$code] = \$this->url(\$type, \$item, \$code);
            }

            \$lastmod = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

            [\$loc, \$lastmod, \$alternates] = match (self::\$mode) {
                'external' => ['https://evil.example/'.\$item, \$lastmod, []],
                'foreign' => [(string) self::\$extra['url'], \$lastmod, []],
                'wrong-locale' => [\$this->url(\$type, \$item, 'vi'), \$lastmod, []],
                'bad-lastmod' => [\$loc, new \DateTimeImmutable('2999-01-01'), \$alternates],
                'xml' => [\$loc.'?a=1&b=<2>', \$lastmod, []],
                'utf8' => [\$loc."\\xC3\\x28", \$lastmod, []],
                'bad-alternate' => [\$loc, \$lastmod, ['en' => 'https://evil.example/en', 'vi' => \$this->url(\$type, \$item, 'vi')]],
                default => [\$loc, \$lastmod, \$alternates],
            };

            yield new SitemapEntry(\$key, \$loc, \$lastmod, \$alternates);

            if (self::\$mode === 'dupes') {
                yield new SitemapEntry(\$key, \$loc, \$lastmod, \$alternates);
            }
        }
    }

    private function url(string \$type, string \$item, string \$locale): string
    {
        return \$locale === app('cms.language')->defaultCode()
            ? route('{$slug}.show', ['type' => \$type, 'item' => \$item])
            : route('{$slug}.show.localized', ['locale' => \$locale, 'type' => \$type, 'item' => \$item]);
    }
}

PHP;
    }

    private function seoOnlySource(string $ns): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\ContentSeoProvider;
use TheNguyen\CMS\Content\Extension\ContentSeoRequest;

/** An owner SEO provider that does not answer indexability in batch. */
final class SeoOnly implements ContentSeoProvider
{
    public function seoFor(ContentSeoRequest \$request): ?ContentSeoOverride
    {
        return new ContentSeoOverride(title: 'Owner title');
    }
}

PHP;
    }

    /**
     * Bulk-insert $count published posts with a vi (and optionally en)
     * translation, bypassing model events, then bump Core's sitemap version
     * once. Optionally owned by $owner (available, hide_404). Returns ids.
     *
     * @return list<int>
     */
    protected function smBulkPosts(int $count, bool $english = false, ?string $owner = null, string $stem = 'bulk'): array
    {
        $now = now()->toDateTimeString();
        $start = (int) \Illuminate\Support\Facades\DB::table('cms_contents')->max('id');
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['type' => 'post', 'status' => 'published', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now, 'sort_order' => 0];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            \Illuminate\Support\Facades\DB::table('cms_contents')->insert($chunk);
        }
        $ids = \Illuminate\Support\Facades\DB::table('cms_contents')->where('id', '>', $start)->orderBy('id')->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $translations = [];
        $ownership = [];
        foreach ($ids as $n => $id) {
            $translations[] = ['content_id' => $id, 'locale' => 'vi', 'title' => "{$stem} {$id}", 'slug' => "{$stem}-{$id}", 'content' => '<p>x</p>', 'created_at' => $now, 'updated_at' => $now];
            if ($english) {
                $translations[] = ['content_id' => $id, 'locale' => 'en', 'title' => "{$stem} {$id} en", 'slug' => "{$stem}-{$id}-en", 'content' => '<p>x</p>', 'created_at' => $now, 'updated_at' => $now];
            }
            if ($owner !== null) {
                $ownership[] = ['content_id' => $id, 'fallback_policy' => 'hide_404', 'availability' => 'available', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($translations, 200) as $chunk) {
            \Illuminate\Support\Facades\DB::table('cms_content_translations')->insert($chunk);
        }
        // Single authority (CORE-UNIFIED-OWNERSHIP-R1): owner on the row, policy beside it.
        if ($owner !== null) {
            \Illuminate\Support\Facades\DB::table('cms_contents')->whereIn('id', $ids)->update(['managed_by' => $owner]);
        }
        foreach (array_chunk($ownership, 200) as $chunk) {
            \Illuminate\Support\Facades\DB::table('cms_content_owner_policies')->insert($chunk);
        }

        app('cms.sitemap')->invalidateCore();

        return $ids;
    }

    /** Absolute vi URL of a bulk post (Core's permalink authority). */
    protected function smBulkLoc(int $id, string $stem = 'bulk'): string
    {
        return url(content_url(\TheNguyen\CMS\Models\Content::query()->findOrFail($id), 'vi'));
    }

    private function policySource(string $ns): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\ContentSeoProvider;
use TheNguyen\CMS\Content\Extension\ContentSeoRequest;
use TheNguyen\CMS\Content\Extension\ContentSitemapPolicy;

/** Owner SEO provider that also answers the batched sitemap exclusion query. */
final class SeoPolicy implements ContentSeoProvider, ContentSitemapPolicy
{
    /** @var list<int> */
    public static array \$exclude = [];

    public static bool \$throw = false;

    /** @var list<list<int>> */
    public static array \$calls = [];

    /** Robots restriction the page override returns (null = none). */
    public static ?\TheNguyen\CMS\Content\Extension\RobotsRestriction \$robots = null;

    public function seoFor(ContentSeoRequest \$request): ?ContentSeoOverride
    {
        return self::\$robots === null ? null : new ContentSeoOverride(robots: self::\$robots);
    }

    public function sitemapExcludedIds(array \$contentIds): array
    {
        self::\$calls[] = \$contentIds;

        if (self::\$throw) {
            throw new \RuntimeException('SM-SECRET policy');
        }

        return self::\$exclude;
    }
}

PHP;
    }
}
