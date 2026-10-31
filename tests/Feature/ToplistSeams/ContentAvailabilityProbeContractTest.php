<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentSeoOverride;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate C (H-2): the active owner's read-only
 * availability probe runs before a 200 render and may only restrict. It never
 * persists, never widens Core's decision, and fails closed (404).
 */
final class ContentAvailabilityProbeContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    /** @var class-string */
    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
        $this->sxPlugin('sx-owner');
        $this->probe = $this->sxProvider('sx-owner', 'Probe');
    }

    public function test_invalid_owner_state_is_404_on_the_first_request_with_no_writes(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Corrupt');
        ($this->probe)::$probe = ContentAvailability::Unavailable;
        $before = $this->sxStateHash();

        $this->get($this->sxPath($post))->assertNotFound();

        $this->assertSame($before, $this->sxStateHash(), 'GET persisted nothing');
        $this->assertSame(['probe:'.$post->id.':vi'], ($this->probe)::$calls, 'probe ran once, SEO never asked');
    }

    public function test_no_opinion_and_available_render_normally(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Fine');

        ($this->probe)::$probe = null;
        $this->get($this->sxPath($post))->assertOk()->assertSee('<title>Probe Fine</title>', false);

        ($this->probe)::$probe = ContentAvailability::Available;
        $this->get($this->sxPath($post, 'en'))->assertOk()->assertSee('<title>Probe Fine EN</title>', false);
    }

    public function test_probe_answers_map_through_the_existing_availability_contract(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Map');

        ($this->probe)::$probe = ContentAvailability::Gone;
        $this->get($this->sxPath($post))->assertNotFound();

        ($this->probe)::$probe = ContentAvailability::TemporarilyUnavailable;
        $this->get($this->sxPath($post))->assertStatus(503)->assertHeader('Retry-After', '300');
    }

    public function test_exception_and_invalid_result_fail_closed_and_log_class_only(): void
    {
        Log::spy();
        $post = $this->sxOwnedPost('sx-owner', 'Probe Throws');

        ($this->probe)::$probe = 'throw';
        $response = $this->get($this->sxPath($post))->assertNotFound();
        $this->assertStringNotContainsString('hunter2', (string) $response->getContent());

        ($this->probe)::$probe = 'invalid';
        $this->get($this->sxPath($post))->assertNotFound();

        Log::shouldHaveReceived('warning')->withArgs(static fn ($message, $context) => $message === 'TN CMS content availability probe failed'
            && $context['exception'] === \RuntimeException::class
            && ! str_contains((string) json_encode($context), 'hunter2'))->once();
        Log::shouldHaveReceived('warning')->withArgs(static fn ($message, $context) => $message === 'TN CMS content availability probe failed'
            && $context['exception'] === \TypeError::class)->once();
    }

    public function test_a_probe_cannot_persist_from_a_get(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Writes');
        ($this->probe)::$probe = 'write';
        $before = $this->sxStateHash();

        $this->get($this->sxPath($post))->assertOk();

        $this->assertSame($before, $this->sxStateHash(), 'the probe\'s write was rolled back');
        $this->assertSame('available', DB::table('cms_content_owner_policies')->where('content_id', $post->id)->value('availability'));
    }

    public function test_unbalanced_probe_transactions_fail_closed_and_restore_the_level(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Unbalanced');

        foreach (['open-throw', 'commit'] as $behaviour) {
            ($this->probe)::$probe = $behaviour;
            $level = DB::transactionLevel();

            $this->get($this->sxPath($post))->assertNotFound();

            $this->assertSame($level, DB::transactionLevel(), $behaviour.': back at the starting level');
        }
    }

    public function test_probe_cannot_widen_core_or_persisted_decisions(): void
    {
        ($this->probe)::$probe = ContentAvailability::Available;

        $unavailable = $this->sxOwnedPost('sx-owner', 'Probe Persisted');
        $this->sxCode('sx-owner')->availability($unavailable->id, ContentAvailability::Unavailable);
        $this->get($this->sxPath($unavailable))->assertNotFound();

        $draft = $this->sxOwnedPost('sx-owner', 'Probe Draft');
        DB::table('cms_contents')->where('id', $draft->id)->update(['status' => 'draft']);
        $this->get($this->sxPath($draft))->assertNotFound();

        $this->assertSame([], ($this->probe)::$calls, 'never asked once Core or the persisted state refused');
    }

    public function test_core_content_and_inactive_owner_fallback_never_call_the_probe(): void
    {
        ($this->probe)::$probe = ContentAvailability::Unavailable;
        $core = $this->sxCorePost('Probe Core');
        $adopted = $this->sxOwnedPost('sx-owner', 'Probe Adopted', fallback: ContentFallbackPolicy::CoreRender);

        $this->get($this->sxPath($core))->assertOk();

        app('cms.extension')->deactivatePlugin('sx-owner');
        app('cms.extension')->flushRegistry();
        $this->get($this->sxPath($adopted))->assertOk()->assertSee('<title>Probe Adopted</title>', false);

        $this->assertSame([], ($this->probe)::$calls);
    }

    public function test_provider_without_the_probe_contract_is_unchanged(): void
    {
        $this->sxPlugin('sx-plain');
        $plain = $this->sxProvider('sx-plain', 'SeoOnly');
        $plain::$probe = ContentAvailability::Unavailable;
        $plain::$override = new ContentSeoOverride(title: 'Plain Owner Title');
        $post = $this->sxOwnedPost('sx-plain', 'Probe Plain');

        $this->get($this->sxPath($post))->assertOk()->assertSee('<title>Plain Owner Title</title>', false);
        $this->assertNotContains('probe:'.$post->id.':vi', $plain::$calls);
    }

    public function test_preview_never_calls_the_probe(): void
    {
        $post = $this->sxOwnedPost('sx-owner', 'Probe Preview');
        ($this->probe)::$probe = ContentAvailability::Unavailable;

        $this->get(app('cms.preview')->temporaryUrl($post, locale: 'vi'))->assertOk()->assertSee('Probe Preview');

        $this->assertSame([], ($this->probe)::$calls);
    }
}
