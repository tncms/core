<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Scripts\ScriptAsset;

/**
 * Global Script Settings render bridge (v1.0.0-beta.7.1.13.2).
 *
 * Reads the admin-managed `scripts.*` settings (stored in cms_settings by the
 * ScriptSettingsPage) and registers them into the ScriptManager at render time.
 * It NEVER stores or executes PHP/callbacks — every value passes through the
 * ScriptManager's own validation (unsafe inline, untrusted URLs, invalid JSON,
 * dangerous iframes are rejected there and simply never render).
 *
 * Idempotent per request: the ScriptManager de-duplicates by key, so applying
 * twice (head + footer render) produces no duplicate output.
 *
 * `scripts.enabled` gates ONLY these settings-managed scripts. Scripts a plugin
 * registers directly through the Script facade/ScriptManager API always render.
 */
class ScriptSettingsRegistrar
{
    /** Verification providers exposed in the admin UI. */
    public const VERIFICATION_PROVIDERS = ['google', 'bing', 'yandex', 'facebook', 'pinterest', 'baidu'];

    /** Passive source metadata tag for settings-managed scripts (v1.0.0-beta.7.1.13.3). */
    private const SOURCE = ['source_type' => 'settings', 'source_name' => 'settings'];

    public function __construct(private readonly SettingsManager $settings) {}

