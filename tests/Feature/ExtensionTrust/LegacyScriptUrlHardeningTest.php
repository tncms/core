<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Services\AssetRegistry;
use TheNguyen\CMS\Services\ScriptManager;

/**
 * CORE-EXTENSION-TRUST S1 — Gate G / ADR D-8 / matrix T8 / finding F-G3-SCHEME.
 *
 * Before S1, ScriptManager checked only the HOST of an absolute URL, so
 * "data://www.google.com/,<js>" (executes in Chrome), "http://", "ftp://",
 * userinfo and custom ports on an allowlisted host were rendered. AssetRegistry
 * accepted userinfo. Both now require HTTPS on the default port, no userinfo.
 * Legitimate HTTPS, relative and same-origin assets keep working; the inline
 * guard (D-11) is unchanged.
 */
final class LegacyScriptUrlHardeningTest extends TestCase
{
    private function onRequest(string $url): void
    {
        app()->instance('request', Request::create($url));
    }

    /** @return array<string, array{string}> */
    public static function scriptManagerHostile(): array
    {
        return [
            'data authority (F-G3-SCHEME)' => ['data://www.google.com/,alert(1)'],
            'javascript authority' => ['javascript://www.google.com/%0aalert(1)'],
            'http downgrade' => ['http://www.google.com/x.js'],
            'ftp' => ['ftp://www.google.com/x.js'],
            'userinfo' => ['https://user:pass@www.google.com/x.js'],
            'userinfo no pass' => ['https://evil.example@www.google.com/x.js'],
            'nonstandard port' => ['https://www.google.com:8443/x.js'],
            'mixed case scheme' => ['HTTPS://www.google.com/x.js'],
            'protocol relative' => ['//www.google.com/x.js'],
            'not allowlisted' => ['https://challenges.cloudflare.com/turnstile/v0/api.js'],
        ];
    }

    #[DataProvider('scriptManagerHostile')]
    public function test_script_manager_rejects_hostile_external_urls(string $url): void
    {
        $manager = new ScriptManager;

        $this->assertFalse($manager->externalFooter('k', $url));
        $this->assertFalse($manager->externalHead('k', $url));
        $this->assertStringNotContainsString('<script', $manager->renderFooter());
    }

    /** @return array<string, array{string}> */
    public static function scriptManagerLegitimate(): array
    {
        return [
            'gtm https' => ['https://www.googletagmanager.com/gtag/js?id=G-TEST'],
            'jsdelivr https' => ['https://cdn.jsdelivr.net/npm/x@1.0.0/x.js'],
            'explicit 443' => ['https://cdnjs.cloudflare.com:443/ajax/libs/x.js'],
            'relative' => ['/js/app.js'],
        ];
    }

    #[DataProvider('scriptManagerLegitimate')]
    public function test_script_manager_still_accepts_legitimate_urls(string $url): void
    {
        $manager = new ScriptManager;

        $this->assertTrue($manager->externalFooter('k', $url));
        $this->assertStringContainsString('<script src="'.e($url).'"', $manager->renderFooter());
    }

    /** @return array<string, array{string}> */
    public static function assetRegistryHostile(): array
    {
        return [
            'userinfo allowlisted' => ['https://u:p@cdnjs.cloudflare.com/x.js'],
            'userinfo user only' => ['https://u@cdnjs.cloudflare.com/x.js'],
            'http allowlisted' => ['http://cdnjs.cloudflare.com/x.js'],
            'port allowlisted' => ['https://cdnjs.cloudflare.com:8443/x.js'],
            'ftp same host' => ['ftp://s1.test/x.js'],
            'same host other port' => ['https://s1.test:8443/x.js'],
            'same host userinfo' => ['https://u@s1.test/x.js'],
            'http same host on https page' => ['http://s1.test/x.js'],
            'data authority' => ['data://cdnjs.cloudflare.com/,alert(1)'],
            'javascript' => ['javascript:alert(1)'],
            'protocol relative' => ['//cdnjs.cloudflare.com/x.js'],
        ];
    }

    #[DataProvider('assetRegistryHostile')]
    public function test_asset_registry_rejects_hostile_urls(string $url): void
    {
        $this->onRequest('https://s1.test/page');
        $registry = new AssetRegistry;

        $this->assertFalse($registry->registerScript('k', $url));
    }

    /** @return array<string, array{string, string}> */
    public static function assetRegistryLegitimate(): array
    {
        return [
            'allowlisted https' => ['https://s1.test/page', 'https://cdnjs.cloudflare.com/ajax/libs/x.js'],
            'allowlisted 443' => ['https://s1.test/page', 'https://cdn.jsdelivr.net:443/npm/x.js'],
            'relative' => ['https://s1.test/page', '/themes/default/app.css'],
            'bare relative' => ['https://s1.test/page', 'css/app.css'],
            'same origin https' => ['https://s1.test/page', 'https://s1.test/themes/default/app.css'],
            'same origin http on http site' => ['http://s1.test/page', 'http://s1.test/themes/default/app.css'],
            'same origin https on http site' => ['http://s1.test/page', 'https://s1.test/themes/default/app.css'],
            'same origin explicit own port' => ['http://s1.test:8080/page', 'http://s1.test:8080/themes/default/app.css'],
        ];
    }

    #[DataProvider('assetRegistryLegitimate')]
    public function test_asset_registry_still_accepts_legitimate_urls(string $page, string $url): void
    {
        $this->onRequest($page);
        $registry = new AssetRegistry;

        $this->assertTrue($registry->registerScript('k', $url));
        $this->assertTrue($registry->registerStyle('s', $url));
    }

    public function test_d11_inline_guard_is_unchanged(): void
    {
        $registry = new AssetRegistry;

        // Identifier containing "on…=" is still refused (documented: use a neutral name).
        $this->assertFalse($registry->inlineScript('a', 'window.onloadTurnstileCallback = function () {};'));
        $this->assertTrue($registry->inlineScript('b', 'window.tncTurnstileReady = function () {};'));
        $this->assertFalse((new ScriptManager)->footer('c', 'window.onloadTurnstileCallback = function () {};'));
    }
}
