<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\Html\SafeHtmlId;

/** CORE-HTML-SANITIZER-A11Y-R1 §3 (revision 2): the Core-reserved authored-content id namespace. */
final class SafeHtmlIdTest extends TestCase
{
    public static function valid(): array
    {
        return [
            'minimum (13 bytes)' => ['cms-content-a'],
            'digit-only suffix' => ['cms-content-1'],
            'results anchor' => ['cms-content-results'],
            'filter anchor' => ['cms-content-news-filters'],
            'pagination anchor' => ['cms-content-news-page-2'],
            'instance-scoped anchor' => ['cms-content-pbab12cd-results'],
            'maximum (64 bytes)' => ['cms-content-'.str_repeat('a', 52)],
        ];
    }

    public static function invalid(): array
    {
        return [
            // ids outside the namespace, including real runtime ids and revision-1 generic ids
            'page builder canvas' => ['pb-frontend-canvas'],
            'generic hyphenated' => ['results-region'],
            'broad content prefix' => ['content-results'],
            'theme chrome' => ['site-nav-menu'],
            'reserved core marker' => ['tncms-slot'],
            'no hyphen' => ['results'],
            'partial prefix' => ['cms-results'],
            'prefix without separator' => ['cms-contentresults'],
            'prefix as suffix' => ['x-cms-content-a'],
            // malformed namespace values
            'bare prefix' => ['cms-content-'],
            'empty' => [''],
            'uppercase prefix' => ['CMS-content-a'],
            'mixed-case prefix' => ['Cms-Content-a'],
            'uppercase suffix' => ['cms-content-Results'],
            'leading suffix hyphen' => ['cms-content--a'],
            'trailing suffix hyphen' => ['cms-content-a-'],
            'double hyphen in suffix' => ['cms-content-a--b'],
            'too long (65)' => ['cms-content-'.str_repeat('a', 53)],
            'leading space' => [' cms-content-a'],
            'trailing space' => ['cms-content-a '],
            'inner space' => ['cms-content-a b'],
            'trailing newline' => ["cms-content-a\n"],
            'tab' => ["cms-content-a\tb"],
            'NUL' => ["cms-content-a\0"],
            'nbsp' => ["cms-content-a\u{00A0}b"],
            'zero width space' => ["cms-content-a\u{200B}b"],
            'cyrillic confusable' => ["cms-content-\u{0430}"],
            'fullwidth hyphen' => ["cms-content\u{FF0D}a"],
            'vietnamese' => ['cms-content-kết-quả'],
            'underscore' => ['cms-content-a_b'],
            'dot' => ['cms-content-a.b'],
            'colon' => ['cms-content-a:b'],
            'slash' => ['cms-content-a/b'],
            'backslash' => ['cms-content-a\\b'],
            'double quote' => ['cms-content-a"b'],
            'single quote' => ["cms-content-a'b"],
            'angle bracket' => ['cms-content-a<b'],
            'square bracket' => ['cms-content-a[b]'],
            'backtick' => ['cms-content-a`b'],
            'percent-encoded' => ['cms-content-a%20b'],
            'hash' => ['cms-content-a#b'],
            'ampersand' => ['cms-content-a&b'],
            'equals' => ['cms-content-a=b'],
        ];
    }

    #[DataProvider('valid')]
    public function test_namespaced_ids_are_accepted(string $id): void
    {
        $this->assertTrue(SafeHtmlId::isValid($id));
    }

    #[DataProvider('invalid')]
    public function test_everything_else_is_rejected(string $id): void
    {
        $this->assertFalse(SafeHtmlId::isValid($id));
    }

    public function test_grammar_and_bounds_are_published(): void
    {
        $this->assertSame('cms-content-', SafeHtmlId::PREFIX);
        $this->assertSame(64, SafeHtmlId::MAX_LENGTH);
        $this->assertSame('/^cms-content-[a-z0-9]+(?:-[a-z0-9]+)*$/D', SafeHtmlId::PATTERN);
    }

    public function test_accepted_ids_are_never_javascript_identifiers(): void
    {
        foreach (array_column(self::valid(), 0) as $id) {
            $this->assertSame(0, preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $id), $id);
        }
    }

    public function test_any_id_outside_the_namespace_is_rejected(): void
    {
        // Namespace separation, not enumeration: arbitrary well-formed ids that an extension might use today or
        // after a rename are all rejected, with no list of known ids anywhere in Core.
        mt_srand(20261104);
        for ($i = 0; $i < 500; $i++) {
            $id = $this->randomSlug().'-'.$this->randomSlug();
            if (str_starts_with($id, 'cms-content-')) {
                continue;
            }
            $this->assertFalse(SafeHtmlId::isValid($id), $id);
        }
    }

    private function randomSlug(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0, $n = mt_rand(1, 12); $i < $n; $i++) {
            $out .= $chars[mt_rand(0, 35)];
        }

        return $out;
    }
}
