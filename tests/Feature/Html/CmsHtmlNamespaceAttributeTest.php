<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-HTML-SANITIZER-INTEGRATION-R2 / H1: `xml:*` and `xmlns*` attribute names are not on any allow-list and
 * must not survive cms_html(). Root cause: DOMElement::removeAttribute() resolves an `xml:` name against the
 * XML namespace and an `xmlns` name against namespace declarations, so the null-namespace attribute produced
 * by the HTML parser was never removed (the call returned false).
 */
final class CmsHtmlNamespaceAttributeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function namespacedAttributes(): array
    {
        return [
            'xml:id canvas' => ['<div xml:id="pb-frontend-canvas">x</div>', '<div>x</div>'],
            'xml:id safe-looking value' => ['<div xml:id="cms-content-a">x</div>', '<div>x</div>'],
            'xml:lang' => ['<p xml:lang="en">x</p>', '<p>x</p>'],
            'xml:base' => ['<a href="/a" xml:base="https://evil.example/">x</a>', '<a href="/a">x</a>'],
            'xml:onload' => ['<p xml:onload="alert(1)">x</p>', '<p>x</p>'],
            'xml:onclick' => ['<p xml:onclick="alert(1)">x</p>', '<p>x</p>'],
            'xml:style' => ['<p xml:style="color:red">x</p>', '<p>x</p>'],
            'xml:aria-label' => ['<nav xml:aria-label="x">x</nav>', '<nav>x</nav>'],
            'xml:class' => ['<p xml:class="c">x</p>', '<p>x</p>'],
            'xmlns default' => ['<p xmlns="http://www.w3.org/1999/xhtml">x</p>', '<p>x</p>'],
            'xmlns prefixed' => ['<p xmlns:x="urn:x">x</p>', '<p>x</p>'],
            'xmlns:xlink' => ['<a href="/a" xmlns:xlink="http://www.w3.org/1999/xlink">x</a>', '<a href="/a">x</a>'],
            'uppercase XML:ID' => ['<div XML:ID="pb-frontend-canvas">x</div>', '<div>x</div>'],
            'mixed case Xmlns:Y' => ['<p Xmlns:Y="urn:y">x</p>', '<p>x</p>'],
            'mixed case XmLnS' => ['<p XmLnS="urn:y">x</p>', '<p>x</p>'],
            'other prefix' => ['<p x:onclick="alert(1)" foo:bar="1">x</p>', '<p>x</p>'],
            'empty prefix and suffix' => ['<p :id="cms-content-a" id:="cms-content-b">x</p>', '<p>x</p>'],
            'duplicate xml:id' => ['<p xml:id="a" xml:id="b">x</p>', '<p>x</p>'],
            'unquoted / malformed' => ['<p xml:id=a xmlns:=b xml:>x</p>', '<p>x</p>'],
            'nested on allowed tags' => [
                '<ul xmlns="u"><li xml:id="a"><a href="/a" xml:lang="en">x</a></li></ul>',
                '<ul><li><a href="/a">x</a></li></ul>',
            ],
        ];
    }

    #[DataProvider('namespacedAttributes')]
    public function test_cms_html_removes_namespaced_attributes(string $input, string $expected): void
    {
        $this->assertSame($expected, (string) cms_html($input));
    }

    public function test_approved_attributes_survive_next_to_namespaced_ones(): void
    {
        $input = '<section id="cms-content-r" aria-labelledby="cms-content-t" xml:id="pb-frontend-canvas" xmlns="u">'
            .'<h2 id="cms-content-t" xml:lang="en">T</h2>'
            .'<nav aria-label="Pages" xmlns:x="u"><ul><li><a href="#cms-content-r" aria-current="page" class="c" xml:base="/b">1</a></li></ul></nav>'
            .'<a href="https://example.com" target="_blank" xml:onclick="x">e</a>'
            .'<table><tr><td colspan="2" xml:id="q">c</td></tr></table></section>';

        $this->assertSame(
            '<section id="cms-content-r" aria-labelledby="cms-content-t"><h2 id="cms-content-t">T</h2>'
            .'<nav aria-label="Pages"><ul><li><a href="#cms-content-r" aria-current="page" class="c">1</a></li></ul></nav>'
            .'<a href="https://example.com" target="_blank" rel="noopener noreferrer">e</a>'
            .'<table><tr><td colspan="2">c</td></tr></table></section>',
            (string) cms_html($input),
        );
    }

    public function test_no_namespaced_attribute_or_declaration_survives_anywhere(): void
    {
        $out = (string) cms_html('<div xml:id="pb-frontend-canvas" xmlns="u"><p xml:onclick="x" xmlns:a="u" XML:LANG="en">'
            .'<strong xml:id="z">b</strong><em xmlns:b="u">i</em><img src="/a.png" alt="a" xml:id="i" xmlns="u"></p></div>');

        $this->assertDoesNotMatchRegularExpression('/\s(?:xml|xmlns)[:=\s>]/i', $out);
        $this->assertStringNotContainsString('pb-frontend-canvas', $out);
        $this->assertStringContainsString('<img src="/a.png" alt="a">', $out);
    }

    public function test_content_write_path_strips_namespaced_attributes(): void
    {
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        $content = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi', 'title' => 'NS', 'slug' => 'ns-page',
            'content' => '<div xml:id="pb-frontend-canvas" xmlns="u"><p xml:onload="x" id="cms-content-keep">k</p></div>',
        ], ContentFixtures::context());

        $stored = (string) $content->translations()->where('locale', 'vi')->value('content');
        $this->assertSame('<div><p id="cms-content-keep">k</p></div>', $stored);
    }
}
