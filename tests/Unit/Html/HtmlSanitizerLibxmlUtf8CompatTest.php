<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Services\HtmlSanitizer;

/**
 * CORE-HTML-LIBXML-UTF8-COMPAT-R1: valid UTF-8 HTML must survive sanitization on every supported libxml.
 *
 * On libxml 2.11.x–2.12.x an http-equiv charset switch at the start of the parse fails with "Memory allocation
 * failed" and drops the synthetic root, so cms_html() silently returned ''. These cases must keep producing the
 * exact output the sanitizer produces on unaffected libxml versions, and charset declarations found inside the
 * content must never re-decode the rest of the fragment.
 */
final class HtmlSanitizerLibxmlUtf8CompatTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function utf8Cases(): array
    {
        return [
            'simple paragraph' => ['<p>hi</p>', '<p>hi</p>'],
            'vietnamese' => ['<p>Phiên bản 2.0 — Tiếng Việt: ắ ằ ẳ ẵ ặ đ Đ ơ ư</p>', '<p>Phiên bản 2.0 — Tiếng Việt: ắ ằ ẳ ẵ ặ đ Đ ơ ư</p>'],
            'curly quotes' => ['<p>“ngoặc” ‘đơn’</p>', '<p>“ngoặc” ‘đơn’</p>'],
            'em and en dash' => ['<p>a — b – c</p>', '<p>a — b – c</p>'],
            'encoded ampersand' => ['<p>A &amp; B</p>', '<p>A &amp; B</p>'],
            'raw ampersand' => ['<p>A & B</p>', '<p>A &amp; B</p>'],
            'checkmark' => ['<p>✓ done</p>', '<p>✓ done</p>'],
            'emoji and modifiers' => ['<p>🚀 launch 👍🏽</p>', '<p>🚀 launch 👍🏽</p>'],
            'combining marks' => ["<p>e\u{0301} a\u{0323}\u{0302}</p>", "<p>e\u{0301} a\u{0323}\u{0302}</p>"],
            'details and summary' => ['<details><summary>Q?</summary><p>A</p></details>', '<details><summary>Q?</summary><p>A</p></details>'],
            'table' => [
                '<table><thead><tr><th>Tên</th></tr></thead><tbody><tr><td>Giá ✓</td></tr></tbody></table>',
                '<table><thead><tr><th>Tên</th></tr></thead><tbody><tr><td>Giá ✓</td></tr></tbody></table>',
            ],
            'plain text' => ['plain text & “more”', 'plain text &amp; “more”'],
            'recoverable malformed' => ['<p>mở <strong>đậm <em>nghiêng</p>', '<p>mở <strong>đậm <em>nghiêng</em></strong></p>'],
        ];
    }

    #[DataProvider('utf8Cases')]
    public function test_valid_utf8_html_is_not_emptied(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HtmlSanitizer)->sanitize($input));
    }

    public function test_owner_reported_fragment_keeps_its_content(): void
    {
        $out = (new HtmlSanitizer)->sanitize('<p>Phiên bản 2.0 — “ngoặc” &amp; ✓</p><details><summary>Q?</summary><p>A</p></details>');

        $this->assertSame('<p>Phiên bản 2.0 — “ngoặc” &amp; ✓</p><details><summary>Q?</summary><p>A</p></details>', $out);
    }

    /** @return array<string, array{string, string}> */
    public static function embeddedCharsetCases(): array
    {
        return [
            'meta charset iso-8859-1' => ['<meta charset="iso-8859-1"><p>é ✓</p>', '<p>é ✓</p>'],
            'http-equiv windows-1252' => ['<meta http-equiv="Content-Type" content="text/html; charset=windows-1252"><p>é “q”</p>', '<p>é “q”</p>'],
            'meta charset utf-7 stays literal' => ['<meta charset="utf-7"><p>+ADw-b+AD4-x+ADw-/b+AD4-</p>', '<p>+ADw-b+AD4-x+ADw-/b+AD4-</p>'],
            'meta charset utf-8' => ['<meta charset="utf-8"><p>é</p>', '<p>é</p>'],
            'xml declaration in content' => ['<?xml encoding="ISO-8859-1"?><p>é</p>', '<p>é</p>'],
        ];
    }

    #[DataProvider('embeddedCharsetCases')]
    public function test_charset_declarations_inside_the_content_do_not_re_decode_it(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HtmlSanitizer)->sanitize($input));
    }

    public function test_the_parser_encoding_hint_never_reaches_the_output(): void
    {
        $sanitizer = new HtmlSanitizer;

        foreach (['<p>x</p>', 'text', '<?xml encoding="UTF-8"><p>y</p>', '<p>a</p></div><p>b</p>', '<details><summary>s</summary></details>'] as $input) {
            $out = $sanitizer->sanitize($input);
            $this->assertNotSame('', $out, $input);
            $this->assertStringNotContainsString('<?xml', $out, $input);
            $this->assertStringNotContainsString('encoding=', $out, $input);
            $this->assertStringNotContainsString('<meta', $out, $input);
            $this->assertStringNotContainsString('data-cms-root', $out, $input);
        }
    }
}
