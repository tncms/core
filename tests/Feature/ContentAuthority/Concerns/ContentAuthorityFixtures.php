<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\ContentManager;

/**
 * Fixtures for CORE-STRUCTURED-CONTENT-AUTHORITY-R1.
 *
 * Synthetic plugins are real directories under a per-process temp plugins root
 * OUTSIDE the repository, so plugin identity is derived from genuine file paths
 * exactly as at runtime (no identity test seam):
 *
 *   CaFixture\{Studly}\Guard  — a configurable ContentWriteGuard (static mode)
 *   CaFixture\{Studly}\Plugin — "the plugin's own code": registers its guard and
 *                               performs extension-context content writes
 */
trait ContentAuthorityFixtures
{
    protected function contents(): ContentManager
    {
        return app('cms.content');
    }

    /** Languages (vi default + en) and the Core roles: RBAC becomes initialised. */
    protected function seedSite(): void
    {
        $this->seed(CmsLanguageSeeder::class);
        $this->seed(CmsRolePermissionSeeder::class);
    }

    protected function userWithRole(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $roleModel = Role::query()->where('slug', $role)->firstOrFail();

        if ($extraPermissions !== []) {
            $custom = Role::query()->create(['name' => 'Custom '.Str::random(6), 'slug' => 'custom-'.Str::lower(Str::random(8)), 'is_system' => false]);
            $ids = $roleModel->permissions()->pluck('cms_permissions.id')
                ->merge(Permission::query()->whereIn('slug', $extraPermissions)->pluck('id'))
                ->unique()->all();
            $custom->permissions()->sync($ids);
            $roleModel = $custom;
        }

        $roleModel->users()->attach($user->id);

        return $user;
    }

    protected function superAdmin(): User
    {
        return $this->userWithRole('super-admin');
    }

    /** Create content as a super admin (fixture setup, not under test). */
    protected function makeContent(array $data, ?User $author = null): Content
    {
        $admin = $this->superAdmin();
        $previous = auth()->user();
        $this->actingAs($admin);

        $content = $this->contents()->create($data + [
            'type' => 'post', 'status' => 'draft', 'locale' => 'en',
            'title' => 'Fixture '.Str::random(5), 'content' => '<p>Body</p>',
            'author_id' => $author?->id ?? $admin->id,
        ]);

        $previous !== null ? $this->actingAs($previous) : auth()->logout();

        return $content->refresh();
    }

    // ---------------------------------------------------------------------
    // Synthetic plugins
    // ---------------------------------------------------------------------

    protected function authorityFixtureRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-ca-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function useAuthorityFixturePlugins(): void
    {
        config(['cms.paths.plugins' => $this->authorityFixtureRoot()]);
        app('cms.extension')->flushRegistry();
    }

    protected function authorityNamespace(string $slug): string
    {
        return 'CaFixture\\'.Str::studly($slug);
    }

