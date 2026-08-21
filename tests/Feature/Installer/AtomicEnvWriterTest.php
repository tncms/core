<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TheNguyen\CMS\Services\AtomicEnvWriter;
use Tests\TestCase;

/**
 * CORE-INSTALLER-2 — atomic .env writer (§15, §42).
 *
 * .env is written temp → flush → validate → rename so a failure never leaves a
 * partial, secret-bearing file: either the target is absent (fresh) or a previous
 * valid .env is preserved.
 */
final class AtomicEnvWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tncms-env-'.uniqid('', true);
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function it_writes_exact_content_atomically_and_leaves_no_temp(): void
    {
        $path = $this->dir.'/.env';

        (new AtomicEnvWriter)->write($path, "APP_KEY=base64:x\nDB_DATABASE=site\n");

        $this->assertFileExists($path);
        $this->assertSame("APP_KEY=base64:x\nDB_DATABASE=site\n", file_get_contents($path));
        $this->assertSame([], glob($this->dir.'/*.tmp') ?: [], 'No temp file may remain after a commit.');
    }

    #[Test]
    public function it_refuses_to_write_empty_content(): void
    {
        $this->expectException(RuntimeException::class);
        (new AtomicEnvWriter)->write($this->dir.'/.env', "   \n");
    }

    #[Test]
    public function a_move_failure_leaves_no_partial_env_when_target_is_absent(): void
    {
        $path = $this->dir.'/.env';

        try {
            $this->failingWriter()->write($path, "APP_KEY=base64:secret\n");
            $this->fail('Expected a move failure.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertFileDoesNotExist($path, 'A failed commit must leave NO partial .env.');
        $this->assertSame([], glob($this->dir.'/*.tmp') ?: [], 'No temp file may remain after a failure.');
    }

    #[Test]
    public function a_move_failure_preserves_a_previous_valid_env(): void
    {
        $path = $this->dir.'/.env';
        file_put_contents($path, "APP_KEY=base64:original\n");

        try {
            $this->failingWriter()->write($path, "APP_KEY=base64:rotated\n");
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame("APP_KEY=base64:original\n", file_get_contents($path), 'The previous .env must survive a failed replace.');
    }

    #[Test]
    public function it_replaces_an_existing_env_on_a_successful_resume(): void
    {
        $path = $this->dir.'/.env';
        (new AtomicEnvWriter)->write($path, "APP_KEY=base64:first\n");
        (new AtomicEnvWriter)->write($path, "APP_KEY=base64:second\nDB_DATABASE=x\n");

        $this->assertSame("APP_KEY=base64:second\nDB_DATABASE=x\n", file_get_contents($path));
        $this->assertFileDoesNotExist($path.'.bak', 'The backup must be cleaned after a successful replace.');
    }

    private function failingWriter(): AtomicEnvWriter
    {
        return new class extends AtomicEnvWriter
        {
            protected function move(string $tmp, string $path): void
            {
                @unlink($tmp);
                throw new RuntimeException('injected move failure');
            }
        };
    }
}
