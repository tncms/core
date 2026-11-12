<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * H-Z1-M2 security backport (.39): the Posts / Pages admin lists put the item title into
 * the featured-image <img alt>. Filament renders extraImgAttributes values unescaped, so
 * Core must escape the title. Whatever an author types as a title, the list must render it
 * as the alt TEXT, byte for byte, and must never gain, lose or rename an <img> attribute.
 */
final class ContentListThumbnailAltEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const CELL_IMG = "//td[contains(@class,'fi-ta-cell-featured-image')]//img";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create(['admin_locale' => 'en']);
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    public static function lists(): array
    {
        return ['posts' => ['post', '/admin/posts'], 'pages' => ['page', '/admin/pages']];
    }

    /** Titles an author may legitimately (or hostilely) type. Each must round-trip as text. */
    public static function titles(): array
    {
        $attr = 'on'.'focus'; // any attribute-shaped token; nothing executable is needed to prove the boundary

        return [
            'plain' => ['Harbour at dawn'],
            'double quotes' => ['The "quoted" title'],
            'apostrophes' => ["Children's book — it's here"],
            'unicode' => ['Tiếng Việt có dấu – 日本語 – emoji 🌅'],
            'html-looking text' => ['<b>bold</b> & <i>italic</i> <script>x</script>'],
            'attribute-looking (quoted)' => ['a" data-injected="1" title="b'],
            'attribute-looking (unquoted)' => ['a" '.$attr.'=x b="'],
            'single-quote break-out' => ["a' data-injected='1"],
            'pre-escaped entities' => ['&quot; &amp; &lt;x&gt;'],
        ];
    }

    private function item(string $type, string $title): void
    {
        app('cms.content')->create([
            'type' => $type, 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => $type.'-'.substr(md5($title), 0, 10),
            'content' => '<p>Body</p>', 'featured_image' => 'https://cdn.example.test/a.jpg',
        ], \Tests\Support\ContentFixtures::context());
    }

    private function thumbnail(string $url): DOMElement
    {
        $html = $this->get($url)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $imgs = (new DOMXPath($dom))->query(self::CELL_IMG);
        $this->assertSame(1, $imgs->length, 'exactly one list thumbnail');

        return $imgs->item(0);
    }

    /** @return list<string> */
    private function attributeNames(DOMElement $img): array
    {
        $names = [];
        foreach ($img->attributes as $a) {
            $names[] = $a->nodeName;
        }
        sort($names);

        return $names;
    }

    #[DataProvider('lists')]
    public function test_plain_title_is_the_thumbnail_alt(string $type, string $url): void
    {
        $this->item($type, 'Harbour at dawn');

        $this->assertSame('Harbour at dawn', $this->thumbnail($url)->getAttribute('alt'));
    }

    /** @return array<string, array{string, string, string}> lists x titles */
    public static function listTitles(): array
    {
        $out = [];
        foreach (self::lists() as $list => [$type, $url]) {
            foreach (self::titles() as $case => [$title]) {
                $out["$list / $case"] = [$type, $url, $title];
            }
        }

        return $out;
    }

    /**
     * The attribute set for any title must equal the set rendered for a plain title, and
     * the alt must be the exact title text.
     */
    #[DataProvider('listTitles')]
    public function test_title_renders_as_text_and_never_changes_the_img_attributes(string $type, string $url, string $title): void
    {
        $this->item($type, 'Harbour at dawn');
        $expected = $this->attributeNames($this->thumbnail($url));
        $this->assertContains('alt', $expected);
        \TheNguyen\CMS\Models\Content::query()->where('type', $type)->get()->each->forceDelete();

        $this->item($type, $title);
        $img = $this->thumbnail($url);

        $this->assertSame($expected, $this->attributeNames($img), 'the title changed the <img> attribute set');
        foreach ($this->attributeNames($img) as $name) {
            $this->assertStringStartsNotWith('on', $name, 'an event-handler attribute was created from the title');
        }
        $this->assertSame($title, $img->getAttribute('alt'), 'the alt is not the exact title text');
    }
}
