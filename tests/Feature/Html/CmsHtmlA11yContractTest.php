<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Services\HtmlSanitizer;

/**
 * CORE-HTML-SANITIZER-A11Y-R1 through the real container seams: the cms.html singleton, cms_html() output
 * and the ContentManager write-time sanitization. No consumer-specific code path is involved.
 */
final class CmsHtmlA11yContractTest extends TestCase
{
    use RefreshDatabase;

    private const MARKUP = '<section id="cms-content-results" aria-labelledby="cms-content-results-title"><h2 id="cms-content-results-title">Kết quả</h2>'
        .'<nav aria-label="Phân trang"><ul><li><a href="?page=1#cms-content-results" aria-current="page">1</a></li></ul></nav></section>';

    private const HOSTILE = '<section id="pb-frontend-canvas" role="region" data-x="1" onclick="alert(1)" aria-hidden="true">'
        .'<a href="javascript:alert(1)" aria-current="PAGE">x</a></section>';

    public function test_singleton_is_the_core_sanitizer(): void
    {
        $this->assertInstanceOf(HtmlSanitizer::class, app('cms.html'));
    }

    public function test_cms_html_keeps_the_bounded_contract(): void
    {
        $this->assertSame(self::MARKUP, (string) cms_html(self::MARKUP));
    }

    public function test_cms_html_strips_hostile_attributes_and_keeps_content(): void
    {
        $this->assertSame('<section><a>x</a></section>', (string) cms_html(self::HOSTILE));
    }

    public function test_cms_html_plain_text_path_is_unchanged(): void
    {
        $this->assertSame("a &amp; b<br />\nc", (string) cms_html("a & b\nc"));
    }

    public function test_content_write_keeps_valid_attributes_and_strips_hostile_ones(): void
    {
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => 'A11y R1', 'slug' => 'a11y-r1',
            'content' => self::MARKUP.self::HOSTILE,
        ], ContentFixtures::context());

        $stored = $post->translations()->where('locale', 'en')->value('content');

        $this->assertSame(self::MARKUP.'<section><a>x</a></section>', $stored);
    }

    public function test_trusted_slot_wrapper_is_untouched(): void
    {
        $slot = '<div class="tncms-slot">TNCMSSLOT0123456789abcdef0123456789abcdef</div>';

        $this->assertSame($slot, app('cms.html')->sanitize($slot));
        $this->assertSame('<div class="tncms-slot">x</div>', app('cms.html')->sanitize('<div class="tncms-slot" id="tncms-slot">x</div>'));
        // The slot marker is class-only; a namespaced authored id beside it is ordinary content and gains nothing.
        $this->assertSame(
            '<div class="tncms-slot" id="cms-content-slot-a">x</div>',
            app('cms.html')->sanitize('<div class="tncms-slot" id="cms-content-slot-a" data-slot="1">x</div>'),
        );
    }
}
