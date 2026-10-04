<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\MenuUrlPolicy;

/**
 * CORE-MENU-URL-1 — adversarial unit corpus for the canonical menu-URL safety
 * authority. These assert the navigation-scheme classification directly (no DB,
 * no HTTP): every executable scheme — including obfuscated and mixed-case
 * variants — must be rejected, while every legitimate relative/absolute
 * navigation target must be accepted. sanitize() must null-map exactly what
 * isSafe() rejects and never rewrite an accepted value.
 */
final class MenuUrlPolicyTest extends TestCase
{
    /**
     * Legitimate navigation targets a theme may safely place in an href.
     *
     * @return array<string, array{string}>
     */
    public static function safeUrls(): array
    {
        return [
            'root relative' => ['/'],
            'relative path' => ['/about'],
            'nested path' => ['/blog/2026/post-slug'],
            'path with query' => ['/search?q=laravel'],
            'query only' => ['?page=2'],
            'fragment only' => ['#section'],
            'hash placeholder (dropdown parent)' => ['#'],
            'relative no slash' => ['about/team'],
            'protocol-relative host' => ['//cdn.example.com/page'],
            'http absolute' => ['http://example.com'],
            'https absolute' => ['https://example.com/path?x=1#y'],
            'https uppercase scheme' => ['HTTPS://example.com'],
            'mailto' => ['mailto:info@example.com'],
            'tel' => ['tel:+15551234567'],
            // A dangerous word appearing only as data, not as the scheme, is safe.
            'javascript as query value' => ['/search?q=javascript:test'],
            'javascript as path segment' => ['/docs/javascript:guide'],
            'javascript as fragment' => ['/page#javascript:note'],
            'leading/trailing whitespace trimmed around safe path' => ['  /about  '],
        ];
    }

    /**
     * Values that must never be emitted as an executable navigation href.
     *
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript lower' => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'javascript upper' => ['JAVASCRIPT:alert(document.cookie)'],
            'javascript leading space' => [' javascript:alert(1)'],
            'javascript leading tab' => ["\tjavascript:alert(1)"],
            'javascript embedded newline' => ["java\nscript:alert(1)"],
            'javascript embedded carriage return' => ["java\r\nscript:alert(1)"],
            'javascript embedded null byte' => ["java\0script:alert(1)"],
            'javascript embedded vertical tab' => ["java\x0bscript:alert(1)"],
            'javascript embedded form feed' => ["java\x0cscript:alert(1)"],
            'javascript with spaces around colon word' => ["  jav\tascript:alert(1)"],
            'data html' => ['data:text/html,<script>alert(1)</script>'],
            'data base64' => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'blob' => ['blob:https://example.com/uuid'],
            'unknown custom scheme' => ['steam://run/12345'],
            'unknown app scheme' => ['intent://evil#Intent;scheme=http;end'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_safe_urls_are_accepted(string $url): void
    {
        $this->assertTrue(
            MenuUrlPolicy::isSafe($url),
            "Expected safe navigation URL to be accepted: {$url}",
        );
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        $this->assertFalse(
            MenuUrlPolicy::isSafe($url),
            "Expected unsafe navigation URL to be rejected: {$url}",
        );
    }

    public function test_empty_and_whitespace_are_not_linkable(): void
    {
        $this->assertFalse(MenuUrlPolicy::isSafe(''));
        $this->assertFalse(MenuUrlPolicy::isSafe('   '));
        $this->assertFalse(MenuUrlPolicy::isSafe("\t\n"));
    }

    #[DataProvider('safeUrls')]
    public function test_sanitize_returns_accepted_value_unchanged(string $url): void
    {
        // sanitize never rewrites a safe value — it returns it verbatim so the
        // stored URL and the rendered URL stay identical.
        $this->assertSame($url, MenuUrlPolicy::sanitize($url));
    }

    #[DataProvider('unsafeUrls')]
    public function test_sanitize_null_maps_rejected_value(string $url): void
    {
        $this->assertNull(MenuUrlPolicy::sanitize($url));
    }

    public function test_sanitize_null_input_is_null(): void
    {
        $this->assertNull(MenuUrlPolicy::sanitize(null));
    }

    public function test_sanitize_empty_string_is_null(): void
    {
        // Nothing to navigate to → not linkable (but not dangerous).
        $this->assertNull(MenuUrlPolicy::sanitize(''));
        $this->assertNull(MenuUrlPolicy::sanitize('   '));
    }

    public function test_safe_scheme_allowlist_is_exactly_navigation_safe(): void
    {
        $this->assertSame(['http', 'https', 'mailto', 'tel'], MenuUrlPolicy::SAFE_SCHEMES);
    }
}
