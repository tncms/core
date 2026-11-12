<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM1;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-40-H-Z1-M2 probe — the Posts / Pages list puts the item title into the featured-image
 * <img alt>. Filament does not escape extraImgAttributes values, so the title must be escaped
 * by Core and the thumbnail must keep a text alternative.
 */
final class ContentListFeaturedImageAltTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create(['admin_locale' => 'en']);
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private function item(string $type, string $title): void
    {
        app('cms.content')->create([
            'type' => $type, 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => $type.'-'.substr(md5($title), 0, 8),
            'content' => '<p>Body</p>', 'featured_image' => 'https://cdn.example.test/a.jpg',
        ], \Tests\Support\ContentFixtures::context());
    }

    /** @return array{0:string,1:DOMXPath} */
    private function page(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return [$html, new DOMXPath($dom)];
    }

    public static function lists(): array
    {
        return [['post', '/admin/posts'], ['page', '/admin/pages']];
    }

    #[DataProvider('lists')]
    public function test_hostile_title_cannot_inject_attributes_into_the_thumbnail(string $type, string $url): void
    {
        $this->item($type, 'x" onerror="alert(1)" data-y="<b>z</b>');

        [$html, $x] = $this->page($url);

        $this->assertStringNotContainsString('onerror="alert(1)"', $html, 'the title must not break out of the alt attribute');
        $imgs = $x->query("//td[contains(@class,'fi-ta-cell-featured-image')]//img");
        $this->assertSame(1, $imgs->length);
        $this->assertFalse($imgs->item(0)->hasAttribute('onerror'));
        $this->assertSame('x" onerror="alert(1)" data-y="<b>z</b>', $imgs->item(0)->getAttribute('alt'), 'the title is shown as text');
    }

    #[DataProvider('lists')]
    public function test_unquoted_handler_in_title_cannot_become_an_attribute(string $type, string $url): void
    {
        $this->item($type, 'x" onerror=window.__m2=1 z="');

        [$html, $x] = $this->page($url);

        $img = $x->query("//td[contains(@class,'fi-ta-cell-featured-image')]//img")->item(0);
        $this->assertNotNull($img);
        $this->assertFalse($img->hasAttribute('onerror'), 'no event-handler attribute may be created from a title');
        $this->assertFalse($img->hasAttribute('z'));
        $this->assertSame('x" onerror=window.__m2=1 z="', $img->getAttribute('alt'));
    }

    #[DataProvider('lists')]
    public function test_thumbnail_alt_names_the_item(string $type, string $url): void
    {
        $this->item($type, 'Harbour at dawn');

        [, $x] = $this->page($url);

        $img = $x->query("//td[contains(@class,'fi-ta-cell-featured-image')]//img")->item(0);
        $this->assertNotNull($img);
        $this->assertSame('Harbour at dawn', $img->getAttribute('alt'));
    }
}
