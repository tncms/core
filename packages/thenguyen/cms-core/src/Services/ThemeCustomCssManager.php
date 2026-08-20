<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Assets\Asset;

/**
 * Theme Custom CSS render bridge (v1.0.0-beta.7.1.13.4).
 *
 * A Core-level feature that lets an admin store Custom CSS as part of Theme
 * Options and have it rendered through the existing Asset Registry — never
 * echoed directly from Blade or the settings page.
 *
 * Storage travels with Theme Options: values persist under the canonical
 * `theme_options` namespace via {@see ThemeOptionManager} (keyed by the active
 * theme slug), so Custom CSS follows the theme through export/import, child
 * themes, and preset sync. The two option keys are:
 *   - custom_css_frontend  →  theme_options.{slug}.custom_css_frontend
 *   - custom_css_admin     →  theme_options.{slug}.custom_css_admin
 *
 * Rendering pipeline (one pipeline, no duplicate renderer):
 *   Theme Options → ThemeCustomCssManager::apply() → AssetRegistry::inlineStyle()
 *   → frontend/admin render. Frontend CSS is scoped to the frontend, admin CSS
 *   to the admin panel; the Asset Registry's scope filtering keeps them from
 *   leaking into each other.
 *
 * Every inline asset is tagged source_type=settings / source_name=theme-options
 * so it appears automatically in Asset Diagnostics.
 *
 * Security: this is CSS only. It never evaluates or executes anything. Values
 * are screened for style breakout, script/HTML injection, dangerous @import and
 * unsafe control characters, and are size-capped. Validation never throws;
 * invalid CSS is simply not stored and not rendered.
 */
class ThemeCustomCssManager
{
    /** Theme Option key for the public-site Custom CSS. */
    public const FRONTEND_KEY = 'custom_css_frontend';

    /** Theme Option key for the admin-panel Custom CSS. */
    public const ADMIN_KEY = 'custom_css_admin';

    /** Maximum accepted payload per field: 256 KB. */
    public const MAX_BYTES = 262144;

    /** Passive source metadata tag for settings-managed Custom CSS. */
    private const SOURCE = ['source_type' => 'settings', 'source_name' => 'theme-options'];

    /** Asset Registry inline handles (stable so re-apply de-duplicates). */
    private const HANDLE_FRONTEND = 'theme-custom-css-frontend';

    private const HANDLE_ADMIN = 'theme-custom-css-admin';

    /**
     * Case-insensitive substrings that reject a Custom CSS payload. Mirrors the
     * Asset Registry's own inline-style screen and extends it with the style
     * breakout (`</style`) and vbscript cases the settings surface must reject.
     */
    private const UNSAFE_PATTERNS = [
        '</style',
        '<script',
        'javascript:',
        'vbscript:',
        'expression(',
        'behavior:',
    ];

    public function __construct(private readonly ThemeOptionManager $options) {}

    /** Stored, un-validated frontend Custom CSS (empty string when unset). */
    public function frontendCss(?string $theme = null): string
    {
        return $this->read(self::FRONTEND_KEY, $theme);
    }

    /** Stored, un-validated admin Custom CSS (empty string when unset). */
    public function adminCss(?string $theme = null): string
    {
        return $this->read(self::ADMIN_KEY, $theme);
    }

    /**
     * Persist both fields after validation. A field that fails validation is
     * left unchanged (its previously stored value keeps rendering) and its
     * reasons are returned. Valid fields are always stored. Never throws.
     *
     * @return list<string> Prefixed validation reasons (empty = both saved).
     */
    public function save(string $frontend, string $admin, ?string $theme = null): array
    {
        $errors = [];

        foreach ([
            self::FRONTEND_KEY => [$frontend, 'Frontend CSS'],
            self::ADMIN_KEY => [$admin, 'Admin CSS'],
        ] as $key => [$css, $label]) {
            $reasons = $this->validate($css);

            if ($reasons !== []) {
                foreach ($reasons as $reason) {
                    $errors[] = $label.': '.$reason;
                }

                continue;
            }

            $this->options->set($key, $css, $theme);
        }

        return $errors;
    }

    /**
     * Validate a Custom CSS payload. Returns a list of short, safe reason
     * strings ([] means valid). Rejects style breakout, script/HTML injection,
     * dangerous schemes, unsafe @import, control characters, and oversize
     * payloads. Never throws.
     *
     * @return list<string>
     */
    public function validate(string $css): array
    {
        $reasons = [];

        if (strlen($css) > self::MAX_BYTES) {
            $reasons[] = 'exceeds the 256 KB size limit';
        }

        // NUL bytes and unsafe control characters (allow tab, LF, CR only).
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $css) === 1) {
            $reasons[] = 'contains unsafe control characters';
        }

        $lower = strtolower($css);

        foreach (self::UNSAFE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $reasons[] = 'contains a disallowed pattern ('.$pattern.')';
            }
        }

        // Reject dangerous @import: remote/protocol-relative URLs and the
        // javascript:/vbscript:/data: schemes (the scheme substrings above
        // already reject javascript:/vbscript: anywhere; this also blocks
        // @import data: which is legitimate only outside @import).
        if (str_contains($lower, '@import')
            && (str_contains($lower, 'http://')
                || str_contains($lower, 'https://')
                || str_contains($lower, '//')
                || str_contains($lower, 'data:'))) {
            $reasons[] = 'contains a disallowed @import';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Register the stored, valid Custom CSS into the Asset Registry as inline
     * styles (frontend-scoped + admin-scoped). Fires the optional
     * `cms.theme.custom_css.loading` action first so plugins may inject before
     * registration. Never throws — invalid or empty CSS is silently skipped, and
     * the Asset Registry independently re-screens every inline style.
     */
    public function apply(AssetRegistry $assets, ?string $theme = null): void
    {
        try {
            if (function_exists('do_action')) {
                do_action('cms.theme.custom_css.loading', $assets);
            }
        } catch (\Throwable) {
            // Injection is best-effort; continue registering stored CSS.
        }

        $frontend = $this->frontendCss($theme);

        if ($frontend !== '' && $this->validate($frontend) === []) {
            $assets->inlineStyle(
                self::HANDLE_FRONTEND,
                $frontend,
                null,
                null,
                Asset::SCOPE_FRONTEND,
                Asset::POSITION_HEAD,
                self::SOURCE,
            );
        }

        $admin = $this->adminCss($theme);

        if ($admin !== '' && $this->validate($admin) === []) {
            $assets->inlineStyle(
                self::HANDLE_ADMIN,
                $admin,
                null,
                null,
                Asset::SCOPE_ADMIN,
                Asset::POSITION_HEAD,
                self::SOURCE,
            );
        }
    }

    /**
     * Count-only health snapshot for /cms-health. Exposes byte sizes and an
     * enabled flag — never the CSS contents. Never throws.
     *
     * @return array{
     *     theme_custom_css_enabled: bool,
     *     theme_custom_css_frontend_size: int,
     *     theme_custom_css_admin_size: int
     * }
     */
    public function healthSnapshot(): array
    {
        $frontend = $this->frontendCss();
        $admin = $this->adminCss();

        return [
            'theme_custom_css_enabled' => $frontend !== '' || $admin !== '',
            'theme_custom_css_frontend_size' => strlen($frontend),
            'theme_custom_css_admin_size' => strlen($admin),
        ];
    }

    /** Read a Custom CSS option as a string, defaulting to empty. */
    private function read(string $key, ?string $theme): string
    {
        $value = $this->options->get($key, '', $theme);

        return is_string($value) ? $value : '';
    }
}
