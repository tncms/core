<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use TheNguyen\CMS\Services\InstallerManager;
use Tests\TestCase;

/**
 * CORE-INSTALLER-2 — the single install-commit environment authority.
 *
 * commitEnvironment() is the ONLY place a permanent .env is created: it generates
 * a unique permanent APP_KEY on a fresh commit, and REUSES (never rotates) an
 * existing key on a resume. Tests target a temp .env via the envPath() seam — the
 * running source test app legitimately has its own dev .env at base_path.
 */
final class EnvironmentCommitTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private string|false $origKey;

    protected function setUp(): void
    {
        parent::setUp();
        // commitEnvironment mutates the process APP_KEY env; snapshot it so it can
        // never leak to another test (Dotenv is immutable and would not restore it).
        $this->origKey = getenv('APP_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->origKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        } else {
            putenv('APP_KEY='.$this->origKey);
            $_ENV['APP_KEY'] = $this->origKey;
            $_SERVER['APP_KEY'] = $this->origKey;
        }

        foreach ($this->paths as $p) {
            @unlink($p);
            @unlink($p.'.bak');
        }

        parent::tearDown();
    }

    #[Test]
    public function commit_creates_env_with_a_valid_permanent_key_and_safe_defaults(): void
    {
        $path = $this->tmpEnv();
        $this->assertFileDoesNotExist($path, 'No .env exists before the commit (FRESH).');

        $this->manager($path)->commitEnvironment($this->config());

        $this->assertFileExists($path);
        $env = (string) file_get_contents($path);

        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('DB_DATABASE=site_db', $env);
        $this->assertStringContainsString('ADMIN_PATH=admin', $env);

        $key = $this->keyOf($env);
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7), true) ?: ''), 'Permanent key must be 256-bit.');
    }

    #[Test]
    public function two_independent_installs_get_different_permanent_keys(): void
    {
        $a = $this->tmpEnv();
        $b = $this->tmpEnv();

        $this->manager($a)->commitEnvironment($this->config());
        $this->manager($b)->commitEnvironment($this->config());

        $this->assertNotSame(
            $this->keyOf((string) file_get_contents($a)),
            $this->keyOf((string) file_get_contents($b)),
            'Install A and Install B must get different keys (K1 != K2).',
        );
    }

    #[Test]
    public function a_resume_reuses_the_existing_key_and_never_rotates_it(): void
    {
        $path = $this->tmpEnv();
        $manager = $this->manager($path);

        $manager->commitEnvironment($this->config());
        $k1 = $this->keyOf((string) file_get_contents($path));

        // Post-env retry with a changed value: the key must be identical.
        $manager->commitEnvironment($this->config('other_db'));
        $env = (string) file_get_contents($path);

        $this->assertSame($k1, $this->keyOf($env), 'Retry after commit must never rotate the key.');
        $this->assertStringContainsString('DB_DATABASE=other_db', $env);
    }

    #[Test]
    public function has_committed_env_tracks_the_state_transition(): void
    {
        $path = $this->tmpEnv();
        $manager = $this->manager($path);

        $this->assertFalse($manager->hasCommittedEnv(), 'FRESH: no committed env.');

        $manager->commitEnvironment($this->config());

        $this->assertTrue($manager->hasCommittedEnv(), 'CONFIG_COMMITTED_NOT_INSTALLED: env committed.');
    }

    private function manager(string $envPath): InstallerManager
    {
        $this->paths[] = $envPath;

        return new class($envPath) extends InstallerManager
        {
            public function __construct(private readonly string $envPathOverride) {}

            protected function envPath(): string
            {
                return $this->envPathOverride;
            }
        };
    }

    /** @return array<string, mixed> */
    private function config(string $db = 'site_db'): array
    {
        return [
            'app_name' => 'My Site',
            'app_url' => 'https://example.test',
            'app_timezone' => 'UTC',
            'default_language' => 'en',
            'admin_path' => 'admin',
            'db' => [
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => $db,
                'username' => 'u',
                'password' => 'p',
            ],
        ];
    }

    private function tmpEnv(): string
    {
        return sys_get_temp_dir().'/tncms-commit-'.uniqid('', true).'.env';
    }

    private function keyOf(string $env): string
    {
        preg_match('/^APP_KEY=(\S+)$/m', $env, $m);

        return $m[1] ?? '';
    }
}
