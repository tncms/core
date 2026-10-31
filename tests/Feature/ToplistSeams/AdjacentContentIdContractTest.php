<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Contracts\AdjacentPostResolver;
use TheNguyen\CMS\View\AdjacentPostViewModel;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate F (H-6): the adjacent navigation item carries
 * the content id it already resolved (like PostCardViewModel), so a theme can
 * identify an owner-managed neighbour from public data. Ordering, visibility
 * and query count are unchanged.
 */
final class AdjacentContentIdContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
        $this->sxPlugin('sx-owner');
    }

    public function test_adjacent_items_carry_the_resolved_content_id(): void
    {
        $older = $this->sxCorePost('Adjacent Older', ['published_at' => now()->subDays(3)]);
        $middle = $this->sxCorePost('Adjacent Middle', ['published_at' => now()->subDays(2)]);
        $newer = $this->sxOwnedPost('sx-owner', 'Adjacent Newer', ['published_at' => now()->subDay()]);

        $resolver = app(AdjacentPostResolver::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $previous = $resolver->previous($middle, 'vi');
        $next = $resolver->next($middle, 'vi');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('Adjacent Older', $previous?->title);
        $this->assertSame((int) $older->id, $previous->id);
        $this->assertNull($previous->ownership);
        $this->assertSame((int) $newer->id, $next?->id);
        $this->assertSame(['owner' => 'sx-owner', 'state' => 'active'], $next->ownership?->toArray());
        $this->assertSame((int) $newer->id, $next->toArray()['id']);
        // Measured on the .38 baseline resolver for the same fixtures: 12 (the id is the resolved row's key).
        $this->assertSame(12, $queries, 'id adds no query');
    }

    public function test_hidden_neighbours_are_still_skipped(): void
    {
        $older = $this->sxCorePost('Adjacent Skip Older', ['published_at' => now()->subDays(3)]);
        $hidden = $this->sxOwnedPost('sx-owner', 'Adjacent Skip Hidden', ['published_at' => now()->subDays(2)]);
        $this->sxCode('sx-owner')->availability($hidden->id, \TheNguyen\CMS\Content\Extension\ContentAvailability::Unavailable);
        $newer = $this->sxCorePost('Adjacent Skip Newer', ['published_at' => now()->subDay()]);

        $this->assertSame((int) $older->id, app(AdjacentPostResolver::class)->previous($newer, 'vi')?->id);
    }

    public function test_legacy_construction_and_array_shape_are_compatible(): void
    {
        $legacy = new AdjacentPostViewModel('x', '/x');
        $this->assertNull($legacy->id);
        $this->assertSame(['title' => 'x', 'url' => '/x', 'published_at' => null], $legacy->toArray());

        $this->assertSame(5, (new AdjacentPostViewModel('x', '/x', null, null, 5))->toArray()['id']);
    }
}