    /** Whether settings-managed scripts are enabled (default on). */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('scripts.enabled', true);
    }

    /**
     * Register the configured settings into the shared ScriptManager, honouring
     * the enabled gate. Called from the cms.scripts.rendering_head/footer hooks.
     */
    public function apply(?ScriptManager $manager = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->registerInto($manager ?? app('cms.scripts'));
    }

    /**
     * Register every configured entry into the given ScriptManager (no enabled
     * gate — used by both apply() and diagnostics()). Never throws: malformed
     * stored settings are skipped defensively, and each value is still validated
     * by the ScriptManager (invalid entries are rejected, not rendered).
     */
    public function registerInto(ScriptManager $manager): void
    {
        // Let plugins inject settings-managed scripts before the stored settings
        // are registered (v1.0.0-beta.7.1.13.3). Best-effort: a misbehaving
        // listener must never break rendering or diagnostics.
        try {
            if (function_exists('do_action')) {
                do_action('cms.scripts.settings.loading', $manager);
            }
        } catch (\Throwable) {
            // Injection is best-effort; continue registering stored settings.
        }

        // Verification meta — paste value only; strip any pasted markup.
        foreach ($this->assoc('scripts.verifications') as $provider => $value) {
            $value = trim(strip_tags((string) $value));

            if ($value !== '') {
                $manager->verification((string) $provider, $value, 10, self::SOURCE);
            }
        }

        // JSON-LD blocks.
        foreach ($this->rows('scripts.json_ld') as $row) {
            if (! $this->rowEnabled($row)) {
                continue;
            }

            $manager->jsonLd((string) ($row['key'] ?? ''), (string) ($row['json'] ?? ''), 10, self::SOURCE);
        }

        // Inline head / footer scripts.
        foreach ($this->rows('scripts.head_inline') as $row) {
            if ($this->rowEnabled($row)) {
                $manager->head((string) ($row['key'] ?? ''), (string) ($row['code'] ?? ''), $this->priority($row), self::SOURCE);
            }
        }

        foreach ($this->rows('scripts.footer_inline') as $row) {
            if ($this->rowEnabled($row)) {
                $manager->footer((string) ($row['key'] ?? ''), (string) ($row['code'] ?? ''), $this->priority($row), self::SOURCE);
            }
        }

        // External head / footer scripts.
        foreach ($this->rows('scripts.head_external') as $row) {
            if ($this->rowEnabled($row)) {
                $manager->externalHead((string) ($row['key'] ?? ''), (string) ($row['src'] ?? ''), [], $this->priority($row), self::SOURCE);
            }
        }

        foreach ($this->rows('scripts.footer_external') as $row) {
            if ($this->rowEnabled($row)) {
                $manager->externalFooter((string) ($row['key'] ?? ''), (string) ($row['src'] ?? ''), [], $this->priority($row), self::SOURCE);
            }
        }

        // Trusted iframe embeds (position stored per row).
        foreach ($this->rows('scripts.embeds') as $row) {
            if (! $this->rowEnabled($row)) {
                continue;
            }

            $position = ((string) ($row['position'] ?? ScriptAsset::POSITION_HEAD)) === ScriptAsset::POSITION_FOOTER
                ? ScriptAsset::POSITION_FOOTER
                : ScriptAsset::POSITION_HEAD;

            $manager->embed((string) ($row['key'] ?? ''), (string) ($row['html'] ?? ''), $position, $this->priority($row), self::SOURCE);
        }
    }

    /**
     * Validate the current settings against the ScriptManager without touching
     * the live registry: valid/invalid counts + safe skip reasons (key/type/
     * reason only — never script contents). Ignores the enabled gate so the
     * admin sees validation feedback even while scripts are disabled.
     *
     * The `snapshot` key carries the full passive {@see ScriptManager::diagnostics()}
     * of the probe (source/plugin/theme/settings breakdown + warnings) for the
     * admin Diagnostics panel (v1.0.0-beta.7.1.13.3).
     *
     * @return array{valid: int, invalid: int, skipped: list<array{key: string, type: string, reason: string}>, snapshot: array<string, mixed>}
     */
    public function diagnostics(): array
    {
        $probe = new ScriptManager;
        $this->registerInto($probe);

        return [
            'valid' => count($probe->all()),
            'invalid' => count($probe->rejected()),
            'skipped' => $probe->rejected(),
            'snapshot' => $probe->diagnostics(),
        ];
    }

    /**
     * Count-only health snapshot for /cms-health. Counts CONFIGURED + enabled
     * entries; never exposes any script value, URL, or key.
     *
     * @return array{
     *     script_settings_ready: bool,
     *     script_settings_enabled: bool,
     *     script_settings_verification_count: int,
     *     script_settings_json_ld_count: int,
     *     script_settings_inline_count: int,
     *     script_settings_external_count: int,
     *     script_settings_embed_count: int
     * }
     */
    public function healthSnapshot(): array
    {
        $verifications = array_filter(
            $this->assoc('scripts.verifications'),
            static fn ($v): bool => trim(strip_tags((string) $v)) !== '',
        );

        return [
            'script_settings_ready' => true,
            'script_settings_enabled' => $this->enabled(),
            'script_settings_verification_count' => count($verifications),
            'script_settings_json_ld_count' => $this->countEnabled('scripts.json_ld'),
            'script_settings_inline_count' => $this->countEnabled('scripts.head_inline') + $this->countEnabled('scripts.footer_inline'),
            'script_settings_external_count' => $this->countEnabled('scripts.head_external') + $this->countEnabled('scripts.footer_external'),
            'script_settings_embed_count' => $this->countEnabled('scripts.embeds'),
        ];
    }

    /**
     * Repeatable rows for a key, defensively normalised to a list of arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $key): array
    {
        $value = $this->settings->get($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Assoc map for a key (used by verifications), defensively normalised.
     *
     * @return array<string, mixed>
     */
    private function assoc(string $key): array
    {
        $value = $this->settings->get($key, []);

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $row */
    private function rowEnabled(array $row): bool
    {
        // Absent enabled flag defaults to on (a saved row is active by default).
        return (bool) ($row['enabled'] ?? true);
    }

    /** @param array<string, mixed> $row */
    private function priority(array $row): int
    {
        return (int) ($row['priority'] ?? 10);
    }

    private function countEnabled(string $key): int
    {
        return count(array_filter($this->rows($key), fn (array $row): bool => $this->rowEnabled($row)));
    }
}
