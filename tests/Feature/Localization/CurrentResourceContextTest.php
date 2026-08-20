<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\CurrentLocalizationContextFactory;
use TheNguyen\CMS\Localization\CurrentResourceContext;
use TheNguyen\CMS\Localization\CurrentResourcePublisher;
use TheNguyen\CMS\Localization\CurrentResourceReference;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use Tests\TestCase;

/**
 * CORE-L10N.1B (Phase P3.3 / P3.3B) — the generic Current Resource Context.
 *
 * Proves one request-scoped, typed, SEO-independent, strategy-neutral authority
 * identifies the current frontend resource; that its write is WRITE-ONCE (a
 * lock) so nothing can overwrite the current resource; that the render boundary
 * ({@see CurrentResourcePublisher}) is the writer; that language switching
 * consumes it; that a third-party plugin resource participates with no Core
 * change; and that Core Page/Post switching stays byte-identical.
 */
final class CurrentResourceContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    private function context(): CurrentResourceContext
    {
        return app('cms.localization.current_resource');
    }

    private function factory(): CurrentLocalizationContextFactory
    {
        return app('cms.localization.context_factory');
    }

    private function publisher(): CurrentResourcePublisher
    {
        return app('cms.localization.current_resource_publisher');
    }

    /** @return array<string, array{code:string,url:string,active:bool}> */
    private function switcherByCode(): array
    {
        $out = [];
        foreach (language_switcher() as $entry) {
            $out[$entry['code']] = $entry;
        }

        return $out;
    }

    // -- resource reference contract --------------------------------------------

    public function test_reference_is_typed_immutable_and_rejects_bad_input(): void
    {
        $ref = CurrentResourceReference::of('ecommerce.product', new \stdClass, 7, 'ecommerce');
        $this->assertSame('ecommerce.product', $ref->type);
        $this->assertSame(7, $ref->identity);
        $this->assertSame('ecommerce', $ref->owner);
        $this->assertArrayNotHasKey('resource', $ref->toArray()); // never leaks the object

        $this->assertTrue(CurrentResourceReference::home()->isHome());

        $this->expectException(\InvalidArgumentException::class);
        CurrentResourceReference::of('', new \stdClass);
    }

    public function test_reference_rejects_url_shaped_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CurrentResourceReference::of('https://evil/x', new \stdClass);
    }

    // -- write-once, read, clear ------------------------------------------------

    public function test_first_publish_wins_and_locks(): void
    {
        $this->assertFalse($this->context()->has());
        $this->assertNull($this->context()->current());

        $a = CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme');
        $this->context()->publish($a);

        $this->assertTrue($this->context()->has());
        $this->assertSame($a, $this->context()->current());
        $this->assertFalse($this->context()->hadConflict());
    }

    public function test_identical_republish_is_idempotent(): void
    {
        $this->context()->publish(CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme'));
        // A byte-equal identity (type + identity + owner) is a no-op, not a conflict.
        $this->context()->publish(CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme'));

        $this->assertFalse($this->context()->hadConflict());
        $this->assertSame('acme.widget', $this->context()->current()?->type);
    }

    public function test_conflicting_publish_is_rejected_not_overwritten(): void
    {
        $locked = CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme');
        $this->context()->publish($locked);

        // A different resource NEVER overwrites the locked one — it is rejected
        // and flagged, so a nested render can never redefine the current resource.
        $this->context()->publish(CurrentResourceReference::of('acme.gizmo', new \stdClass, 2, 'acme'));

        $this->assertSame($locked, $this->context()->current());
        $this->assertSame('acme.widget', $this->context()->current()?->type);
        $this->assertTrue($this->context()->hadConflict());
    }

    public function test_clear_resets_the_lock(): void
    {
        $this->context()->publish(CurrentResourceReference::home());
        $this->assertTrue($this->context()->has());

        $this->context()->clear();
        $this->assertFalse($this->context()->has());
        $this->assertFalse($this->context()->hadConflict());
    }

    public function test_context_is_request_scoped_not_a_singleton(): void
    {
        // scoped() returns the same instance within a request; a clear() is
        // observable through a re-resolve within the request.
        $this->context()->publish(CurrentResourceReference::home());
        $this->assertTrue(app('cms.localization.current_resource')->has());
        app('cms.localization.current_resource')->clear();
        $this->assertFalse($this->context()->has());
    }

    // -- factory builds one LocalizationContext ---------------------------------

    public function test_factory_maps_empty_context_to_default(): void
    {
        $this->assertSame('default', $this->factory()->current()->type);
    }

    public function test_factory_maps_home_reference(): void
    {
        $this->context()->publish(CurrentResourceReference::home());
        $this->assertTrue($this->factory()->current()->isHome());
    }

    public function test_factory_maps_plugin_reference(): void
    {
        $ref = CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme');
        $this->context()->publish($ref);

        $ctx = $this->factory()->current();
        $this->assertSame('acme.widget', $ctx->type);
        $this->assertNull($ctx->content);
        $this->assertNull($ctx->term);
        $this->assertSame($ref, $ctx->reference);
        $this->assertInstanceOf(\stdClass::class, $ctx->resource());
    }

    // -- central acceptance: plugin resource switch WITHOUT SeoManager ----------

    public function test_language_switch_resolves_a_plugin_resource_without_any_seo_call(): void
    {
        app('cms.localization.resolvers')->register($this->fakeResolver());

        // NO seo()->forX() call anywhere — a generic controller/plugin published the context.
        $this->context()->publish(CurrentResourceReference::of('acme.widget', new \stdClass, 1, 'acme'));

        $entries = $this->switcherByCode();

        // The fake resolver drives the target — NOT the localized-home fallback.
        $this->assertSame('/acme/gadget', $entries['en']['url']);
        $this->assertSame('/vi/acme/gadget', $entries['vi']['url']);
        $this->assertNotSame('/vi', $entries['vi']['url']);
    }

    public function test_without_a_current_resource_the_switcher_falls_back_to_home(): void
    {
        // No context set → home fallback (unchanged legacy behaviour).
        $entries = $this->switcherByCode();
        $this->assertSame('/', $entries['en']['url']);
        $this->assertSame('/vi', $entries['vi']['url']);
    }

    // -- Core byte-identity: the render boundary publishes the resource ---------

    public function test_core_page_switch_is_byte_identical_via_the_context(): void
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);
        app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'trang-chu',
        ]);

        // The RENDER BOUNDARY publishes the resource (as FrontendController does) —
        // NOT SeoManager.
        $this->publisher()->publishContent($page->fresh(['translations']));

        $entries = $this->switcherByCode();
        $this->assertSame('/home', $entries['en']['url']);
        $this->assertSame('/vi/trang-chu', $entries['vi']['url']);

        $this->assertTrue($this->context()->has());
        $this->assertSame('page', $this->context()->current()?->type);
    }

    public function test_seo_call_alone_does_not_publish_the_resource(): void
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        // SEO is a pure consumer now — calling it must NOT populate the authority.
        seo()->forContent($page->fresh(['translations']), 'en');

        $this->assertFalse($this->context()->has());
    }

    private function fakeResolver(): LocalizedResourceResolverContract
    {
        return new class implements LocalizedResourceResolverContract
        {
            public function key(): string
            {
                return 'acme.widget';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(LocalizationContext $context): bool
            {
                return $context->type === 'acme.widget';
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                return new RouteDescriptor(
                    resolverKey: 'acme.widget',
                    canonicalType: 'acme.widget',
                    canonicalId: 1,
                    canonicalPath: '/acme/gadget',
                );
            }
        };
    }
}
