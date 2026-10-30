<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use App\Filament\Admin\Resources\RoleResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — plugin permission persistence on the
 * activation / re-install lifecycle (T-D1), definition ownership, the Roles
 * screen no longer dropping registered-but-unpersisted slugs (P08 G-P08-1),
 * and namespaced/legacy plugin seeder resolution (P08 G-P08-2).
 *
 * Fixture plugins are real directories with real providers under a temp plugins
 * root, booted through ExtensionManager exactly like a later request.
 */
final class PluginPermissionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function root(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-ca-perm-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $this->seed(CmsRolePermissionSeeder::class);
        config(['cms.paths.plugins' => $this->root()]);
        app('cms.extension')->flushRegistry();
    }

    /** A plugin whose provider registers permissions (optionally a foreign slug). */
    private function writePermissionPlugin(string $slug, array $slugs, array $extraFiles = []): void
    {
        $dir = $this->root().DIRECTORY_SEPARATOR.$slug;
        $studly = Str::studly($slug);
        File::ensureDirectoryExists($dir.'/src');
        File::put($dir.'/plugin.json', (string) json_encode([
            'name' => 'Perm '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'CA Tests',
            'providers' => ["Plugins\\{$studly}\\PermissionProvider"],
        ], JSON_PRETTY_PRINT));

        $calls = implode("\n", array_map(
            static fn (string $s): string => "        app('cms.permission')->register('{$s}', 'Fixture {$s}', 'Fixture');",
            $slugs,
        ));

        $provider = $dir.'/src/PermissionProvider.php';

        if (! File::exists($provider)) {
            File::put($provider, <<<PHP
<?php

declare(strict_types=1);

namespace Plugins\\{$studly};

use Illuminate\\Support\\ServiceProvider;

final class PermissionProvider extends ServiceProvider
{
    public function register(): void
    {
{$calls}
    }

    /** Plugin code persisting slugs through the public subset API. */
    public static function persist(array \$slugs): array
    {
        return app('cms.permission')->persist(\$slugs);
    }
}

PHP);
        }

        foreach ($extraFiles as $relative => $content) {
            File::ensureDirectoryExists(dirname($dir.'/'.$relative));
            File::put($dir.'/'.$relative, $content);
        }
    }

    /** What a later request does: boot active plugins, then the booted callbacks. */
    private function nextRequestBoot(): void
    {
        app('cms.extension')->flushRegistry();
        app('cms.extension')->bootActivePlugins();
        app('cms.plugin_lifecycle')->syncPendingPermissions();
    }

    private function grants(): array
    {
        return DB::table('cms_role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_web_activation_persists_the_plugins_permissions_without_touching_grants(): void
    {
        $this->writePermissionPlugin('ca-perm-a', ['ca-perm-a.view', 'ca-perm-a.publish']);
        $grants = $this->grants();
        $rows = Permission::query()->count();

        $result = app('cms.plugin_lifecycle')->activate('ca-perm-a');
        $this->assertTrue($result->success, $result->message);
        $this->assertSame(0, Permission::query()->where('slug', 'like', 'ca-perm-a.%')->count(), 'not booted yet on the activating request');

        $this->nextRequestBoot();

        $this->assertSame(['ca-perm-a.publish', 'ca-perm-a.view'], Permission::query()->where('slug', 'like', 'ca-perm-a.%')->orderBy('slug')->pluck('slug')->all());
        $this->assertSame($rows + 2, Permission::query()->count());
        $this->assertSame($grants, $this->grants(), 'no role grant added or removed');
        $this->assertSame([], settings('extensions.permission_sync_pending', []));

        // Repeat activation, deactivation + reactivation and a re-install are idempotent.
        app('cms.plugin_lifecycle')->deactivate('ca-perm-a');
        app('cms.plugin_lifecycle')->activate('ca-perm-a');
        app('cms.plugin_lifecycle')->install('ca-perm-a');
        $this->nextRequestBoot();
        $this->nextRequestBoot();

        $this->assertSame($rows + 2, Permission::query()->count());
        $this->assertSame($grants, $this->grants());
    }

    public function test_a_plugin_cannot_redefine_or_persist_another_owners_slugs(): void
    {
        $this->writePermissionPlugin('ca-perm-b', ['ca-perm-b.view']);
        $this->writePermissionPlugin('ca-perm-c', ['ca-perm-b.view', 'posts.publish', 'ca-perm-c.view']);
        app('cms.plugin_lifecycle')->activate('ca-perm-b');
        app('cms.plugin_lifecycle')->activate('ca-perm-c');
        $this->nextRequestBoot();

        $permissions = app('cms.permission');
        $this->assertSame('ca-perm-b', $permissions->ownerOf('ca-perm-b.view'));
        $this->assertSame('core', $permissions->ownerOf('posts.publish'));
        $this->assertSame('ca-perm-c', $permissions->ownerOf('ca-perm-c.view'));
        $this->assertSame('Fixture ca-perm-b.view', Permission::query()->where('slug', 'ca-perm-b.view')->value('name'));
        $refused = array_column(array_filter($permissions->diagnostics(), fn ($d) => $d['owner'] === 'ca-perm-c'), 'slug');
        $this->assertEqualsCanonicalizing(['ca-perm-b.view', 'posts.publish'], $refused);

        // Plugin C's subset persist of foreign slugs persists nothing foreign.
        Permission::query()->where('slug', 'ca-perm-b.view')->delete();
        $persisted = ('Plugins\\CaPermC\\PermissionProvider')::persist(['ca-perm-b.view', 'ca-perm-c.view', 'never.registered']);
        $this->assertSame(['ca-perm-c.view'], $persisted);
        $this->assertFalse($permissions->isPersisted('ca-perm-b.view'));
        $this->assertFalse($permissions->isPersisted('never.registered'));
    }

    public function test_the_roles_screen_persists_selected_registered_slugs_instead_of_dropping_them(): void
    {
        app('cms.permission')->register('ca-host.unpersisted', 'Unpersisted host permission', 'Fixture');
        $this->assertFalse(app('cms.permission')->isPersisted('ca-host.unpersisted'));
        $role = Role::query()->where('slug', 'editor')->firstOrFail();

        $state = [
            RoleResource::permissionGroupKey('Fixture') => ['ca-host.unpersisted', 'not.registered.anywhere'],
            RoleResource::permissionGroupKey('Posts') => ['posts.view'],
        ];
        RoleResource::syncPermissions($role, $state);

        $slugs = $role->permissions()->pluck('slug')->all();
        $this->assertContains('ca-host.unpersisted', $slugs);
        $this->assertNotContains('not.registered.anywhere', $slugs);
        $this->assertFalse(Permission::query()->where('slug', 'not.registered.anywhere')->exists());
    }

    public function test_namespaced_and_legacy_seeders_both_run_when_already_loaded(): void
    {
        $seeder = static fn (string $ns, string $class, string $key): string => "<?php\n\ndeclare(strict_types=1);\n\n"
            .($ns !== '' ? "namespace {$ns};\n\n" : '')
            ."use Illuminate\\Database\\Seeder;\n\nclass {$class} extends Seeder\n{\n    public function run(): void\n    {\n        settings()->set('{$key}', true, 'boolean');\n    }\n}\n";

        $this->writePermissionPlugin('ca-seed-ns', [], [
            'database/seeders/CaSeedNsSeeder.php' => $seeder('Plugins\\CaSeedNs\\Database\\Seeders', 'CaSeedNsSeeder', 'ca.seeded.namespaced'),
        ]);
        $this->writePermissionPlugin('ca-seed-legacy', [], [
            'database/seeders/CaLegacyFixtureSeeder.php' => $seeder('', 'CaLegacyFixtureSeeder', 'ca.seeded.legacy'),
        ]);

        // The plugin autoloader (or an earlier install) already loaded both files:
        // require_once is then a no-op and the class must be inferred.
        require_once $this->root().'/ca-seed-ns/database/seeders/CaSeedNsSeeder.php';
        require_once $this->root().'/ca-seed-legacy/database/seeders/CaLegacyFixtureSeeder.php';

        $ns = app('cms.plugin_lifecycle')->activate('ca-seed-ns');
        $legacy = app('cms.plugin_lifecycle')->activate('ca-seed-legacy');

        $this->assertContains('Plugins\\CaSeedNs\\Database\\Seeders\\CaSeedNsSeeder', $ns->ranSeeders);
        $this->assertContains('CaLegacyFixtureSeeder', $legacy->ranSeeders);
        $this->assertTrue((bool) settings('ca.seeded.namespaced', false));
        $this->assertTrue((bool) settings('ca.seeded.legacy', false));
    }
}
