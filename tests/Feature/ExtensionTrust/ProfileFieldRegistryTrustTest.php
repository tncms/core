<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ReservedProfileFieldKeyException;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Profile\UserProfileFieldRegistry;

/**
 * CORE-EXTENSION-TRUST S3 (G-3) — a plugin's profile-field registrations carry a
 * server-derived source (`plugin:{slug}`, from the S1 identity resolver) and may
 * only use the plugin's own namespace. Core/host callers keep the declared source.
 *
 * Fixture plugins are real directories under a per-process temp plugins root,
 * so the call-site identity comes from genuine realpaths.
 */
final class ProfileFieldRegistryTrustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.paths.plugins' => $this->root()]);
        app('cms.extension')->flushRegistry();
        app()->forgetInstance('cms.user_profile_fields');
        // A host-registered group, so a field registration can only fail on the trust rule.
        $this->registry()->registerGroup('host_bucket', [], 'admin');
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s3-plugins-'.getmypid();
        File::ensureDirectoryExists($root);

        return $root;
    }

    private function registry(): UserProfileFieldRegistry
    {
        return app('cms.user_profile_fields');
    }

    /** Write (once per process) and return a fixture plugin's own registrar code. */
    private function plugin(string $slug, bool $activate = true): object
    {
        $dir = $this->root().DIRECTORY_SEPARATOR.$slug;
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'src');
        File::put($dir.DIRECTORY_SEPARATOR.'plugin.json', (string) json_encode(['name' => 'S3 '.$slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 'S3 Tests'])."\n");

        $namespace = 'S3Fixture\\'.Str::studly($slug);
        $file = $dir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Registrar.php';
        File::put($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class Registrar
{
    public function field(string \$key, array \$definition = [], ?string \$source = null): void
    {
        \$source === null
            ? app('cms.user_profile_fields')->registerField(\$key, \$definition)
            : app('cms.user_profile_fields')->registerField(\$key, \$definition, \$source);
    }

    public function group(string \$key, ?string \$source = null): void
    {
        \$source === null
            ? app('cms.user_profile_fields')->registerGroup(\$key)
            : app('cms.user_profile_fields')->registerGroup(\$key, [], \$source);
    }
}

PHP);

        app('cms.extension')->flushRegistry();

        if ($activate) {
            $this->assertTrue(app('cms.extension')->activatePlugin($slug), "activate {$slug}");
            app('cms.extension')->flushRegistry();
        }

        $fqcn = $namespace.'\\Registrar';

        if (! class_exists($fqcn, false)) {
            require_once $file;
        }

        return new $fqcn;
    }

    private function def(string $group): array
    {
        return ['group' => $group, 'type' => 'text', 'visibility' => 'public'];
    }

    public function test_plugin_registration_gets_a_server_derived_source(): void
    {
        $code = $this->plugin('s3-crm');
        $code->group('s3_crm');
        $code->field('s3_crm.job_title', $this->def('s3_crm'));

        $this->assertSame('plugin:s3-crm', $this->registry()->get('s3_crm.job_title')?->source);

        $this->registry()->reconcile();
        $this->assertSame('plugin:s3-crm', UserProfileField::query()->where('key', 's3_crm.job_title')->value('source'));
    }

    public function test_a_plugin_may_name_its_own_source_explicitly(): void
    {
        $code = $this->plugin('s3-crm');
        $code->group('s3_crm_extra', 's3-crm');
        $code->field('s3_crm.a', $this->def('host_bucket'), 's3-crm');
        $code->field('s3_crm.b', $this->def('s3_crm_extra'), 'plugin:s3-crm');
        $this->assertSame('plugin:s3-crm', $this->registry()->groups()['s3_crm_extra']['source'] ?? null);

        $this->assertSame('plugin:s3-crm', $this->registry()->get('s3_crm.a')?->source);
        $this->assertSame('plugin:s3-crm', $this->registry()->get('s3_crm.b')?->source);
    }

    public function test_a_plugin_cannot_claim_core(): void
    {
        $code = $this->plugin('s3-crm');

        $this->expectException(ReservedProfileFieldKeyException::class);
        $code->field('core.public_profile', $this->def('host_bucket'), 'core');
    }

    public function test_a_plugin_cannot_claim_another_plugins_source(): void
    {
        $code = $this->plugin('s3-crm');

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->expectExceptionMessageMatches('/source/');
        $code->field('s3_crm.job_title', $this->def('host_bucket'), 'plugin:s3-billing');
    }

    public function test_a_plugin_cannot_register_into_another_plugins_namespace(): void
    {
        $this->plugin('s3-billing');
        $code = $this->plugin('s3-crm');

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->expectExceptionMessageMatches('/namespace/');
        $code->field('s3_billing.card_last4', $this->def('host_bucket'));
    }

    public function test_a_plugin_cannot_register_a_group_outside_its_namespace(): void
    {
        $code = $this->plugin('s3-crm');

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->expectExceptionMessageMatches('/namespace/');
        $code->group('billing');
    }

    public function test_an_inactive_plugin_cannot_register(): void
    {
        $code = $this->plugin('s3-dormant', activate: false);

        $this->expectException(InvalidProfileFieldDefinitionException::class);
        $this->expectExceptionMessageMatches('/active plugin/');
        $code->field('s3_dormant.x', $this->def('host_bucket'));
    }

    public function test_core_and_host_callers_keep_the_declared_source(): void
    {
        $this->registry()->registerField('custom.headline', $this->def('host_bucket'), 'plugin:legacy');
        $this->registry()->registerField('core.example', $this->def('host_bucket'));

        $this->assertSame('plugin:legacy', $this->registry()->get('custom.headline')?->source);
        $this->assertSame('core', $this->registry()->get('core.example')?->source);
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeFixtureFilesystem(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s3-plugins-'.getmypid();

        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}
