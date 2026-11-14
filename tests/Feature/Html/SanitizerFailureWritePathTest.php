<?php

declare(strict_types=1);

namespace Tests\Feature\Html;

use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\HtmlSanitizer;
use TheNguyen\CMS\Services\TaxonomyManager;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-01: a sanitizer parse/root failure must never be persisted as an empty body.
 * The write is refused through the existing error channels (ContentWriteDenied for content, ValidationException
 * for term descriptions) and the previously stored value survives; rendering stays fail-closed (empty) and logged.
 */
final class SanitizerFailureWritePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
    }

    private function breakSanitizer(): void
    {
        $broken = new class extends HtmlSanitizer
        {
            protected function findRoot(DOMDocument $dom): ?DOMElement
            {
                return null;
            }
        };

        $this->app->instance('cms.html', $broken);
        $this->app->forgetInstance('cms.content');
        $this->app->forgetInstance('cms.taxonomy');
        $this->assertInstanceOf(ContentManager::class, app('cms.content'));
        $this->assertInstanceOf(TaxonomyManager::class, app('cms.taxonomy'));
    }

    private function restoreSanitizer(): void
    {
        $this->app->instance('cms.html', new HtmlSanitizer);
        $this->app->forgetInstance('cms.content');
        $this->app->forgetInstance('cms.taxonomy');
    }

    public function test_create_with_a_failing_sanitizer_is_refused_and_leaves_no_row(): void
    {
        $this->breakSanitizer();

        try {
            app('cms.content')->create([
                'type' => 'post', 'status' => 'published', 'locale' => 'en',
                'title' => 'Broken', 'slug' => 'broken', 'content' => '<p>valid body</p>',
            ], ContentFixtures::context());
            $this->fail('the write must be refused');
        } catch (ContentWriteDenied $e) {
            $this->assertSame('content_unprocessable', $e->errorCode());
        }

        $this->assertSame(0, Content::query()->count());
    }

    public function test_update_with_a_failing_sanitizer_keeps_the_stored_body(): void
    {
        $content = app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => 'Kept', 'slug' => 'kept', 'content' => '<p>original body</p>',
        ], ContentFixtures::context());

        $this->breakSanitizer();

        foreach ([['content' => '<p>new body</p>'], ['title' => 'Only the title changes']] as $change) {
            try {
                app('cms.content')->update($content->fresh(), ['type' => 'post', 'locale' => 'en'] + $change, null, ContentFixtures::context());
                $this->fail('the write must be refused: '.json_encode($change));
            } catch (ContentWriteDenied $e) {
                $this->assertSame('content_unprocessable', $e->errorCode());
            }

            $this->assertSame('<p>original body</p>', $content->translations()->where('locale', 'en')->value('content'));
            $this->assertSame('Kept', $content->translations()->where('locale', 'en')->value('title'));
        }

        $this->restoreSanitizer();

        $updated = app('cms.content')->update($content->fresh(), ['type' => 'post', 'locale' => 'en', 'content' => ''], null, ContentFixtures::context());
        $this->assertSame('', (string) $updated->translations()->where('locale', 'en')->value('content'), 'an intentionally empty body is still accepted');
    }

    public function test_term_description_with_a_failing_sanitizer_is_refused_and_kept(): void
    {
        $term = app('cms.taxonomy')->createTerm('category', ['locale' => 'en', 'name' => 'News', 'description' => '<p>about news</p>']);

        $this->breakSanitizer();

        try {
            app('cms.taxonomy')->updateTerm($term->fresh(), ['locale' => 'en', 'name' => 'News', 'description' => '<p>changed</p>']);
            $this->fail('the write must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
        }

        $this->assertSame('<p>about news</p>', $term->translations()->where('locale', 'en')->value('description'));
    }

    public function test_render_with_a_failing_sanitizer_stays_empty_and_is_logged_without_content(): void
    {
        Log::spy();
        $this->breakSanitizer();

        $this->assertSame('', cms_html('<p>secret draft body</p>'));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, 'sanitizer') && ! str_contains(json_encode($context), 'secret draft body');
        })->atLeast()->once();
    }
}
