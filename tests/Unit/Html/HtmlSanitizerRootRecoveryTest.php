<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Services\HtmlSanitizer;
use TheNguyen\CMS\Support\Html\HtmlSanitizationFailed;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-01: the sanitizer must never silently destroy authored content.
 *
 * - A stray closing tag in the input used to close the synthetic `data-cms-root` wrapper early; everything after
 *   it became a sibling of the root and was dropped without a trace (`</div><p>after</p>` → `''`).
 * - When the root cannot be recovered at all (the libxml 2.11–2.12 failure fixed in RC2), the storage entry point
 *   must fail loudly instead of returning `''`, while the render entry point stays safe (empty output).
 */
final class HtmlSanitizerRootRecoveryTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function strayClosingTags(): array
    {
        return [
            'stray close first' => ['</div><p>after</p>', '<p>after</p>'],
            'stray close in middle' => ['<p>a</p></div><p>b</p>', '<p>a</p><p>b</p>'],
            'double stray close' => ['<p><b>x</p></div></div><p>after</p>', '<p><b>x</b></p><p>after</p>'],
            'user div then stray' => ['<div><p>a</p></div></div><p>b</p>', '<div><p>a</p></div><p>b</p>'],
            'vietnamese after stray' => ['</div><p>Tiếng Việt — “ngoặc”</p>', '<p>Tiếng Việt — “ngoặc”</p>'],
            // libxml 2.11+ without an implied body drops every top-level node after a void element.
            'void element after stray' => ['</div><img src="x"><b>l</b>', '<img src="x"><b>l</b>'],
            'void then text after stray' => ['<p>a</p></div><br>x<b>l</b>', '<p>a</p><br>x<b>l</b>'],
            'void after double stray' => ['</div></div><hr><p>b</p>', '<hr><p>b</p>'],
        ];
    }

    #[DataProvider('strayClosingTags')]
    public function test_content_after_a_stray_closing_tag_is_kept(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HtmlSanitizer)->sanitize($input));
    }

    public function test_hostile_markup_after_a_stray_closing_tag_is_still_sanitized(): void
    {
        $out = (new HtmlSanitizer)->sanitize('</div><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:x">l</a><iframe src="//e"></iframe>');

        $this->assertSame('<img src="x"><a>l</a>', $out);
        $this->assertStringNotContainsString('data-cms-root', $out);
    }

    public function test_intentionally_empty_input_stays_empty_on_both_entry_points(): void
    {
        $sanitizer = new HtmlSanitizer;

        foreach (['', '   ', "\n\t"] as $empty) {
            $this->assertSame('', $sanitizer->sanitize($empty));
            $this->assertSame('', $sanitizer->sanitizeForStorage($empty));
        }

        $this->assertSame('', $sanitizer->sanitize(null));
    }

    public function test_input_that_legitimately_sanitizes_to_nothing_is_not_a_failure(): void
    {
        $sanitizer = new HtmlSanitizer;

        $this->assertSame('', $sanitizer->sanitizeForStorage('<script>alert(1)</script>'));
        $this->assertSame('', $sanitizer->sanitizeForStorage('<!-- only a comment -->'));
    }

    public function test_storage_entry_point_matches_render_output_for_ordinary_and_hostile_html(): void
    {
        $sanitizer = new HtmlSanitizer;

        foreach (['<p>abc</p>', '<p>Tiếng Việt</p><img src="a.png" alt="Ảnh">', '<p><b>x</p>', '<img src=x onerror=alert(1)>'] as $input) {
            $this->assertSame($sanitizer->sanitize($input), $sanitizer->sanitizeForStorage($input), $input);
        }
    }

    public function test_a_lost_root_fails_loudly_for_storage_and_renders_nothing(): void
    {
        $sanitizer = new class extends HtmlSanitizer
        {
            protected function findRoot(DOMDocument $dom): ?DOMElement
            {
                return null;
            }
        };

        $this->assertSame('', $sanitizer->sanitize('<p>valid content</p>'));

        $this->expectException(HtmlSanitizationFailed::class);
        $sanitizer->sanitizeForStorage('<p>valid content</p>');
    }
}
