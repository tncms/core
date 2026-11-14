<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-04: Core had no way to declare trusted reverse proxies short of editing
 * bootstrap/app.php. TRUSTED_PROXIES (config/trustedproxy.php, read by the framework TrustProxies middleware)
 * is now the operator switch. It defaults to empty — no forwarded header is trusted — and is never `*` unless the
 * operator sets it explicitly. Spoofed forwarded headers from an untrusted peer stay ignored.
 */
final class TrustedProxyConfigurationTest extends TestCase
{
    private const FORWARDED = [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
    ];

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    /** @return array{ip: string|null, secure: bool} */
    private function probe(string $peer, string $host = 'localhost'): array
    {
        $this->assertContains(TrustProxies::class, app(Kernel::class)->getGlobalMiddleware(), 'TrustProxies must stay in the global stack');

        $request = Request::create('http://'.$host.'/probe', 'GET', [], [], [], ['REMOTE_ADDR' => $peer] + self::FORWARDED);

        return (new TrustProxies)->handle($request, static fn (Request $r): array => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);
    }

    public function test_the_shipped_default_trusts_no_proxy(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'), 'the shipped default must not trust any proxy');

        $this->assertSame(['ip' => '10.0.0.5', 'secure' => false], $this->probe('10.0.0.5'));
    }

    /**
     * CORE-POST40-OPEN-DEBT-DECISION-CLOSURE-R1 / SEC-PROXY-HOST-1: when trustedproxy.proxies is null the framework
     * trusts EVERY peer if the request Host ends in .on-forge.com / .on-vapor.com (or on Laravel Cloud). The Host
     * header is client-controlled, so "empty" must mean an explicit empty list, never null.
     */
    public function test_the_default_trusts_no_proxy_whatever_host_the_client_sends(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'), 'an empty TRUSTED_PROXIES must be an explicit empty list');

        foreach (['evil.on-forge.com', 'evil.on-vapor.com', 'localhost'] as $host) {
            $this->assertSame(['ip' => '10.9.9.9', 'secure' => false], $this->probe('10.9.9.9', $host), "spoofed forwarded headers trusted for Host {$host}");
        }

        $previous = $_SERVER['LARAVEL_CLOUD'] ?? null;
        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            $this->assertSame(['ip' => '10.9.9.9', 'secure' => false], $this->probe('10.9.9.9'), 'the Laravel Cloud fallback must not apply either');
        } finally {
            if ($previous === null) {
                unset($_SERVER['LARAVEL_CLOUD']);
            } else {
                $_SERVER['LARAVEL_CLOUD'] = $previous;
            }
        }
    }

    public function test_a_configured_proxy_is_trusted_and_an_unlisted_peer_is_not(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5, 192.168.10.0/24']);

        $this->assertSame(['ip' => '203.0.113.7', 'secure' => true], $this->probe('10.0.0.5'));
        $this->assertSame(['ip' => '203.0.113.7', 'secure' => true], $this->probe('192.168.10.20'));
        $this->assertSame(['ip' => '10.9.9.9', 'secure' => false], $this->probe('10.9.9.9'), 'spoofed headers from an untrusted peer are ignored');
    }

    public function test_the_config_file_reads_the_env_switch_and_keeps_empty_as_untrusted(): void
    {
        $load = static function (string|false $value): mixed {
            $value === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES='.$value);
            $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value === false ? null : $value;

            try {
                return (require base_path('config/trustedproxy.php'))['proxies'];
            } finally {
                putenv('TRUSTED_PROXIES');
                unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
            }
        };

        $this->assertSame([], $load(false));
        $this->assertSame([], $load(''));
        $this->assertSame([], $load('   '));
        $this->assertSame('10.0.0.5,10.0.0.6', $load('10.0.0.5,10.0.0.6'));
        $this->assertSame('*', $load('*'), 'trust-all is only ever an explicit operator choice');
    }
}
