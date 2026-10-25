<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\ExtensionTrust\Concerns\TrustedScriptFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;

/**
 * CORE-EXTENSION-TRUST S1 — Gate C / ADR D-10 / matrix T10.
 *
 * Identity is derived ONLY from the canonical realpath of a class file inside an
 * ACTIVE plugin directory under the configured plugins root.
 */
final class ExtensionIdentityResolverTest extends TestCase
{
    use RefreshDatabase;
    use TrustedScriptFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFixturePlugins();
        $this->cleanFixturePlugins();
    }

    private function resolver(): ExtensionIdentityResolver
    {
        return app('cms.extension_identity');
    }

    public function test_container_binding_is_the_public_resolver(): void
    {
        $this->assertInstanceOf(ExtensionIdentityResolver::class, app('cms.extension_identity'));
        $this->assertSame(app('cms.extension_identity'), app(ExtensionIdentityResolver::class));
    }

    public function test_active_plugin_class_resolves_to_its_slug(): void
    {
        $this->writePlugin('id-alpha');
        $this->activate('id-alpha');

        $caller = $this->caller('id-alpha');

        $this->assertSame('id-alpha', $this->resolver()->ownerOf($caller));
        $this->assertSame('id-alpha', $this->resolver()->ownerOf($caller::class));
    }

    public function test_inactive_plugin_class_resolves_to_null(): void
    {
        $this->writePlugin('id-inactive');
        $caller = $this->caller('id-inactive');

        $this->assertNull($this->resolver()->ownerOf($caller));
    }

    public function test_deactivation_removes_identity(): void
    {
        $this->writePlugin('id-cycle');
        $this->activate('id-cycle');
        $caller = $this->caller('id-cycle');
        $this->assertSame('id-cycle', $this->resolver()->ownerOf($caller));

        app('cms.extension')->deactivatePlugin('id-cycle');
        app('cms.extension')->flushRegistry();

        $this->assertNull($this->resolver()->ownerOf($caller));
    }

    public function test_classes_outside_plugins_and_internal_classes_resolve_to_null(): void
    {
        $this->assertNull($this->resolver()->ownerOf($this));
        $this->assertNull($this->resolver()->ownerOf(new \stdClass));
        $this->assertNull($this->resolver()->ownerOf(fn () => null));
        $this->assertNull($this->resolver()->ownerOf(new class {}));
        $this->assertNull($this->resolver()->ownerOf('Definitely\\Missing\\ClassName'));
        $this->assertNull($this->resolver()->ownerOf(app('cms.assets')));
    }

    public function test_manifest_slug_that_differs_from_directory_is_not_trusted(): void
    {
        // Folder "id-impostor" claims to be "id-victim" in its manifest.
        $this->writePlugin('id-victim', [], 'id-victim', 'id-impostor');
        app('cms.extension')->flushRegistry();
        // activatePlugin() looks the slug up by folder name, so force the active
        // list the way a tampered settings row would.
        settings()->set('extensions.active_plugins', ['id-victim'], 'array');
        app('cms.extension')->flushRegistry();

        $caller = $this->caller('id-victim', 'id-impostor');

        $this->assertNull($this->resolver()->ownerOf($caller));
    }

    public function test_false_prefix_directory_does_not_match(): void
    {
        $this->writePlugin('id-foo');
        $this->writePlugin('id-foobar');
        $this->activate('id-foo');

        // id-foobar is NOT active; its path starts with ".../id-foo" textually.
        $this->assertNull($this->resolver()->ownerOf($this->caller('id-foobar')));
        $this->assertSame('id-foo', $this->resolver()->ownerOf($this->caller('id-foo')));
    }

    public function test_plugin_directory_junction_escaping_the_root_is_not_trusted(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-s1-outside-'.getmypid();
        $outsidePlugin = $outside.DIRECTORY_SEPARATOR.'id-escape';
        File::ensureDirectoryExists($outsidePlugin.DIRECTORY_SEPARATOR.'src');
        File::put($outsidePlugin.DIRECTORY_SEPARATOR.'plugin.json', json_encode(['name' => 'E', 'slug' => 'id-escape', 'version' => '1', 'author' => 'x']));
        File::put($outsidePlugin.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Caller.php', "<?php\nnamespace S1Fixture\\EscapeOutside;\nfinal class Caller {}\n");

        $link = $this->fixtureRoot().DIRECTORY_SEPARATOR.'id-escape';

        if (! is_dir($link)) {
            if (DIRECTORY_SEPARATOR === '\\') {
                exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($outsidePlugin).' 2>NUL', $o, $code);
            } else {
                $code = @symlink($outsidePlugin, $link) ? 0 : 1;
            }

            if (($code ?? 1) !== 0 || ! is_dir($link)) {
                $this->markTestSkipped('Cannot create a directory junction/symlink on this host.');
            }
        }

        $this->activate('id-escape');
        require_once $link.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Caller.php';

        $this->assertContains('id-escape', app('cms.extension')->activePluginSlugs());
        $this->assertNull($this->resolver()->ownerOf(new \S1Fixture\EscapeOutside\Caller));
    }

    public function test_forged_labels_have_no_effect_on_identity(): void
    {
        $this->writePlugin('id-labels');
        $this->activate('id-labels');

        // Self-declared asset "source" labels never establish identity.
        app('cms.assets')->registerScript('x-label', '/x.js', [], null, 'frontend', 'footer', [], ['source_type' => 'plugin', 'source_name' => 'id-labels']);

        $this->assertNull($this->resolver()->ownerOf(app('cms.assets')));
        $this->assertNull($this->resolver()->ownerOfFile(__FILE__));
    }
}
