<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Tests\TestCase;

/**
 * CORE-OPTIMIZE-1 — EN/VI translation parity (§31). The new Optimize strings
 * must exist in both language files, VI must be actually translated, and the
 * two files must remain key-for-key symmetric.
 */
class OptimizeTranslationParityTest extends TestCase
{
    /** @var list<string> */
    private const NEW_KEYS = [
        'Optimize',
        'Enable CMS Cache',
        'Clear CMS Cache',
        'CMS cache cleared',
        'Could not clear CMS cache',
        'Cache reusable TNCMS application data for performance. Disabling this bypasses CMS optimization caches but does not disable sessions or Laravel infrastructure caches.',
        'Invalidate cached TNCMS application data. This does not affect sessions, logins, or Laravel infrastructure caches.',
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

    public function test_language_files_remain_key_for_key_symmetric(): void
    {
        $en = array_keys($this->lang('en'));
        $vi = array_keys($this->lang('vi'));
        sort($en);
        sort($vi);

        $this->assertSame($en, $vi, 'EN and VI translation keys must stay in parity.');
    }
}
