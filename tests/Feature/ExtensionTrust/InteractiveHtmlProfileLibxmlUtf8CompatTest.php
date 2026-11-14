<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveHtmlProfile;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveProfileViolation;

/**
 * CORE-HTML-LIBXML-UTF8-COMPAT-R1: valid trusted-component HTML must not be classified as "malformed" only
 * because the DOM cross-check could not parse it. On libxml 2.11.x–2.12.x the http-equiv charset switch failed
 * ("Memory allocation failed"), the synthetic root disappeared and every valid output was rejected.
 */
final class InteractiveHtmlProfileLibxmlUtf8CompatTest extends TestCase
{
    private function profile(): InteractiveHtmlProfile
    {
        return new InteractiveHtmlProfile(
            origin: 'https://cms.example.test',
            readOnly: false,
            dataAttributes: [],
            formAuthorizer: fn (string $action, string $method): ?string => [$action, $method] === ['/contact/submit', 'POST'] ? null : 'form target: undeclared',
        );
    }

    /** @return array<string, array{string}> */
    public static function validCases(): array
    {
        return [
            'ascii' => ['<section class="wrap"><p>hi</p></section>'],
            'vietnamese and symbols' => ['<section><h2>Liên hệ — “Hỗ trợ”</h2><p>Phiên bản 2.0 &amp; ✓ 🚀</p></section>'],
            'list' => ['<ul><li>Câu hỏi?</li><li>Trả lời “có” ✓</li></ul>'],
            'form' => ['<form method="post" action="/contact/submit"><input type="hidden" name="_token" value="t">'
                .'<label for="n">Họ tên</label><input type="text" id="n" name="name"><button type="submit">Gửi ✓</button></form>'],
        ];
    }

    #[DataProvider('validCases')]
    public function test_valid_unicode_component_html_is_accepted(string $html): void
    {
        try {
            $out = $this->profile()->validate($html);
        } catch (InteractiveProfileViolation $e) {
            $this->fail('rejected as '.$e->reason);
        }

        $this->assertNotSame('', $out);
        $this->assertStringNotContainsString('<?xml', $out);
        $this->assertStringNotContainsString('data-cms-profile-root', $out);
        foreach (['Liên hệ', 'Phiên bản 2.0', '✓', '🚀', 'Câu hỏi?', 'Họ tên'] as $text) {
            if (str_contains($html, $text)) {
                $this->assertStringContainsString($text, $out);
            }
        }
    }

    public function test_genuinely_malformed_html_is_still_rejected(): void
    {
        foreach (['<section><p>open', '<p>a</p></div><p>b</p>', '<script>alert(1)</script>'] as $html) {
            try {
                $this->profile()->validate($html);
                $this->fail('accepted: '.$html);
            } catch (InteractiveProfileViolation $e) {
                $this->assertStringNotContainsString('<', $e->reason);
            }
        }
    }
}
