<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Services\HtmlSanitizer;

/**
 * CORE-EXTENSION-TRUST S2 — S2 gains no S1 privilege and leaves the legacy
 * sanitizer byte-identical (brief §3.3 last bullet, §4 last bullet, §5.7;
 * matrix T1/T22).
 */
final class TrustedComponentS1BoundaryTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private const TURNSTILE = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-s1', ['trusted_scripts' => ['turnstile' => [
            'url' => self::TURNSTILE,
            'position' => 'footer',
            'attributes' => ['async' => true, 'defer' => true],
            'csp' => ['script-src' => ['https://challenges.cloudflare.com'], 'frame-src' => ['https://challenges.cloudflare.com']],
            'purpose' => 'S2 boundary fixture',
        ]]]);
        $this->activateComponentPlugins('s2-s1');

        $code = $this->pluginCode('s2-s1');
        $this->assertTrue($code->register($this->provider('s2-s1', 'widget', '<div class="cf-turnstile" data-sitekey="1x00000000000000000000AA"></div>', ['public'], ['@host'], null, ['data-sitekey'], 'enqueue')));
        $code->shortcode('s2_widget', 's2-s1/widget');

        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Widget', 'slug' => 'widget', 'content' => '[s2_widget]']);
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Other', 'slug' => 'other', 'content' => '<p>other</p>']);
    }

    public function test_a_component_does_not_approve_or_load_an_unapproved_script(): void
    {
        $response = $this->get('/widget')->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('data-sitekey="1x00000000000000000000AA"', $html);
        $this->assertStringNotContainsString(self::TURNSTILE, $html);
        $this->assertFalse(app('cms.trusted_script_approvals')->isApproved(app('cms.trusted_scripts')->declaration('s2-s1', 'turnstile')));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        Http::assertNothingSent();
    }

    public function test_an_approved_script_still_follows_s1_rules_once_and_route_scoped(): void
    {
        app('cms.trusted_script_approvals')->approve(app('cms.trusted_scripts')->declaration('s2-s1', 'turnstile'), null);

        $widget = (string) $this->get('/widget')->assertOk()->getContent();
        $other = (string) $this->get('/other')->assertOk()->getContent();

        $this->assertSame(1, substr_count($widget, self::TURNSTILE));
        $this->assertStringNotContainsString(self::TURNSTILE, $other);
        Http::assertNothingSent();
    }

    public function test_s2_source_never_touches_s1_authority_or_csp(): void
    {
        $root = dirname(__DIR__, 3).'/packages/thenguyen/cms-core/src';
        $files = array_merge(
            [$root.'/Services/TrustedComponentRegistry.php', $root.'/Http/Middleware/ResolveTrustedSlots.php', $root.'/Contracts/TrustedComponentProvider.php'],
            File::glob($root.'/Support/TrustedComponents/*.php'),
        );

        $this->assertGreaterThanOrEqual(8, count($files));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['cms.trusted_scripts', 'cms.trusted_script_approvals', 'TrustedScriptApprovalStore', 'Content-Security-Policy', 'Http::get', 'Http::post'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file).' references '.$forbidden);
            }
        }
    }

    public function test_legacy_sanitizer_output_is_byte_identical_to_the_s2_base(): void
    {
        // Expected values recorded on the untouched base 908b5da
        // (docs/core-extension-trust/evidence/s2/baseline/sanitizer-corpus-908b5da.txt).
        $corpus = [
            '<p class="lead">Hello <strong>world</strong></p>' => '<p class="lead">Hello <strong>world</strong></p>',
            '<p onclick="alert(1)">x</p><script>alert(2)</script>' => '<p>x</p>',
            '<form method="post" action="/x"><input name="a"><button>Go</button></form><p>after</p>' => '<p>after</p>',
            '<a href="javascript:alert(1)">bad</a><a href="https://example.com" target="_blank">ok</a>' => '<a>bad</a><a href="https://example.com" target="_blank" rel="noopener noreferrer">ok</a>',
            '<img src="data:image/svg+xml,<svg onload=alert(1)>"><img src="/a.png" alt="A">' => '<img src="/a.png" alt="A">',
            '<div class="tncms-slot" data-provider="x">TNCMSSLOT0123456789abcdef0123456789abcdef</div>' => '<div class="tncms-slot">TNCMSSLOT0123456789abcdef0123456789abcdef</div>',
            '<section class="s"><details><summary>S</summary><p>D</p></details></section>' => '<section class="s"><details><summary>S</summary><p>D</p></details></section>',
            '<iframe src="https://evil.test"></iframe><svg><a xlink:href="javascript:1">x</a></svg>' => '',
            '<p style="color:red" data-x="1" id="i">styled</p>' => '<p>styled</p>',
            '<!-- comment --><table><tr><td colspan="2">c</td></tr></table>' => '<table><tr><td colspan="2">c</td></tr></table>',
        ];

        foreach ($corpus as $input => $expected) {
            $this->assertSame($expected, (new HtmlSanitizer)->sanitize($input), $input);
            $this->assertSame($expected, cms_html($input), $input);
        }
    }
}
