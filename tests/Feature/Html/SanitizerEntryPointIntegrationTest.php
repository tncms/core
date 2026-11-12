<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Widgets\HtmlWidget;

/**
 * CORE-HTML-SANITIZER-INTEGRATION-R1: the integrated `cms-content-*` / bounded ARIA contract holds at every Core
 * authored-HTML entry point on the integrated line (page and post bodies on create and update, taxonomy
 * descriptions, the HTML widget) and through the real frontend render of the default theme in both locales.
 * Authored HTML can never claim the Page Builder runtime canvas id or any other non-namespaced id.
 */
final class SanitizerEntryPointIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SAFE = '<section id="cms-content-results" aria-labelledby="cms-content-results-title">'
        .'<h2 id="cms-content-results-title">Results</h2>'
        .'<nav aria-label="Pages"><ul><li><a href="#cms-content-results" aria-current="page">1</a></li></ul></nav></section>';

    private const HOSTILE = '<div id="pb-frontend-canvas" data-pb-frontend-canvas><p>fake canvas</p></div>'
        .'<p id="Cms-Content-Upper" aria-describedby="cms-content-results" aria-controls="cms-content-results" role="region" '
        .'style="color:red" onclick="alert(1)"><a href="javascript:alert(1)" aria-current="PAGE">x</a></p>'
        .'<p id="cms-content-results">duplicate</p><img src="x" onerror="alert(1)">';

    private const HOSTILE_SANITIZED = '<div><p>fake canvas</p></div><p><a>x</a></p><p>duplicate</p><img src="x">';

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
    }

    public function test_page_and_post_bodies_are_sanitized_on_create_and_update_and_render_in_both_locales(): void
    {
        foreach (['page' => ['entry-page', 'trang-muc'], 'post' => ['entry-post', 'bai-muc']] as $type => [$enSlug, $viSlug]) {
            $content = app('cms.content')->create([
                'type' => $type, 'status' => 'published', 'locale' => 'en',
                'title' => 'Entry '.$type, 'slug' => $enSlug, 'content' => self::SAFE.self::HOSTILE,
            ], ContentFixtures::context());

            $content = app('cms.content')->update($content, [
                'type' => $type, 'locale' => 'vi',
                'title' => 'Mục '.$type, 'slug' => $viSlug, 'content' => self::HOSTILE.self::SAFE,
            ], null, ContentFixtures::context());

            $en = $content->translations()->where('locale', 'en')->value('content');
            $vi = $content->translations()->where('locale', 'vi')->value('content');

            $this->assertSame(self::SAFE.self::HOSTILE_SANITIZED, $en, $type.' en stored');
            // In vi the hostile duplicate comes first, so it wins the id and the later section loses it.
            $this->assertStringContainsString('<p id="cms-content-results">duplicate</p>', $vi);
            $this->assertStringContainsString('<section aria-labelledby="cms-content-results-title">', $vi);
            $this->assertStringNotContainsString('pb-frontend-canvas"', $vi);

            foreach (['en' => content_url($content->fresh(), 'en'), 'vi' => content_url($content->fresh(), 'vi')] as $locale => $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertStringNotContainsString('id="pb-frontend-canvas"', $html, "$type $locale render");
                $this->assertStringNotContainsString('data-pb-frontend-canvas', $html, "$type $locale render");
                $this->assertStringNotContainsString('onerror', $html, "$type $locale render");
                $this->assertStringNotContainsString('javascript:alert', $html, "$type $locale render");
                $this->assertStringContainsString('<h2 id="cms-content-results-title">Results</h2>', $html, "$type $locale render");
                $this->assertSame(1, substr_count($html, 'id="cms-content-results"'), "$type $locale single winning id");
            }
        }
    }

    public function test_taxonomy_description_is_sanitized_on_write_and_rendered_on_the_archive(): void
    {
        $term = app('cms.taxonomy')->createTerm('category', [
            'locale' => 'en', 'name' => 'Entry Category', 'slug' => 'entry-category',
            'description' => self::SAFE.self::HOSTILE,
        ]);

        $this->assertSame(self::SAFE.self::HOSTILE_SANITIZED, $term->translatedDescription('en'));

        $html = $this->get(term_url($term, 'en'))->assertOk()->getContent();
        $this->assertStringContainsString('<section id="cms-content-results" aria-labelledby="cms-content-results-title">', $html);
        $this->assertStringNotContainsString('id="pb-frontend-canvas"', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_html_widget_render_applies_the_contract(): void
    {
        $out = (new HtmlWidget)->render(['title' => 'W', 'html' => self::SAFE.self::HOSTILE]);

        $this->assertStringContainsString('<div class="tn-widget__body">'.self::SAFE.self::HOSTILE_SANITIZED.'</div>', $out);
        $this->assertStringNotContainsString('pb-frontend-canvas"', $out);
    }

    public function test_namespace_variants_and_runtime_ids_never_survive_any_entry_point(): void
    {
        $rejected = [
            'pb-frontend-canvas', 'cms-content', 'cms-content-', 'cms-content--a', 'cms-content-a-', 'Cms-Content-a',
            'cms-content-A', 'cms-content-a_b', 'cms-content-a.b', ' cms-content-a', 'cms-content-a ', 'cms-content-é',
            'xcms-content-a', 'cms-contents-a', 'cms-content-'.str_repeat('a', 53), "cms-content-a\n",
        ];

        foreach ($rejected as $id) {
            $html = '<div id="'.htmlspecialchars($id, ENT_QUOTES).'">x</div>';
            $this->assertSame('<div>x</div>', cms_html($html), var_export($id, true));
            $this->assertStringContainsString('<div class="tn-widget__body"><div>x</div></div>', (new HtmlWidget)->render(['html' => $html]), var_export($id, true));
        }

        // Exactly 64 bytes is the longest accepted id; 13 bytes the shortest.
        foreach (['cms-content-'.str_repeat('a', 52), 'cms-content-a'] as $id) {
            $this->assertSame('<div id="'.$id.'">x</div>', cms_html('<div id="'.$id.'">x</div>'), $id);
        }
    }
}
