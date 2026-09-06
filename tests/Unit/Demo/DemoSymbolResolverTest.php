<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Services\DemoSymbolResolver;

/**
 * EG-9 Phase 1A — the ONE coherent symbolic reference resolver used by every
 * native demo importer. Symbolic keys are the demo's import identity; they are
 * never database ids. The resolver distinguishes namespace, symbolic key,
 * resolved local identity, an unresolved reference, a duplicate symbolic
 * declaration, and a wrong/malformed namespace — so importer behaviour is
 * never a boolean "exists / does not exist".
 */
final class DemoSymbolResolverTest extends TestCase
{
    public function test_parse_returns_namespace_and_key_for_a_valid_reference(): void
    {
        $this->assertSame(['media', 'blog-hero'], DemoSymbolResolver::parse('media:blog-hero'));
        $this->assertSame(['category', 'news'], DemoSymbolResolver::parse('category:news'));
        $this->assertSame(['post', 'hello_tncms'], DemoSymbolResolver::parse('post:hello_tncms'));
    }

    public function test_parse_rejects_malformed_and_empty_parts(): void
    {
        $this->assertNull(DemoSymbolResolver::parse('nocolonhere'));
        $this->assertNull(DemoSymbolResolver::parse('media:'));
        $this->assertNull(DemoSymbolResolver::parse(':news'));
        $this->assertNull(DemoSymbolResolver::parse('media:bad key')); // whitespace
        $this->assertNull(DemoSymbolResolver::parse('media:../escape'));
    }

    public function test_parse_splits_on_the_first_colon_only(): void
    {
        // A key never legitimately contains a colon (slug charset), so a second
        // colon makes the key invalid rather than silently keeping it.
        $this->assertNull(DemoSymbolResolver::parse('media:a:b'));
    }

    public function test_register_maps_namespace_key_to_local_id(): void
    {
        $r = new DemoSymbolResolver;

        $this->assertTrue($r->register('category', 'news', 10));
        $this->assertTrue($r->has('category', 'news'));
        $this->assertSame(10, $r->idFor('category', 'news'));
    }

    public function test_register_reports_a_duplicate_symbolic_declaration(): void
    {
        $r = new DemoSymbolResolver;

        $this->assertTrue($r->register('tag', 'tncms', 5));
        // Same (namespace,key) declared twice within one run → duplicate.
        $this->assertFalse($r->register('tag', 'tncms', 6));
        // The first registration wins; the duplicate never overwrites it.
        $this->assertSame(5, $r->idFor('tag', 'tncms'));
    }

    public function test_resolve_classifies_resolved_unresolved_invalid_and_malformed(): void
    {
        $r = new DemoSymbolResolver;
        $r->register('media', 'blog-hero', 42);

        $resolved = $r->resolve('media:blog-hero');
        $this->assertSame(DemoSymbolResolver::STATUS_RESOLVED, $resolved['status']);
        $this->assertSame(42, $resolved['id']);
        $this->assertSame('media', $resolved['namespace']);
        $this->assertSame('blog-hero', $resolved['key']);

        $unresolved = $r->resolve('category:missing');
        $this->assertSame(DemoSymbolResolver::STATUS_UNRESOLVED, $unresolved['status']);
        $this->assertNull($unresolved['id']);

        $wrongNs = $r->resolve('widget:thing');
        $this->assertSame(DemoSymbolResolver::STATUS_INVALID_NAMESPACE, $wrongNs['status']);

        $malformed = $r->resolve('not-a-ref');
        $this->assertSame(DemoSymbolResolver::STATUS_MALFORMED, $malformed['status']);
    }

    public function test_from_imported_keys_ingests_namespaced_and_legacy_media_keys(): void
    {
        // The persisted provenance map mixes namespaced keys (page:/category:/…)
        // with the legacy bare media keys (media importer stored plain keys). The
        // resolver normalizes both so cross-object refs resolve on re-import.
        $r = DemoSymbolResolver::fromImportedKeys([
            'blog-hero' => 7,          // legacy bare media key
            'category:news' => 10,
            'tag:tncms' => 5,
            'post:hello' => 20,
            'page:landing' => 30,
        ]);

        $this->assertSame(7, $r->idFor('media', 'blog-hero'));
        $this->assertSame(10, $r->idFor('category', 'news'));
        $this->assertSame(5, $r->idFor('tag', 'tncms'));
        $this->assertSame(20, $r->idFor('post', 'hello'));
        $this->assertSame(30, $r->idFor('page', 'landing'));
    }

    public function test_namespaces_are_the_documented_set(): void
    {
        $this->assertSame(
            ['media', 'category', 'tag', 'page', 'post'],
            DemoSymbolResolver::NAMESPACES,
        );
    }
}
