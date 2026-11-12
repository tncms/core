<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\Html\AccessibleAttributePolicy;

/** CORE-HTML-SANITIZER-A11Y-R1 §5: exact ARIA names, element sets and value validators. */
final class AccessibleAttributePolicyTest extends TestCase
{
    public function test_only_three_aria_names_exist(): void
    {
        $this->assertSame(['aria-label', 'aria-labelledby', 'aria-current'], AccessibleAttributePolicy::names());
    }

    public static function elements(): array
    {
        return [
            ['aria-label', 'nav', true], ['aria-label', 'section', true], ['aria-label', 'a', true], ['aria-label', 'ul', true],
            ['aria-label', 'p', false], ['aria-label', 'div', false], ['aria-label', 'span', false], ['aria-label', 'li', false],
            ['aria-labelledby', 'section', true], ['aria-labelledby', 'nav', true], ['aria-labelledby', 'div', false],
            ['aria-current', 'a', true], ['aria-current', 'li', true], ['aria-current', 'span', false], ['aria-current', 'nav', false],
            ['aria-hidden', 'span', false], ['aria-describedby', 'section', false], ['role', 'nav', false], ['aria-live', 'p', false],
        ];
    }

    #[DataProvider('elements')]
    public function test_element_sets(string $name, string $tag, bool $allowed): void
    {
        $this->assertSame($allowed, AccessibleAttributePolicy::allows($tag, $name));
    }

    public static function labels(): array
    {
        return [
            'ascii' => ['Pagination', true],
            'vietnamese' => ['Phân trang kết quả', true],
            'inner spaces kept' => ['Page  2', true],
            'punctuation' => ['Results: 1–6 of 30 (filtered)', true],
            '160 code points' => [str_repeat('ệ', 160), true],
            'empty' => ['', false],
            'spaces only' => ['   ', false],
            'nbsp only' => ["\u{00A0}", false],
            '161 code points' => [str_repeat('x', 161), false],
            'bell' => ["a\u{0007}b", false],
            'newline' => ["a\nb", false],
            'tab' => ["a\tb", false],
            'DEL' => ["a\u{007F}b", false],
            'C1' => ["a\u{0085}b", false],
            'RLO bidi' => ["a\u{202E}b", false],
            'isolate' => ["a\u{2066}b", false],
            'zero width space' => ["a\u{200B}b", false],
            'zero width joiner' => ["a\u{200D}b", false],
            'BOM' => ["\u{FEFF}a", false],
            'soft hyphen' => ["a\u{00AD}b", false],
            'line separator' => ["a\u{2028}b", false],
            'invalid utf-8' => ["a\xC3b", false],
        ];
    }

    #[DataProvider('labels')]
    public function test_label_grammar(string $value, bool $valid): void
    {
        $this->assertSame($valid, AccessibleAttributePolicy::isValidLabel($value));
    }

    public static function currents(): array
    {
        return [
            ['page', true], ['step', true], ['location', true], ['date', true], ['time', true], ['true', true], ['false', true],
            ['Page', false], ['PAGE', false], [' page', false], ['page ', false], ['yes', false], ['', false], ['1', false], ['page step', false],
        ];
    }

    #[DataProvider('currents')]
    public function test_current_enumeration(string $value, bool $valid): void
    {
        $this->assertSame($valid, AccessibleAttributePolicy::isValidCurrent($value));
    }

    public static function idrefs(): array
    {
        $a = 'cms-content-a';
        $b = 'cms-content-b';
        $c = 'cms-content-c';
        $d = 'cms-content-d';
        $max = 'cms-content-'.str_repeat('z', 52);

        return [
            'one' => ['cms-content-heading', ['cms-content-heading']],
            'four' => ["$a $b $c $d", [$a, $b, $c, $d]],
            'over-long token' => ["$max-1", null], // 66 bytes
            'four longest valid tokens' => [
                implode(' ', ['cms-content-'.str_repeat('a', 52), 'cms-content-'.str_repeat('b', 52), 'cms-content-'.str_repeat('c', 52), 'cms-content-'.str_repeat('d', 52)]),
                ['cms-content-'.str_repeat('a', 52), 'cms-content-'.str_repeat('b', 52), 'cms-content-'.str_repeat('c', 52), 'cms-content-'.str_repeat('d', 52)],
            ],
            'five' => ["$a $b $c $d cms-content-e", null],
            'empty' => ['', null],
            'double space' => ["$a  $b", null],
            'leading space' => [" $a", null],
            'trailing space' => ["$a ", null],
            'tab separator' => ["$a	$b", null],
            'newline separator' => ["$a
$b", null],
            'duplicate token' => ["$a $a", null],
            'non-namespaced token' => ['heading-a', null],
            'runtime id token' => ['pb-frontend-canvas', null],
            'mixed namespaced and not' => ["$a pb-frontend-canvas", null],
            'uppercase token' => ['cms-content-A', null],
            'bare prefix token' => ['cms-content-', null],
        ];
    }

    #[DataProvider('idrefs')]
    public function test_idref_list_grammar(string $value, ?array $tokens): void
    {
        $this->assertSame($tokens, AccessibleAttributePolicy::idRefTokens($value));
    }

    public function test_bounds_are_published(): void
    {
        $this->assertSame(160, AccessibleAttributePolicy::MAX_LABEL_LENGTH);
        $this->assertSame(4, AccessibleAttributePolicy::MAX_IDREFS);
        $this->assertSame(259, AccessibleAttributePolicy::MAX_IDREF_BYTES);
    }
}
