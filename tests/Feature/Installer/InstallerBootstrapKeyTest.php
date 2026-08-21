<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use PHPUnit\Framework\Attributes\Test;
use TheNguyen\CMS\Services\InstallerBootstrapKey;
use Tests\TestCase;

/**
 * CORE-INSTALLER-2 — the ephemeral pre-install bootstrap key.
 *
 * A file-backed key (storage/framework/tncms-installer.key) that lets a keyless
 * fresh extract boot cookie/session encryption BEFORE any .env exists. It must be
 * cryptographically secure, stable across pre-install requests, distinct per
 * installation, and removable — never the permanent APP_KEY.
 */
final class InstallerBootstrapKeyTest extends TestCase
{
    private InstallerBootstrapKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = new InstallerBootstrapKey;
        $this->key->forget();
    }

    protected function tearDown(): void
    {
        $this->key->forget();
        parent::tearDown();
    }

    #[Test]
    public function it_mints_a_valid_laravel_base64_key_only_on_demand(): void
    {
        $this->assertSame('', $this->key->peek(), 'No key exists before the first resolve().');

        $k = $this->key->resolve();

        $this->assertStringStartsWith('base64:', $k);
        $this->assertSame(32, strlen(base64_decode(substr($k, 7), true) ?: ''), 'Must be a 256-bit key.');
    }

    #[Test]
    public function it_is_stable_across_pre_install_requests(): void
    {
        $a = $this->key->resolve();
        $b = (new InstallerBootstrapKey)->resolve(); // Separate instance, same file.

        $this->assertSame($a, $b, 'The ephemeral key must be stable across requests.');
        $this->assertSame($a, $this->key->peek());
    }

    #[Test]
    public function each_installation_mints_a_distinct_ephemeral_key(): void
    {
        $a = $this->key->resolve();
        $this->key->forget();
        $b = $this->key->resolve();

        $this->assertNotSame($a, $b, 'A fresh installation mints a distinct ephemeral key.');
    }

    #[Test]
    public function it_lives_under_storage_framework_and_forget_removes_it(): void
    {
        $this->assertSame(
            storage_path('framework'.DIRECTORY_SEPARATOR.'tncms-installer.key'),
            $this->key->path(),
        );

        $this->key->resolve();
        $this->assertTrue($this->key->exists());

        $this->key->forget();
        $this->assertFalse($this->key->exists());
        $this->assertSame('', $this->key->peek());
    }
}
