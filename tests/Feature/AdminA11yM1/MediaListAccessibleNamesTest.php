<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM1;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-40-H-Z1-M1 — a populated Media library list must give every preview image a text
 * alternative and every row link in the preview / caption cells an accessible name
 * (WCAG 1.1.1, 2.4.4, 4.1.2). Metadata reaching those attributes must be escaped, because
 * Filament does not escape extraImgAttributes / extraAttributes values.
 */
final class MediaListAccessibleNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $languages = app('cms.language');
        $languages->create(['code' => 'en', 'name' => 'English', 'is_default' => true]);
        $languages->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_active' => true]);
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder::class);
    }

    private function admin(string $locale): User
    {
        $user = User::factory()->create(['admin_locale' => $locale]);
        Role::query()->where('slug', 'admin')->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();

        return $user;
    }

    private function media(array $attributes): Media
    {
        return Media::query()->create($attributes + [
            'disk' => 'public', 'folder' => 'uploads/2026/11', 'size' => 1000, 'extension' => 'png', 'mime_type' => 'image/png',
            'filename' => 'stored.png', 'path' => 'uploads/2026/11/stored.png', 'url' => '/uploads/2026/11/stored.png',
        ]);
    }

    private function xpath(string $locale): DOMXPath
    {
        $html = $this->actingAs($this->admin($locale))->get('/admin/media')->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    /** @return list<DOMElement> */
    private function cells(DOMXPath $x, string $column): array
    {
        return iterator_to_array($x->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' fi-ta-cell-{$column} ')]"));
    }

    /** Accessible name of a link computed the way it matters here: aria-label, else img alt / role=img aria-label / text. */
    private function linkName(DOMElement $a): string
    {
        if ($a->hasAttribute('aria-label')) {
            return trim($a->getAttribute('aria-label'));
        }
        $parts = [];
        $x = new DOMXPath($a->ownerDocument);
        foreach ($x->query('.//img[@alt] | .//*[@role="img"][@aria-label]', $a) as $node) {
            $parts[] = $node->getAttribute($node->nodeName === 'img' ? 'alt' : 'aria-label');
        }
        $parts[] = trim($a->textContent);

        return trim(implode(' ', array_filter($parts, fn ($p) => trim($p) !== '')));
    }

    public function test_every_preview_image_has_alt_text_and_every_preview_link_is_named(): void
    {
        $this->media(['original_filename' => 'harbour-at-dawn.png', 'alt' => 'Harbour', 'caption' => 'A caption']);
        $this->media(['original_filename' => 'brochure.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'filename' => 'brochure.pdf']);

        $cells = $this->cells($this->xpath('en'), 'preview');
        $this->assertCount(2, $cells, 'fixture: two populated rows');

        foreach ($cells as $td) {
            $img = $td->getElementsByTagName('img')->item(0);
            $this->assertNotNull($img);
            $this->assertTrue($img->hasAttribute('alt'), 'preview <img> needs an alt attribute (1.1.1)');
            $this->assertNotSame('', trim($img->getAttribute('alt')), 'the preview is the only content of a link, so its alt names the link');
            $a = $td->getElementsByTagName('a')->item(0);
            $this->assertNotNull($a);
            $this->assertNotSame('', $this->linkName($a), 'preview row link needs an accessible name (2.4.4, 4.1.2)');
        }

        $names = array_map(fn (DOMElement $td) => $td->getElementsByTagName('img')->item(0)->getAttribute('alt'), $cells);
        $this->assertContains('Media: harbour-at-dawn.png', $names);
        $this->assertContains('Media: brochure.pdf', $names, 'the non-image fallback icon is named after the media too');
    }

    public function test_every_caption_link_is_named_with_the_caption_state(): void
    {
        $this->media(['original_filename' => 'with-caption.png', 'caption' => 'Something']);
        $this->media(['original_filename' => 'without-caption.png', 'caption' => null]);

        $names = array_map(fn (DOMElement $td) => $this->linkName($td->getElementsByTagName('a')->item(0)), $this->cells($this->xpath('en'), 'caption'));
        sort($names);

        $this->assertSame(['Caption: no', 'Caption: yes'], $names);
    }

    public function test_names_are_localized_in_vietnamese(): void
    {
        $this->media(['original_filename' => 'anh.png', 'caption' => 'Có']);

        $x = $this->xpath('vi');
        $alt = $this->cells($x, 'preview')[0]->getElementsByTagName('img')->item(0)->getAttribute('alt');
        $caption = $this->linkName($this->cells($x, 'caption')[0]->getElementsByTagName('a')->item(0));

        $this->assertSame('Tệp: anh.png', $alt);
        $this->assertSame('Chú thích: có', $caption);
    }

    public function test_missing_filenames_fall_back_to_the_record_number(): void
    {
        $m = $this->media(['original_filename' => '', 'filename' => '']);

        $alt = $this->cells($this->xpath('en'), 'preview')[0]->getElementsByTagName('img')->item(0)->getAttribute('alt');

        $this->assertSame('Media: #'.$m->getKey(), $alt);
    }

    public function test_hostile_metadata_is_escaped_and_bounded_in_the_accessible_name(): void
    {
        $evil = "x\" onerror=\"alert(1)\" data-x=\"<script>alert(2)</script>\x00\x1F\r\n".str_repeat('a', 150).'.png';
        $this->media(['original_filename' => $evil]);

        $html = $this->actingAs($this->admin('en'))->get('/admin/media')->assertOk()->getContent();

        $this->assertStringNotContainsString('onerror="alert(1)"', $html, 'no attribute injection through the file name');
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $img = $this->cells(new DOMXPath($dom), 'preview')[0]->getElementsByTagName('img')->item(0);

        $this->assertFalse($img->hasAttribute('onerror'));
        $alt = $img->getAttribute('alt');
        $this->assertStringStartsWith('Media: x" onerror="alert(1)"', $alt, 'the name is shown as text');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $alt, 'control characters never reach the name');
        $this->assertLessThanOrEqual(100, mb_strlen($alt), 'the name is bounded');
    }
}
