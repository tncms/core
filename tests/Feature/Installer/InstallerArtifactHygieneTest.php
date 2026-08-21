<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CORE-INSTALLER-2 — distribution verifier gates (§36).
 *
 * No shipped artifact may carry a real .env, the ephemeral installer bootstrap
 * key, an install lock, or a fixed universal APP_KEY baked into runtime config.
 * These lock the *artifact*; per-installation runtime behavior is proven by the
 * live extracted-package HTTP certification.
 */
final class InstallerArtifactHygieneTest extends TestCase
{
    private const TOOLS = 'tools/distribution';

    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path(self::TOOLS.'/verify.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    #[Test]
    public function a_clean_tree_passes_the_new_bootstrap_gates(): void
    {
        $manifest = $this->manifest();
        $root = $this->tree([
            'config/app.php' => "<?php return ['key' => env('APP_KEY')];",
            '.env.example' => "APP_NAME=Site\nAPP_KEY=\n",
        ]);

        foreach ([
            'installer:no-ephemeral-bootstrap-key',
            'installer:no-install-lock',
            'installer:no-fixed-key-in-config',
        ] as $check) {
            $this->assertTrue($this->check($manifest, $root, $check), "Clean tree must pass {$check}.");
        }
    }

    #[Test]
    public function the_ephemeral_bootstrap_key_is_forbidden(): void
    {
        $manifest = $this->manifest();
        $root = $this->tree([
            'storage/framework/tncms-installer.key' => 'base64:'.base64_encode(str_repeat('A', 32)),
        ]);

        $this->assertFalse(
            $this->check($manifest, $root, 'installer:no-ephemeral-bootstrap-key'),
            'A shipped ephemeral bootstrap key must fail the verifier.',
        );
    }

    #[Test]
    public function an_install_lock_is_forbidden(): void
    {
        $manifest = $this->manifest();
        $root = $this->tree(['storage/app/tncms-installed' => 'installed']);

        $this->assertFalse(
            $this->check($manifest, $root, 'installer:no-install-lock'),
            'A shipped install lock must fail the verifier.',
        );
    }

    #[Test]
    public function a_fixed_key_in_runtime_config_is_forbidden(): void
    {
        $manifest = $this->manifest();
        $root = $this->tree([
            'config/app.php' => "<?php return ['key' => 'base64:".base64_encode(str_repeat('A', 32))."'];",
        ]);

        $this->assertFalse(
            $this->check($manifest, $root, 'installer:no-fixed-key-in-config'),
            'A fixed universal key in config/app.php must fail the verifier.',
        );
    }

    #[Test]
    public function a_shipped_real_env_is_forbidden(): void
    {
        $manifest = $this->manifest();
        $root = $this->tree(['.env' => "APP_KEY=base64:leak\n"]);

        $this->assertFalse(
            $this->check($manifest, $root, 'scan:no-secret-or-private-files'),
            'A shipped .env must fail the secret scan.',
        );
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return require base_path(self::TOOLS.'/manifest.php');
    }

    /** @param array<string, mixed> $manifest */
    private function check(array $manifest, string $root, string $name): bool
    {
        $res = tncms_core_verify($root, $manifest, 'source', null);
        foreach ($res['checks'] as $c) {
            if ($c['name'] === $name) {
                return (bool) $c['ok'];
            }
        }
        $this->fail("check not found: {$name}");
    }

    /** @param array<string, string> $files */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir().'/tncms-installer-hyg-'.uniqid('', true);
        @mkdir($root, 0777, true);
        $this->temps[] = $root;

        foreach ($files as $rel => $body) {
            $abs = $root.'/'.$rel;
            @mkdir(\dirname($abs), 0777, true);
            file_put_contents($abs, $body);
        }

        return str_replace('\\', '/', $root);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
