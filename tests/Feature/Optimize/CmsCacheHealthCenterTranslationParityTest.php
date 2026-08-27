<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Tests\TestCase;

/**
 * CORE-OPTIMIZE-2 §36 — EN/VI parity for the Health Center strings. Every new
 * user-facing string must exist in both locales, be genuinely translated in VI,
 * and the files must stay key-for-key symmetric.
 */
class CmsCacheHealthCenterTranslationParityTest extends TestCase
{
    /** @var list<string> */
    private const NEW_KEYS = [
        'CMS Cache',
        'Status',
        'Healthy',
        'Disabled',
        'Degraded',
        'Unavailable',
        'Bypassed',
        'Active',
        'Effective state',
        'Cache Driver',
        'Cache Lifetime',
        'seconds',
        'Cache Version',
        'Managed Cache',
        'Public content',
        'These figures describe the TNCMS-managed public content cache only. Disabling or clearing it never affects sessions, logins, updates, installer state, or plugin caches.',
        'Refresh Diagnostics',
        'Diagnostics refreshed',
        'Rebuild CMS Cache',
        'Warms supported TNCMS public content cache entries from canonical CMS content. It does not affect search, plugins, sessions, or CDN caches.',
        'CMS cache rebuilt',
        ':count entries warmed.',
        'CMS cache rebuild incomplete',
        ':ok warmed, :failed failed.',
    ];

    /** @return array<string, string> */
    private function lang(string $locale): array
    {
        return json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
    }

    public function test_new_keys_exist_and_are_translated_in_both_locales(): void
    {
        $en = $this->lang('en');
        $vi = $this->lang('vi');

        foreach (self::NEW_KEYS as $key) {
            $this->assertArrayHasKey($key, $en, "Missing EN key: {$key}");
            $this->assertArrayHasKey($key, $vi, "Missing VI key: {$key}");
            $this->assertNotSame('', trim((string) $vi[$key]), "Empty VI translation: {$key}");
            $this->assertNotSame($key, $vi[$key], "VI translation not localized: {$key}");
        }
    }

    public function test_language_files_remain_key_for_key_symmetric(): void
    {
        $en = array_keys($this->lang('en'));
        $vi = array_keys($this->lang('vi'));
        sort($en);
        sort($vi);

        $this->assertSame($en, $vi, 'EN and VI translation keys must stay in parity.');
    }
}
