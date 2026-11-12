<?php

declare(strict_types=1);

namespace Tests\Unit\Html;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Services\HtmlSanitizer;

/**
 * CORE-HTML-SANITIZER-A11Y-R1 (revision 2) security and behaviour matrix.
 *
 * Valid cases assert the exact output. Hostile cases assert that the dangerous token is gone AND that the
 * surrounding safe content survives, so a fix that "passes" by deleting everything is caught.
 */
final class HtmlSanitizerA11yContractTest extends TestCase
{
    private const BEFORE = '<p class="keep-before">before</p>';

    private const AFTER = '<p class="keep-after">after</p>';

    private function sanitize(string $html): string
    {
        return (new HtmlSanitizer)->sanitize($html);
    }

    // ---- accepted: exact output ------------------------------------------------------------------------

    public static function kept(): array
    {
        $max = 'cms-content-'.str_repeat('m', 52);

        return [
            'collection region' => [
                '<section id="cms-content-results" aria-labelledby="cms-content-results-title"><h2 id="cms-content-results-title">Results</h2>'
                .'<nav aria-label="Pagination"><ul><li aria-current="page"><a href="?page=1" aria-current="page">1</a></li>'
                .'<li><a href="?page=2#cms-content-results">2</a></li></ul></nav></section>',
            ],
            'minimum id' => ['<p id="cms-content-a">x</p>'],
            'maximum id' => ['<p id="'.$max.'">x</p><a href="#'.$max.'">top</a>'],
            'filter and pagination anchors' => ['<section id="cms-content-news-filters">f</section><nav id="cms-content-news-page-2" aria-label="Pages">p</nav>'],
            'skip link to fragment target' => ['<p><a href="#cms-content-results">Skip to results</a></p><section id="cms-content-results">x</section>'],
            'vietnamese label' => ['<nav aria-label="Phân trang kết quả"><a href="/a">1</a></nav>'],
            'label elements' => [
                '<a href="/a" aria-label="Home">h</a><section aria-label="s">s</section><article aria-label="a">a</article>'
                .'<aside aria-label="b">b</aside><main aria-label="m">m</main><ul aria-label="u"><li>u</li></ul>'
                .'<ol aria-label="o"><li>o</li></ol><table aria-label="t"><tbody><tr><td>t</td></tr></tbody></table>'
                .'<figure aria-label="f">f</figure>',
            ],
            'current values' => [
                '<ul><li aria-current="step">1</li><li aria-current="location">2</li><li aria-current="date">3</li>'
                .'<li aria-current="time">4</li><li aria-current="true">5</li><li aria-current="false">6</li></ul>',
            ],
            'forward sibling reference' => ['<nav aria-labelledby="cms-content-nav-title"><a href="/a">a</a></nav><h2 id="cms-content-nav-title">Menu</h2>'],
            'four references' => [
                '<h2 id="cms-content-a">A</h2><h2 id="cms-content-b">B</h2><h2 id="cms-content-c">C</h2><h2 id="cms-content-d">D</h2>'
                .'<section aria-labelledby="cms-content-a cms-content-b cms-content-c cms-content-d">x</section>',
            ],
            'self reference' => ['<nav id="cms-content-self" aria-labelledby="cms-content-self">n</nav>'],
            'multiple unique ids' => ['<details id="cms-content-faq"><summary id="cms-content-faq-q">Q</summary><p id="cms-content-faq-a">A</p></details><img src="/a.png" id="cms-content-hero">'],
            'class id and aria together' => ['<nav class="pager is-compact" id="cms-content-pager" aria-label="Pages"><a class="is-current" href="?p=1" aria-current="page">1</a></nav>'],
        ];
    }

    #[DataProvider('kept')]
    public function test_valid_markup_is_kept_byte_for_byte(string $html): void
    {
        $this->assertSame($html, $this->sanitize($html));
    }

