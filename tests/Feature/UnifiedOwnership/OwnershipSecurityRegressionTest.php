<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\LegacyOwnershipReconciler;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gate P: red-to-green regressions for the
 * independent security review (see CORE-UNIFIED-OWNERSHIP-R1-SECURITY.md).
 */
final class OwnershipSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
        $this->guardedPlugin('uo-alpha');
        $this->guardedPlugin('uo-beta');
    }

    /** Plugin B's own code calling the low-level release with A's slug. */
    private function releaseFromBeta(int $id): string
    {
        $file = $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'uo-beta'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Rogue.php';
        File::put($file, "<?php\nnamespace ".$this->authorityNamespace('uo-beta').";\nfinal class Rogue { public function run(int \$id): mixed { return app('cms.content')->releaseOwnership(\$id, 'uo-alpha'); } }\n");
        $fqcn = $this->loadAuthorityClass('uo-beta', 'Rogue');

        return $this->denial(fn () => (new $fqcn)->run($id));
    }

    public function test_m1_a_plugin_cannot_release_another_plugins_content_through_the_service(): void
    {
        $this->actingAs($this->superAdmin());
        $post = $this->ownerCode('uo-alpha')->own(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Mine', 'content' => 'x']);
        $this->ownerCode('uo-alpha')->availability($post->id, ContentAvailability::Unavailable);
        $before = $this->persisted($post->id);

        $outcome = $this->releaseFromBeta($post->id);

        $this->assertNotSame('ALLOWED', $outcome);
        $this->assertSame($before, $this->persisted($post->id), 'owner, policy and audit untouched');
        $this->assertSame('uo-alpha', $post->refresh()->managed_by);
    }

    public function test_m2_owned_creation_of_the_conventional_home_page_is_refused(): void
    {
        $this->actingAs($this->superAdmin());
        $home = ['type' => 'page', 'status' => 'published', 'locale' => 'vi', 'title' => 'Trang chu', 'slug' => 'trang-chu', 'content' => 'x'];

        $this->assertSame('front_page', $this->denial(fn () => $this->ownerCode('uo-alpha')->own($home)));
        $this->assertSame(0, DB::table('cms_contents')->whereNotNull('managed_by')->count());
    }

    public function test_m2_an_operator_can_release_an_owned_page_that_became_the_front_page(): void
    {
        $operator = $this->userWithRole('admin', ['content.authority.recover']);
        $this->actingAs($this->superAdmin());
        $page = $this->ownerCode('uo-alpha')->own(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Landing', 'content' => 'x']);
        settings()->set('reading.homepage_page_id', $page->id, 'integer');
        auth()->logout();

        $this->artisan('cms:content-owner', ['action' => 'release', 'content' => $page->id, '--from' => 'uo-alpha', '--actor' => $operator->email, '--reason' => 'front page'])
            ->assertSuccessful();
        $this->assertNull($page->refresh()->managed_by);
    }

    public function test_l3_unauthorised_callers_learn_nothing_about_other_owners(): void
    {
        $this->actingAs($this->superAdmin());
        $owned = $this->ownerCode('uo-alpha')->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Secret Owner', 'content' => 'x']);

        // Signed in without the adopt grant: refused before the row is inspected.
        $this->assertSame('unauthorized_adopt', $this->denial(fn () => $this->ownerCode('uo-beta')->claim($owned, $owned->revision)));
        $this->assertSame('unauthorized_adopt', $this->denial(fn () => $this->ownerCode('uo-beta')->claim(987654, 0)), 'no existence oracle');
    }

    public function test_l4_reconciler_treats_front_page_and_ungoverned_types_as_conflicts_and_applies_atomically(): void
    {
        Schema::create('cms_content_ownership', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_id')->primary();
            $table->string('owner', 64);
            $table->string('fallback_policy', 32);
            $table->string('availability', 32)->default('available');
            $table->unsignedInteger('retry_after')->nullable();
            $table->boolean('operator_assigned')->default(false);
            $table->timestamps();
        });
        $front = $this->makeContent(['title' => 'Front', 'type' => 'page', 'status' => 'published']);
        settings()->set('reading.homepage_page_id', $front->id, 'integer');
        $faq = $this->makeContent(['title' => 'FAQ', 'type' => 'faq', 'status' => 'published']);
        $row = static fn (int $id) => ['content_id' => $id, 'owner' => 'uo-alpha', 'fallback_policy' => 'hide_404', 'availability' => 'available', 'created_at' => now(), 'updated_at' => now()];
        DB::table('cms_content_ownership')->insert([$row($front->id), $row($faq->id)]);

        $reasons = array_column((new LegacyOwnershipReconciler)->plan()['conflicts'], 'reason');
        sort($reasons);
        $this->assertSame(['front_page', 'unsupported_type'], $reasons);
    }

    public function test_l5_a_claim_never_inherits_a_stale_policy_row(): void
    {
        $post = $this->coreArticle('Stale Policy');
        DB::table('cms_content_owner_policies')->insert(['content_id' => $post->id, 'fallback_policy' => 'hide_404', 'availability' => 'gone', 'operator_assigned' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->adopter());

        $this->ownerCode('uo-alpha')->claim($post, $post->revision, null, ContentFallbackPolicy::CoreRender);

        $own = app('cms.content_ownership')->ownershipOf($post->id);
        $this->assertSame([ContentFallbackPolicy::CoreRender, ContentAvailability::Available, false], [$own->fallback, $own->availability, $own->operatorAssigned]);
    }

    public function test_l6_listings_use_the_current_locale_body_like_the_page(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = $this->ownerCode('uo-alpha');
        $post = $owner->own(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Bodies', 'content' => '<p>en</p>'], ContentFallbackPolicy::CoreRender);
        $owner->update($post, ['locale' => 'vi', 'title' => 'Than', 'slug' => 'than', 'content' => '   '], $post->revision);
        app('cms.extension')->deactivatePlugin('uo-alpha');
        app('cms.extension')->flushRegistry();

        app('cms.language')->setCurrent('vi');
        $this->assertNotContains($post->id, Content::query()->published()->discoverable()->pluck('id')->all(), 'whitespace-only vi body: hidden in vi listings');
        app('cms.language')->setCurrent('en');
        $this->assertContains($post->id, Content::query()->published()->discoverable()->pluck('id')->all(), 'en body: listed in en');
    }
}
