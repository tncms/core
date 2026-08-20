<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Shortcodes (v1.0.0-beta.7.1.11) — WordPress-inspired `[tag attr="v"]…[/tag]`
 * tokens in post/page content that expand to rendered output at render time.
 *
 * Security model (see also the CMS security docs):
 *  - Shortcodes can ONLY invoke callbacks registered in PHP. Unknown tags are
 *    left untouched and execute nothing.
 *  - No eval, no PHP-from-database, no dynamic class instantiation from content.
 *    Attribute parsing is pure string work and cannot execute code.
 *  - A throwing shortcode callback is caught and reported; the original token is
 *    preserved so the surrounding content always survives.
 *  - Callback output is extension-trusted but still passes through the
 *    `cms.shortcode.output` filter, and (for post/page bodies) the existing
 *    write-time/theme HTML sanitizer.
 *
 * Nested shortcodes are supported for ENCLOSING tags: the inner content of
 * `[outer]…[/outer]` is itself rendered (one level deeper) before the callback
 * runs, bounded by {@see self::MAX_DEPTH} so malformed nesting can never recurse
 * infinitely.
 */
class ShortcodeManager
{
    /** Hard recursion bound for nested enclosing shortcodes. */
    private const MAX_DEPTH = 5;

    /** @var array<string, callable|string> */
    private array $shortcodes = [];

    public function __construct(private readonly HookManager $hooks) {}

    public function register(string $tag, callable|string $callback): void
    {
        $this->shortcodes[$this->normalizeTag($tag)] = $callback;
    }

    public function has(string $tag): bool
    {
        return isset($this->shortcodes[$this->normalizeTag($tag)]);
    }

    public function remove(string $tag): void
    {
        unset($this->shortcodes[$this->normalizeTag($tag)]);
    }

    /**
     * Registered tags mapped to nothing useful for discovery — returns the list
     * of tag names. Never exposes the callbacks themselves.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_keys($this->shortcodes);
    }

    /**
     * Expand all registered shortcodes in $content. Unknown tags are left
     * unchanged. Null/empty content yields ''.
     *
     * @param  array<string, mixed>  $context
     */
    public function render(?string $content, array $context = []): string
    {
        if ($content === null || $content === '') {
            return (string) $content;
        }

        if ($this->shortcodes === [] || ! str_contains($content, '[')) {
            return $content;
        }

        return $this->renderDepth($content, $context, 0);
    }

    /**
     * Remove all shortcode tokens from $content (registered or not), leaving the
     * inner content of enclosing tags in place.
     */
    public function strip(?string $content): string
    {
        if ($content === null || $content === '' || ! str_contains($content, '[')) {
            return (string) $content;
        }

        return (string) preg_replace_callback(
            $this->pattern(),
            static fn (array $m): string => $m[3] ?? '',
            $content,
        );
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderDepth(string $content, array $context, int $depth): string
    {
        return (string) preg_replace_callback(
            $this->pattern(),
            function (array $m) use ($context, $depth): string {
                $tag = $this->normalizeTag($m[1]);

                // Unknown shortcode: leave the original token untouched.
                if (! isset($this->shortcodes[$tag])) {
                    return $m[0];
                }

                $attrs = $this->parseAttributes($m[2] ?? '');
                $inner = $m[3] ?? null;

                // Nested support: render the inner content one level deeper first.
                if (is_string($inner) && $inner !== '' && $depth < self::MAX_DEPTH && str_contains($inner, '[')) {
                    $inner = $this->renderDepth($inner, $context, $depth + 1);
                }

                try {
                    $output = (string) ($this->shortcodes[$tag])($attrs, $inner, $context, $tag);
                } catch (\Throwable $e) {
                    $this->reportShortcodeFailure($tag, $e);

                    // Content survives: keep the original token rather than dropping it.
                    return $m[0];
                }

                // The output filter receives the inner content, the raw context
                // array, and a HookContext as the final argument. Existing
                // filters with fewer acceptedArgs are unaffected (HookManager
                // slices arguments to each callback's accepted count).
                $hookContext = ($context['_hook_context'] ?? null) instanceof HookContext
                    ? $context['_hook_context']
                    : HookContext::make($context);

                /** @var string $filtered */
                $filtered = $this->hooks->applyFilters('cms.shortcode.output', $output, $tag, $attrs, $inner, $context, $hookContext);

                return $filtered;
            },
            $content,
        );
    }

    /**
     * Single regex matching `[tag]`, `[tag attrs]`, `[tag/]`, and the enclosing
     * `[tag attrs]inner[/tag]` (inner captured in group 3). Derived from the
     * WordPress shortcode grammar, simplified for our tag/attr set.
     */
    private function pattern(): string
    {
        return '/\[([a-zA-Z0-9_\-]+)((?:[^\]]*?))(?:\/\]|\](?:(.*?)\[\/\1\])?)/s';
    }

    /**
     * Parse a raw attribute string into an associative array. Supports
     * double-quoted, single-quoted and unquoted values; a bare word becomes a
     * boolean `true` attribute. Pure string parsing — never executes anything.
     *
     * @return array<string, string|bool>
     */
    private function parseAttributes(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $pattern = '/([\w\-]+)\s*=\s*"([^"]*)"'        // key="value"
            .'|([\w\-]+)\s*=\s*\'([^\']*)\''            // key='value'
            .'|([\w\-]+)\s*=\s*([^\s\'"\]]+)'           // key=value
            .'|(\S+)/';                                  // boolean word

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $attrs = [];

        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $attrs[strtolower($m[1])] = $m[2];
            } elseif (($m[3] ?? '') !== '') {
                $attrs[strtolower($m[3])] = $m[4];
            } elseif (($m[5] ?? '') !== '') {
                $attrs[strtolower($m[5])] = $m[6];
            } elseif (($m[7] ?? '') !== '') {
                $attrs[strtolower($m[7])] = true;
            }
        }

        return $attrs;
    }

    private function normalizeTag(string $tag): string
    {
        return strtolower(trim($tag));
    }

    private function reportShortcodeFailure(string $tag, \Throwable $e): void
    {
        $context = ['cms_shortcode' => $tag];

        if (config('app.debug')) {
            $context['exception'] = (string) $e;
        }

        try {
            \Illuminate\Support\Facades\Log::warning(
                "TN CMS shortcode [{$tag}] callback failed: {$e->getMessage()}",
                $context,
            );
        } catch (\Throwable) {
            // Logging must never break rendering.
        }
    }
}