    public static function transformed(): array
    {
        return [
            'runtime canvas id stripped, namespaced anchor kept' => [
                '<div id="pb-frontend-canvas"><section id="cms-content-results">r</section></div>',
                '<div><section id="cms-content-results">r</section></div>',
            ],
            'revision-1 generic id now stripped' => ['<section id="results-region">a</section>', '<section>a</section>'],
            'broad content prefix stripped' => ['<main id="content-main">a</main>', '<main>a</main>'],
            'duplicate id keeps first only' => ['<h2 id="cms-content-dup">A</h2><h3 id="cms-content-dup">B</h3><p id="cms-content-dup">C</p>', '<h2 id="cms-content-dup">A</h2><h3>B</h3><p>C</p>'],
            'duplicate nested keeps outer' => ['<section id="cms-content-dup"><p id="cms-content-dup">x</p></section>', '<section id="cms-content-dup"><p>x</p></section>'],
            'entity encoded hyphen decodes then validates' => ['<p id="cms&#45;content&#45;a">x</p>', '<p id="cms-content-a">x</p>'],
            'mixed bag keeps only approved' => [
                '<nav aria-label="Main" aria-hidden="true" role="navigation" data-x="1" onclick="a()" style="x" id="cms-content-main-nav" tabindex="0">n</nav>',
                '<nav aria-label="Main" id="cms-content-main-nav">n</nav>',
            ],
            'partially dangling reference removed whole' => ['<h2 id="cms-content-a">A</h2><nav aria-labelledby="cms-content-a cms-content-missing">n</nav>', '<h2 id="cms-content-a">A</h2><nav>n</nav>'],
            'reference to non-namespaced id removed' => ['<h2 id="heading-a">A</h2><nav aria-labelledby="heading-a">n</nav>', '<h2>A</h2><nav>n</nav>'],
            'reference to the runtime canvas removed' => ['<div id="pb-frontend-canvas">c</div><nav aria-labelledby="pb-frontend-canvas">n</nav>', '<div>c</div><nav>n</nav>'],
            'reference to rejected uppercase id removed' => ['<h2 id="cms-content-A">A</h2><nav aria-labelledby="cms-content-a">n</nav>', '<h2>A</h2><nav>n</nav>'],
            'reference to dropped img removed' => ['<img src="javascript:alert(1)" id="cms-content-img"><nav aria-labelledby="cms-content-img">n</nav>', '<nav>n</nav>'],
            'reference to unwrapped element removed' => ['<font id="cms-content-font">t</font><nav aria-labelledby="cms-content-font">n</nav>', 't<nav>n</nav>'],
            'reference into dangerous subtree removed' => ['<svg><text id="cms-content-svg">t</text></svg><nav aria-labelledby="cms-content-svg">n</nav>', '<nav>n</nav>'],
            'reference into template is unwrapped content' => ['<template><h2 id="cms-content-tpl">t</h2></template><nav aria-labelledby="cms-content-tpl">n</nav>', '<h2 id="cms-content-tpl">t</h2><nav aria-labelledby="cms-content-tpl">n</nav>'],
            'unsafe href removed, a11y kept' => ['<a href="javascript:alert(1)" aria-label="ok" id="cms-content-evil">x</a>', '<a aria-label="ok" id="cms-content-evil">x</a>'],
            'blank target still gets rel' => ['<a href="#cms-content-x" aria-current="page" target="_blank">x</a>', '<a href="#cms-content-x" aria-current="page" target="_blank" rel="noopener noreferrer">x</a>'],
            'name removed id kept' => ['<img src="/a.png" id="cms-content-photo" name="cms-content-photo">', '<img src="/a.png" id="cms-content-photo">'],
            // fragment hrefs are byte-compatible with .39 (href policy unchanged) but never gain an authored target
            'runtime fragment href unchanged and targetless' => ['<a href="#pb-frontend-canvas">x</a><div id="pb-frontend-canvas">c</div>', '<a href="#pb-frontend-canvas">x</a><div>c</div>'],
            'encoded fragment never matches' => ['<a href="#cms-content-a%20b">x</a><p id="cms-content-a b">c</p>', '<a href="#cms-content-a%20b">x</a><p>c</p>'],
        ];
    }

