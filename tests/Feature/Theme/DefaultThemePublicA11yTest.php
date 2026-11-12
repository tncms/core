<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use PHPUnit\Framework\TestCase;

/**
 * CORE-40 TG-DEFAULT-A1 / A2: source-level regression for the Core-shipped
 * default theme. The browser evidence (computed colours, real hover / focus) is
 * authoritative; this guards the shared rules those results come from.
 *
 * A1 (WCAG 1.4.3 AA): the footer copyright text must reach 4.5:1 against the
 *     footer background.
 * A2 (WCAG 1.4.1 A): the theme's global `a` rule removes underlines. Links
 *     that sit inside running text (the search-result title beside its type
 *     label, the search prev / next links beside "Page x of y", and plain links
 *     in post / page body content) must keep a persistent non-colour cue (an
 *     underline). The non-linked search title variant must stay plain text, and
 *     classed component links in body content keep their own styling.
 */
final class DefaultThemePublicA11yTest extends TestCase
{
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3).'/themes/default/assets/css/app.css');

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return list<string> declaration bodies of every rule whose selector list contains exactly $selector */
    private static function rules(string $selector): array
    {
        preg_match_all('/(?:^|\})\s*([^{}@]+?)\s*\{([^}]*)\}/m', self::css(), $m, PREG_SET_ORDER);
        $bodies = [];
        foreach ($m as [, $selectors, $body]) {
            if (in_array($selector, array_map(static fn (string $x): string => trim(preg_replace('/\s+/', ' ', $x)), explode(',', $selectors)), true)) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    private static function prop(string $selector, string $property): ?string
    {
        $value = null;
        foreach (self::rules($selector) as $body) {
            if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/', $body, $m)) {
                $value = trim($m[1]);
            }
        }

        return $value;
    }

    private static function ratio(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $c = array_map(static fn (string $p): float => hexdec($p) / 255, str_split(strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex, 2));
            $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

        return ($x + 0.05) / ($y + 0.05);
    }

    public function test_footer_copyright_text_meets_normal_text_contrast(): void
    {
        $fg = self::prop('.site-footer__copy', 'color');
        $bg = self::prop('.site-footer', 'background');

        $this->assertNotNull($fg);
        $this->assertNotNull($bg);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{3,6}$/i', $fg);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{3,6}$/i', $bg);
        $this->assertGreaterThanOrEqual(4.5, self::ratio($fg, $bg), "footer copyright $fg on $bg");
    }

    public function test_search_result_title_link_has_a_persistent_underline(): void
    {
        $this->assertSame('underline', self::prop('a.tn-search-result-title', 'text-decoration-line'));
    }

    public function test_the_underline_targets_only_the_link_variant(): void
    {
        $this->assertSame([], self::rules('.tn-search-result-title'), 'the non-linked <span> title must not be decorated');
    }

    public function test_hover_still_changes_the_search_title_link(): void
    {
        $this->assertNotNull(self::prop('a.tn-search-result-title:hover', 'text-decoration-thickness'));
    }

    /** Same seam: the search view's prev / next links sit beside the "Page x of y" text. */
    public function test_search_pagination_links_have_a_persistent_underline(): void
    {
        $this->assertSame('underline', self::prop('.tn-search-pagination a', 'text-decoration-line'));
    }

    /** Same seam: plain links inside post / page body text. */
    public function test_body_content_links_have_a_persistent_underline(): void
    {
        $this->assertSame('underline', self::prop('.entry-content a:not([class])', 'text-decoration-line'));
        $this->assertNotNull(self::prop('.entry-content a:not([class]):hover', 'text-decoration-thickness'));
    }

    /**
     * Classed anchors inside .entry-content are components (buttons, cards; e.g. Page Builder
     * blocks render inside the theme's .entry-content) and must keep their own decoration.
     */
    public function test_component_links_in_body_content_are_not_decorated(): void
    {
        $this->assertSame([], self::rules('.entry-content a'));
        $this->assertSame([], self::rules('.entry-content a:hover'));
    }
}
