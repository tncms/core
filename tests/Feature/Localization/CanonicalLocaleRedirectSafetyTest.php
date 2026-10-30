<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\RedirectDefaultLocalePrefix;

/**
 * CORE-PLATFORM-SEAMS-R2 Gate I (security HIGH-1) — the default-locale prefix
 * redirect builds Location from DECODED path segments. Backslashes and control
 * characters must never reach the header raw: browsers read "/\evil.com" and
 * "/<TAB>/evil.com" as protocol-relative, i.e. an open redirect.
 */
final class CanonicalLocaleRedirectSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function location(string $uri): ?string
    {
        $response = app(RedirectDefaultLocalePrefix::class)->handle(Request::create($uri), fn () => response('passed'));

        return $response->isRedirect() ? $response->headers->get('Location') : null;
    }

    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'encoded backslash' => ['/vi/%5Cevil.com'],
            'double encoded backslash' => ['/vi/%5C%5Cevil.com'],
            'backslash then slash' => ['/vi/%5C/evil.com'],
            'tab' => ['/vi/%09/evil.com'],
            'newline' => ['/vi/%0A/evil.com'],
            'carriage return' => ['/vi/%0D/evil.com'],
            'encoded slashes' => ['/vi/%2F%2Fevil.com'],
            'null byte' => ['/vi/%00/evil.com'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_location_is_always_a_same_origin_path(string $uri): void
    {
        $location = (string) $this->location($uri);

        $this->assertStringStartsWith('/', $location);
        $this->assertDoesNotMatchRegularExpression('#^/[/\\\\]#', $location, 'protocol-relative');
        $this->assertDoesNotMatchRegularExpression('#[\\\\\x00-\x1F\x7F]#', $location, 'raw backslash or control character');

        // What a browser does: strip tab/newline, treat "\" as "/", resolve against the site.
        $browser = str_replace('\\', '/', (string) preg_replace('/[\t\r\n]/', '', $location));
        $this->assertDoesNotMatchRegularExpression('#^//#', $browser, 'browser would leave the site');
    }

    public function test_ordinary_and_unicode_paths_are_byte_identical_to_before(): void
    {
        $this->assertSame('/docs?x=1', $this->location('/vi/docs?x=1'));
        $this->assertSame('/bài-viết/xin-chào', $this->location('/vi/'.rawurlencode('bài-viết').'/'.rawurlencode('xin-chào')));
        $this->assertSame('/blog/a', $this->location('/vi/blog/a'));
        $this->assertNull($this->location('/en/docs'), 'non-default locale untouched');
    }
}
