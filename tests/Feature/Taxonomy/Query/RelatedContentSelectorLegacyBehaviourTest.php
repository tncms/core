<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Query;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\Blog\RelatedContentSelector;

/**
 * CORE-P17-1 legacy guard: the strict public seam is additive. The existing `RelatedContentSelector`
 * (Page Builder related source, default post-detail related list) keeps its documented behaviour, including the
 * automatic-mode "latest excluding self" degradation for a term-less post and the same_author/current_post_only modes.
 */
final class RelatedContentSelectorLegacyBehaviourTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_selector_behaviour_is_unchanged(): void
    {
        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $a = (int) app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'A'])->getKey();

        $make = static fn (array $terms, ?int $author = null): Content => tap(
            Content::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subDay(), 'author_id' => $author]),
            static fn (Content $c) => $c->terms()->attach($terms),
        );
        $p1 = $make([$a], 7);
        $p2 = $make([$a], 7);
        $bare = $make([], 8);
        $other = $make([], 7);

        $selector = app(RelatedContentSelector::class);
        $ids = static fn ($q): array => $q->orderBy('id')->pluck('id')->map(static fn ($v): int => (int) $v)->all();

        $q = Content::query()->where('type', 'post')->published();
        $this->assertTrue($selector->apply($q, $bare, 'automatic'), 'legacy automatic degrades instead of failing');
        $this->assertSame([$p1->id, $p2->id, $other->id], $ids($q), 'legacy: latest excluding self');

        $q = Content::query()->where('type', 'post')->published();
        $this->assertTrue($selector->apply($q, $p1, 'automatic'));
        $this->assertSame([$p2->id], $ids($q));

        $q = Content::query()->where('type', 'post')->published();
        $this->assertFalse($selector->apply($q, $bare, 'same_category'));

        $q = Content::query()->where('type', 'post')->published();
        $this->assertTrue($selector->apply($q, $p1, 'same_author'));
        $this->assertSame([$p2->id, $other->id], $ids($q));

        $q = Content::query()->where('type', 'post')->published();
        $this->assertTrue($selector->apply($q, $p1, 'current_post_only'));
        $this->assertSame([$p1->id], $ids($q));

        $q = Content::query()->where('type', 'post')->published();
        $this->assertTrue($selector->apply($q, $p1, 'unknown-mode'), 'unknown modes still fall through to automatic');
        $this->assertSame([$p2->id], $ids($q));
    }
}