    protected function writeAuthorityPlugin(string $slug): void
    {
        $dir = $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'src');
        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', (string) json_encode([
            'name' => 'CA Fixture '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'CA Tests',
        ], JSON_PRETTY_PRINT)."\n");

        $ns = $this->authorityNamespace($slug);
        $guard = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Guard.php';
        $plugin = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Plugin.php';

        if (! File::exists($guard)) {
            File::put($guard, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use TheNguyen\CMS\ContentAuthority\ContentWriteDecision;
use TheNguyen\CMS\ContentAuthority\ContentWriteIntent;
use TheNguyen\CMS\Contracts\ContentWriteGuard;

final class Guard implements ContentWriteGuard
{
    /** allow | deny | throw | malformed | own-only (allow only this plugin's own writes) */
    public static string \$mode = 'allow';

    /** @var list<ContentWriteIntent> */
    public static array \$seen = [];

    public function check(ContentWriteIntent \$intent): ContentWriteDecision
    {
        self::\$seen[] = \$intent;

        return match (self::\$mode) {
            'deny' => ContentWriteDecision::deny('locked', 'Structured article: edit it in the article editor.'),
            'throw' => throw new \RuntimeException('guard exploded secret=hunter2'),
            'malformed' => ContentWriteDecision::deny('Not A Code!', ''),
            'own-only' => \$intent->isFromExtension('{$slug}')
                ? ContentWriteDecision::allow()
                : ContentWriteDecision::deny('foreign_write', 'Only the article editor may change this.'),
            default => ContentWriteDecision::allow(),
        };
    }
}

PHP);
        }

        if (! File::exists($plugin)) {
            File::put($plugin, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Contracts\Auth\Authenticatable;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Models\Content;

/** Stands in for the plugin's own code (service provider, publish service). */
final class Plugin
{
    public function registerGuard(object \$guard): bool
    {
        return app('cms.content.guards')->register(\$guard);
    }

    public function context(?Authenticatable \$actor = null): ContentWriteContext
    {
        return ContentWriteContext::extension(\$actor);
    }

    public function createManaged(array \$data, ?Authenticatable \$actor = null): Content
    {
        return app('cms.content')->create(\$data, ContentWriteContext::extension(\$actor)->claimingOwnership());
    }

    public function claim(Content \$content, int \$revision, ?Authenticatable \$actor = null): Content
    {
        return app('cms.content')->claimOwnership(\$content, \$revision, ContentWriteContext::extension(\$actor));
    }

    public function update(Content \$content, array \$data, ?int \$revision, ?Authenticatable \$actor = null): Content
    {
        return app('cms.content')->update(\$content, \$data, \$revision, ContentWriteContext::extension(\$actor));
    }

    /** A pre-R1 call (no context) made from plugin code. */
    public function legacyUpdate(Content \$content, array \$data, ?int \$revision = null): Content
    {
        return app('cms.content')->update(\$content, \$data, \$revision);
    }

    public function systemContext(): ContentWriteContext
    {
        return ContentWriteContext::system('demo_import');
    }

    /** A multi-locale publish orchestrated as one Core write unit. */
    public function publishUnit(Content \$content, int \$revision, array \$perLocale, ?Authenticatable \$actor = null, ?\Closure \$after = null): mixed
    {
        \$context = ContentWriteContext::extension(\$actor);

        return app('cms.content')->transaction(\$content, \$revision, \$context, function (Content \$locked) use (\$perLocale, \$context, \$after) {
            foreach (\$perLocale as \$data) {
                app('cms.content')->update(\$locked, \$data, null, \$context);
            }

            return \$after !== null ? \$after(\$locked) : \$locked;
        });
    }
}

PHP);
        }
    }

    protected function loadAuthorityClass(string $slug, string $class): string
    {
        $fqcn = $this->authorityNamespace($slug).'\\'.$class;

        if (! class_exists($fqcn, false)) {
            require_once $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$class.'.php';
        }

        return $fqcn;
    }

    protected function pluginCode(string $slug): object
    {
        $fqcn = $this->loadAuthorityClass($slug, 'Plugin');

        return new $fqcn;
    }

    /** The fixture guard class of a plugin (static mode / seen intents). */
    protected function guardClass(string $slug): string
    {
        return $this->loadAuthorityClass($slug, 'Guard');
    }

    protected function activateAuthorityPlugins(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $this->writeAuthorityPlugin($slug);
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
        }

        app('cms.extension')->flushRegistry();
    }

    /** Write + activate a plugin and register its guard in the given mode. */
    protected function guardedPlugin(string $slug, string $mode = 'allow'): object
    {
        $this->activateAuthorityPlugins($slug);
        $guard = $this->guardClass($slug);
        $guard::$mode = $mode;
        $guard::$seen = [];
        $plugin = $this->pluginCode($slug);
        $this->assertTrue($plugin->registerGuard(new $guard), json_encode(app('cms.content.guards')->diagnostics()));

        return $plugin;
    }

    /** Full persisted state of a content item, for "nothing changed" assertions. */
    protected function contentState(int $id): array
    {
        $row = Content::withTrashed()->findOrFail($id);

        return [
            'row' => collect($row->getAttributes())->except(['updated_at'])->all(),
            'translations' => $row->translations()->orderBy('locale')->get()->map->only(['locale', 'title', 'slug', 'excerpt', 'content', 'meta_title'])->all(),
            'terms' => $row->terms()->pluck('cms_terms.id')->sort()->values()->all(),
            'slugs' => \TheNguyen\CMS\Models\Slug::query()->where('reference_type', 'content')->where('reference_id', $id)->orderBy('locale')->pluck('full_path')->all(),
        ];
    }
}