    #[DataProvider('transformed')]
    public function test_deterministic_transformations(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitize($input));
    }

    // ---- rejected: dangerous token removed AND neighbours preserved ------------------------------------

    public static function hostile(): array
    {
        return [
            // [hostile markup, attribute that must be absent, element tag that must survive]
            'runtime canvas id' => ['<div id="pb-frontend-canvas">x</div>', 'id', 'div'],
            'theme chrome id' => ['<nav id="site-nav-menu">x</nav>', 'id', 'nav'],
            'core slot id' => ['<div id="tncms-slot">x</div>', 'id', 'div'],
            'bare prefix' => ['<p id="cms-content-">x</p>', 'id', 'p'],
            'uppercase prefix' => ['<p id="CMS-CONTENT-A">x</p>', 'id', 'p'],
            'uppercase suffix' => ['<p id="cms-content-Results">x</p>', 'id', 'p'],
            'trailing suffix hyphen' => ['<p id="cms-content-a-">x</p>', 'id', 'p'],
            'double suffix hyphen' => ['<p id="cms-content-a--b">x</p>', 'id', 'p'],
            'overlong id' => ['<p id="cms-content-'.str_repeat('o', 53).'">x</p>', 'id', 'p'],
            'underscore id' => ['<p id="cms-content-a_b">x</p>', 'id', 'p'],
            'dot id' => ['<p id="cms-content-a.b">x</p>', 'id', 'p'],
            'colon id' => ['<p id="cms-content-a:b">x</p>', 'id', 'p'],
            'slash id' => ['<p id="cms-content-a/b">x</p>', 'id', 'p'],
            'backtick id' => ['<p id="cms-content-a`b">x</p>', 'id', 'p'],
            'angle id' => ['<p id="cms-content-a&lt;b">x</p>', 'id', 'p'],
            'quote id' => ['<p id="cms-content-a&quot;b">x</p>', 'id', 'p'],
            'encoded newline in id' => ['<p id="cms-content-a&#x0A;">x</p>', 'id', 'p'],
            'encoded space in id' => ['<p id="cms-content-a&#32;">x</p>', 'id', 'p'],
            'double-encoded id' => ['<p id="cms-content-a&amp;#45;b">x</p>', 'id', 'p'],
            'confusable id' => ["<p id=\"cms-content-\u{0430}\">x</p>", 'id', 'p'],
            'clobber cookie' => ['<img src="/a.png" id="cookie">', 'id', 'img'],
            'clobber location' => ['<p id="location">l</p>', 'id', 'p'],
            'clobber proto' => ['<p id="__proto__">p</p>', 'id', 'p'],
            'clobber config' => ['<a href="/x" id="config">c</a>', 'id', 'a'],
            'clobber defaultView' => ['<p id="defaultView">c</p>', 'id', 'p'],
            'duplicate later id' => ['<p id="cms-content-dup">1</p><span id="cms-content-dup">2</span>', 'id', 'span'],
            'encoded RLO in label' => ['<nav aria-label="a&#x202E;b">x</nav>', 'aria-label', 'nav'],
            'encoded ZWSP in label' => ['<nav aria-label="a&#x200B;b">x</nav>', 'aria-label', 'nav'],
            'encoded tab separator' => ['<h2 id="cms-content-a">A</h2><h2 id="cms-content-b">B</h2><nav aria-labelledby="cms-content-a&#9;cms-content-b">x</nav>', 'aria-labelledby', 'nav'],
            'non-namespaced labelledby' => ['<h2 id="cms-content-a">A</h2><nav aria-labelledby="heading-a">x</nav>', 'aria-labelledby', 'nav'],
            'five token labelledby' => ['<h2 id="cms-content-a">A</h2><nav aria-labelledby="cms-content-a cms-content-a cms-content-a cms-content-a cms-content-a">x</nav>', 'aria-labelledby', 'nav'],
            'uppercase current' => ['<a href="/a" aria-current="PAGE">x</a>', 'aria-current', 'a'],
            'current with trailing space' => ['<a href="/a" aria-current="page ">x</a>', 'aria-current', 'a'],
            'label on generic span' => ['<span aria-label="x">x</span>', 'aria-label', 'span'],
            'labelledby on generic div' => ['<h2 id="cms-content-a">A</h2><div aria-labelledby="cms-content-a">x</div>', 'aria-labelledby', 'div'],
            'current on nav' => ['<nav aria-current="page">x</nav>', 'aria-current', 'nav'],
            'aria-hidden on link' => ['<a href="/a" aria-hidden="true">x</a>', 'aria-hidden', 'a'],
            'aria-describedby' => ['<h2 id="cms-content-a">A</h2><section aria-describedby="cms-content-a">x</section>', 'aria-describedby', 'section'],
            'aria-controls' => ['<h2 id="cms-content-a">A</h2><a href="/a" aria-controls="cms-content-a">x</a>', 'aria-controls', 'a'],
            'aria-live' => ['<section aria-live="assertive">x</section>', 'aria-live', 'section'],
            'aria-owns' => ['<ul aria-owns="cms-content-a"><li>x</li></ul>', 'aria-owns', 'ul'],
            'role button on div' => ['<div role="button">x</div>', 'role', 'div'],
            'role status' => ['<p role="status">x</p>', 'role', 'p'],
            'data status' => ['<p data-status="ok">x</p>', 'data-status', 'p'],
            'data cms root forged' => ['<div data-cms-root="1" id="cms-content-root">x</div>', 'data-cms-root', 'div'],
            'data pb canvas marker' => ['<div data-pb-frontend-canvas id="cms-content-c">x</div>', 'data-pb-frontend-canvas', 'div'],
            'event handler beside valid label' => ['<nav aria-label="ok" onfocus="alert(1)">x</nav>', 'onfocus', 'nav'],
            'style beside valid id' => ['<section id="cms-content-ok" style="position:fixed">x</section>', 'style', 'section'],
            'tabindex beside valid current' => ['<li aria-current="page" tabindex="-1">x</li>', 'tabindex', 'li'],
            'label over limit' => ['<nav aria-label="'.str_repeat('y', 500).'">x</nav>', 'aria-label', 'nav'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_attribute_is_removed_and_neighbours_survive(string $hostile, string $attribute, string $tag): void
    {
        $out = $this->sanitize(self::BEFORE.$hostile.self::AFTER);
        $dom = $this->parse($out);
        $xpath = new DOMXPath($dom);

        $this->assertStringStartsWith(self::BEFORE, $out);
        $this->assertStringEndsWith(self::AFTER, $out);

        $nodes = $xpath->query('//body//'.$tag);
        $this->assertGreaterThan(0, $nodes->length, "<$tag> must survive");
        $target = $nodes->item($nodes->length - 1);
        $this->assertInstanceOf(DOMElement::class, $target);
        $this->assertFalse($target->hasAttribute($attribute), "$attribute must be removed: $out");
        $this->assertSame(0, $xpath->query('//*[@role or @style or @tabindex]|//@*[starts-with(name(), "on") or starts-with(name(), "data-")]')->length, $out);
        foreach ($xpath->query('//@id') as $id) {
            $this->assertStringStartsWith('cms-content-', $id->value, $out);
        }
    }

    // ---- hostile values that are valid text must never break out ---------------------------------------

    public static function breakouts(): array
    {
        return [
            'double quote breakout' => ['x" onmouseover="alert(1)'],
            'single quote breakout' => ["x' onmouseover='alert(1)"],
            'both quotes' => ['a"b\'c onmouseover=alert(1)'],
            'tag text' => ['<script>alert(1)</script>'],
            'comment terminator' => ['--><img src=x onerror=alert(1)>'],
            'ampersand entity text' => ['&amp;lt;b&amp;gt;'],
            'backtick' => ['`${alert(1)}`'],
            'id lookalike' => [' id="pb-frontend-canvas"'],
        ];
    }

    #[DataProvider('breakouts')]
    public function test_label_text_is_inert_and_round_trips(string $label): void
    {
        $encoded = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = $this->sanitize(self::BEFORE.'<nav aria-label="'.$encoded.'"><a href="/a">a</a></nav>'.self::AFTER);

        $dom = $this->parse($out);
        $xpath = new DOMXPath($dom);
        $nav = $xpath->query('//nav')->item(0);

        $this->assertInstanceOf(DOMElement::class, $nav);
        $this->assertSame($label, $nav->getAttribute('aria-label'));
        $this->assertSame(1, $nav->attributes->length, 'no attribute may be injected');
        $this->assertSame(0, $xpath->query('//script|//img|//b|//comment()|//*[@id]')->length);
        $this->assertSame(0, $xpath->query('//@*[starts-with(name(), "on")]')->length);
        $this->assertStringStartsWith(self::BEFORE, $out);
        $this->assertStringEndsWith(self::AFTER, $out);
    }

    // ---- unchanged URL policy -------------------------------------------------------------------------

    public static function unsafeHrefs(): array
    {
        return [
            ['javascript:alert(1)'], ['JAVASCRIPT:alert(1)'], [' javascript:alert(1)'], ["java\nscript:alert(1)"],
            ['&#106;avascript:alert(1)'], ['&#x6A;avascript:alert(1)'], ['vbscript:x'], ['data:text/html,<script>alert(1)</script>'], ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('unsafeHrefs')]
    public function test_unsafe_hrefs_stay_removed_with_new_attributes_present(string $href): void
    {
        $out = $this->sanitize('<a href="'.$href.'" id="cms-content-link" aria-label="Link" aria-current="page">x</a>');

        $this->assertSame('<a id="cms-content-link" aria-label="Link" aria-current="page">x</a>', $out);
    }

    // ---- idempotency and bounds -------------------------------------------------------------------------

    public function test_every_matrix_input_is_idempotent(): void
    {
        $inputs = array_merge(
            array_column(self::kept(), 0),
            array_column(self::transformed(), 0),
            array_map(fn (array $c) => self::BEFORE.$c[0].self::AFTER, self::hostile()),
        );

        foreach ($inputs as $input) {
            $once = $this->sanitize($input);
            $this->assertSame($once, $this->sanitize($once), $input);
        }
    }

    public function test_large_input_stays_linear_and_bounded(): void
    {
        $chunk = '';
        for ($i = 0; $i < 2000; $i++) {
            $chunk .= '<section id="cms-content-s-'.$i.'" aria-labelledby="cms-content-h-'.$i.' cms-content-h-'.max(0, $i - 1).'">'
                .'<h2 id="cms-content-h-'.$i.'">T</h2>'
                .'<nav aria-label="Pagination '.$i.'"><a href="#cms-content-s-'.$i.'" aria-current="page">'.$i.'</a></nav>'
                .'<p id="cms-content-s-'.$i.'">dup</p><div id="pb-frontend-canvas">c</div></section>';
        }

        $start = hrtime(true);
        $out = $this->sanitize($chunk);
        $ms = (hrtime(true) - $start) / 1e6;

        $this->assertSame(2000, substr_count($out, '<h2 id="cms-content-h-'));
        $this->assertSame(2000, substr_count($out, '<section id="cms-content-s-'));
        $this->assertSame(2000, substr_count($out, '<p>dup</p>'), 'later duplicate ids lose only the attribute');
        $this->assertSame(0, substr_count($out, 'pb-frontend-canvas'));
        // Section 0 carries "cms-content-h-0 cms-content-h-0", a duplicate token, so only that one list is removed.
        $this->assertSame(1999, substr_count($out, 'aria-labelledby="'));
        $this->assertStringContainsString('<section id="cms-content-s-0"><h2 id="cms-content-h-0">', $out);
        $this->assertLessThan(5000, $ms, "2000-section input took {$ms}ms");
    }

    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }
}
