<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Tests\TestCase;

/**
 * CORE-OPTIMIZE-3 §17/§18.20 — every new Frontend Optimization string exists in
 * both EN and VI, and VI is actually translated (not an English copy). The global
 * key-for-key symmetry is enforced by {@see OptimizeTranslationParityTest}.
 */
class RuntimeOptimizationTranslationParityTest extends TestCase
{
    /** @var list<string> */
    private const NEW_KEYS = [
        'Frontend Optimization',
        'Optimize public response headers',
        'Add safe browser cache headers to anonymous public pages. Logged-in, admin, installer and update responses are never made publicly cacheable.',
        'Public page browser cache (seconds)',
        'How long browsers may reuse an anonymous public page before revalidating. 0 means always revalidate. Applies only when public response optimization is on.',
        'Response optimization only sets browser (private) cache headers for anonymous pages; it never enables shared/CDN caching, never changes sessions or logins, and never caches admin, installer, or update pages. Long-term caching of fingerprinted assets is handled by your web server or CDN.',
        'Response optimization',
        'Public page browser cache',
        'Fingerprinted assets',
        'Detected',
        'Not detected',
        'Media hints',
        'theme-managed',
        'The active cache store is not persistent across requests, which limits CMS caching effectiveness.',
        'These figures describe Core response and asset optimization only. They never affect sessions, logins, updates, installer state, or plugin caches.',
    ];

    /** @return array<string, string> */
    private function lang(string $locale): array
    {
        return json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
    }

    public function test_new_keys_exist_in_both_locales(): void
    {
        $en = $this->lang('en');
        $vi = $this->lang('vi');

        foreach (self::NEW_KEYS as $key) {
            $this->assertArrayHasKey($key, $en, "Missing EN key: {$key}");
            $this->assertArrayHasKey($key, $vi, "Missing VI key: {$key}");
        }
    }

    public function test_vietnamese_values_are_translated(): void
    {
        $vi = $this->lang('vi');

        foreach (self::NEW_KEYS as $key) {
            $this->assertNotSame('', trim((string) $vi[$key]), "Empty VI translation: {$key}");
            $this->assertNotSame($key, $vi[$key], "VI translation not localized: {$key}");
        }
    }
}
