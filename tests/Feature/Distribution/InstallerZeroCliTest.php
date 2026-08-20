<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use TheNguyen\CMS\Http\Middleware\InstallerSession;
use TheNguyen\CMS\Services\InstallerManager;
use Tests\TestCase;

/**
 * CORE-DIST-1-H2 — regression guards for the zero-CLI installer fixes uncovered
 * by end-to-end HTTP install of the extracted shared-hosting artifact.
 *
 * Both defects made the browser wizard unusable without a terminal, and both
 * were invisible to a "GET /install = 200" check:
 *   1. The session cookie name derives from APP_NAME; the DB step writes APP_NAME
 *      to .env, so the next request looked for a renamed cookie and lost the
 *      verified DB config. InstallerSession must pin a stable cookie name.
 *   2. On a fresh keyless extract, cookie/session encryption threw before the
 *      wizard could render. InstallerManager::ensureRuntimeAppKey bootstraps a
 *      key; it must be a safe no-op once a key exists.
 */
final class InstallerZeroCliTest extends TestCase
{
    #[Test]
    public function installer_session_pins_a_stable_cookie_and_file_driver(): void
    {
        // Simulate an operator-chosen APP_NAME that would otherwise rename the
        // session cookie (Str::slug('Acme Corp').'-session' = 'acme-corp-session').
        config([
            'app.name' => 'Acme Corp',
            'session.driver' => 'database',
            'session.cookie' => 'acme-corp-session',
        ]);

        $passed = false;
        (new InstallerSession)->handle(Request::create('/install'), function () use (&$passed) {
            $passed = true;

            return new \Symfony\Component\HttpFoundation\Response('ok');
        });

        $this->assertTrue($passed, 'Middleware must call the next handler.');
        $this->assertSame(
            InstallerSession::COOKIE,
            config('session.cookie'),
            'Installer must pin a stable cookie name so an APP_NAME write cannot orphan the wizard session.',
        );
        $this->assertSame('file', config('session.driver'), 'Installer must force a DB-free file session.');
        $this->assertSame('tncms_installer_session', InstallerSession::COOKIE);
    }

    #[Test]
    public function ensure_runtime_app_key_is_a_safe_noop_when_a_key_exists(): void
    {
        // The test environment always has an APP_KEY. ensureRuntimeAppKey must
        // then do nothing — crucially it must NOT rewrite .env — so it is safe to
        // run on every pre-install request without side effects once installed.
        $this->assertNotSame('', (string) config('app.key'));

        $envPath = base_path('.env');
        $before = is_file($envPath) ? filemtime($envPath) : null;
        $keyBefore = (string) config('app.key');

        app(InstallerManager::class)->ensureRuntimeAppKey();

        $this->assertSame($keyBefore, (string) config('app.key'), 'A present key must be left untouched.');
        if ($before !== null) {
            clearstatcache(true, $envPath);
            $this->assertSame($before, filemtime($envPath), '.env must not be rewritten when a key already exists.');
        }
    }
}
