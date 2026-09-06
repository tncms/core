<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\Support\DemoFingerprint;

/**
 * EG-9 Phase 1A — deterministic source fingerprint over normalized DECLARATIVE
 * data. It distinguishes "owned unchanged" from "owned source changed" without
 * ever depending on database ids or timestamps: the same declarative object
 * fingerprints identically across retries (regardless of key order), a changed
 * source fingerprints differently.
 */
final class DemoFingerprintTest extends TestCase
{
    public function test_same_object_is_stable_regardless_of_key_order(): void
    {
        $a = ['key' => 'news', 'translations' => ['en' => ['name' => 'News'], 'vi' => ['name' => 'Tin']]];
        $b = ['translations' => ['vi' => ['name' => 'Tin'], 'en' => ['name' => 'News']], 'key' => 'news'];

        $this->assertSame(DemoFingerprint::of($a), DemoFingerprint::of($b));
    }

    public function test_changed_source_changes_the_fingerprint(): void
    {
        $a = ['key' => 'news', 'translations' => ['en' => ['name' => 'News']]];
        $b = ['key' => 'news', 'translations' => ['en' => ['name' => 'Updated']]];

        $this->assertNotSame(DemoFingerprint::of($a), DemoFingerprint::of($b));
    }

    public function test_list_order_is_significant_but_stable(): void
    {
        $a = ['tags' => ['tag:a', 'tag:b']];
        $b = ['tags' => ['tag:b', 'tag:a']];

        // List order carries meaning (e.g. sort order), so it is preserved.
        $this->assertNotSame(DemoFingerprint::of($a), DemoFingerprint::of($b));
        // …but re-fingerprinting the SAME list is stable.
        $this->assertSame(DemoFingerprint::of($a), DemoFingerprint::of($a));
    }

    public function test_fingerprint_is_namespaced_hex_and_does_not_leak_values(): void
    {
        $fp = DemoFingerprint::of(['secret' => 'super-secret-value']);

        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $fp);
        $this->assertStringNotContainsString('super-secret-value', $fp);
    }
}
