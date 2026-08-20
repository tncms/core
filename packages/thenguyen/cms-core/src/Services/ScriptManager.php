<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Scripts\ScriptAsset;
use TheNguyen\CMS\Support\Scripts\ScriptRenderResult;

/**
 * Global Script Manager (v1.0.0-beta.7.1.13).
 *
 * A Core-level registry + renderer for frontend head/footer scripts,
 * verification meta tags, JSON-LD, and trusted iframe embeds. Themes never
 * hand-manage custom scripts: the default layout only calls
 * {@see render_head_assets()} and {@see render_footer_assets()}.
 *
 * Security model:
 *  - Inline script content is scanned for obviously unsafe patterns.
 *  - External script URLs must be same-origin relative or on a trusted host.
 *  - Verification meta accepts a value only; provider decides the tag name.
 *  - JSON-LD is validated and re-encoded with HTML-safe flags.
 *  - Embeds are iframe-only, host-allowlisted, and attribute-sanitized.
 *
 * Invalid registrations are rejected and recorded (never rendered), and
 * rendering itself is defensive: it never throws on the frontend.
 *
 * NOT an asset bundler or dependency manager — no ordering by dependency,
 * no PHP execution, no callback serialization.
 */
class ScriptManager
{
    /** @var array<string, ScriptAsset> Keyed by "{type}:{key}"; last write wins. */
    private array $assets = [];

    /**
     * Source metadata per registration (v1.0.0-beta.7.1.13.3), keyed by the same
     * "{type}:{key}" map key as $assets. Passive diagnostics only — never affects
     * validation or rendering.
     *
     * @var array<string, array{type: string, name: string}>
     */
    private array $sources = [];

    /** @var list<string> Passive diagnostic warnings (short, safe strings only). */
    private array $warnings = [];

    /** @var list<string> Keys that were registered more than once (last wins). */
    private array $duplicates = [];

    /** @var list<array{key: string, type: string, reason: string}> */
    private array $rejected = [];

    /** @var list<array{key: string, type: string, reason: string}> Last render's skipped assets. */
    private array $renderSkipped = [];

    private int $sequence = 0;

    /** Allowed source_type values; anything else falls back to SOURCE_DEFAULT. */
    private const SOURCE_TYPES = ['core', 'settings', 'theme', 'plugin', 'custom'];

    private const SOURCE_DEFAULT = 'custom';

    /** Hosts allowed to serve external <script src="..."> (exact host match). */
    private const TRUSTED_SCRIPT_HOSTS = [
        'google.com',
        'www.google.com',
        'googletagmanager.com',
        'www.googletagmanager.com',
        'google-analytics.com',
        'www.google-analytics.com',
        'connect.facebook.net',
        'facebook.com',
        'www.facebook.com',
        'clarity.ms',
        'www.clarity.ms',
        'static.cloudflareinsights.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'unpkg.com',
    ];

    /** Hosts allowed as an <iframe> src (exact host match). */
    private const TRUSTED_EMBED_HOSTS = [
        'google.com',
        'www.google.com',
        'maps.google.com',
        'www.googletagmanager.com',
        'facebook.com',
        'www.facebook.com',
        'youtube.com',
        'www.youtube.com',
        'youtu.be',
        'player.vimeo.com',
        'giscus.app',
    ];

    /** Provider => rendered <meta name="..."> attribute. */
    private const VERIFICATION_PROVIDERS = [
        'google' => 'google-site-verification',
        'bing' => 'msvalidate.01',
        'yandex' => 'yandex-verification',
        'facebook' => 'facebook-domain-verification',
        'pinterest' => 'p:domain_verify',
        'baidu' => 'baidu-site-verification',
    ];

    /** Case-insensitive substrings that reject inline script content. */
    private const UNSAFE_SCRIPT_PATTERNS = [
        'eval(',
        'document.write',
        'javascript:',
        'vbscript:',
        'data:text/html',
        'blob:',
        'file:',
        // Prevents a "</script>" breakout that would escape the inline block.
        '</script',
    ];

    /** Attributes kept when sanitizing an <iframe> embed. */
    private const ALLOWED_IFRAME_ATTRIBUTES = [
        'src',
        'width',
        'height',
        'title',
        'loading',
        'allow',
        'allowfullscreen',
        'referrerpolicy',
        'class',
    ];

    // ---------------------------------------------------------------------
    // Registration API
    // ---------------------------------------------------------------------

    /**
     * Register an inline <script> for the document head.
     *
     * @param  array{source_type?: string, source_name?: string}  $source  Optional
     *   passive source metadata (v1.0.0-beta.7.1.13.3). Never affects rendering.
     */
    public function head(string $key, string $content, int $priority = 10, array $source = []): bool
    {
        return $this->registerInline(ScriptAsset::TYPE_HEAD_INLINE, ScriptAsset::POSITION_HEAD, $key, $content, $priority, $source);
    }

    /**
     * Register an inline <script> before </body>.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function footer(string $key, string $content, int $priority = 10, array $source = []): bool
    {
        return $this->registerInline(ScriptAsset::TYPE_FOOTER_INLINE, ScriptAsset::POSITION_FOOTER, $key, $content, $priority, $source);
    }

    /**
     * Register an external <script src> for the head.
     *
     * @param  array<string, scalar|bool>  $attributes  Optional async/defer/etc.
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function externalHead(string $key, string $url, array $attributes = [], int $priority = 10, array $source = []): bool
    {
        return $this->registerExternal(ScriptAsset::TYPE_HEAD_EXTERNAL, ScriptAsset::POSITION_HEAD, $key, $url, $attributes, $priority, $source);
    }

    /**
     * Register an external <script src> before </body>.
     *
     * @param  array<string, scalar|bool>  $attributes
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function externalFooter(string $key, string $url, array $attributes = [], int $priority = 10, array $source = []): bool
    {
        return $this->registerExternal(ScriptAsset::TYPE_FOOTER_EXTERNAL, ScriptAsset::POSITION_FOOTER, $key, $url, $attributes, $priority, $source);
    }

    /**
     * Register a generic <meta name="{name}" content="{content}">.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function meta(string $name, string $content, int $priority = 10, array $source = []): bool
    {
        $name = trim($name);

        if ($name === '') {
            return $this->reject($name, ScriptAsset::TYPE_META, 'empty meta name');
        }

        return $this->store(new ScriptAsset(
            $name,
            ScriptAsset::TYPE_META,
            ScriptAsset::POSITION_HEAD,
            ['name' => $name, 'content' => $content],
            $priority,
            $this->nextSequence(),
        ), $source);
    }

    /**
     * Register a typed site-verification meta tag by provider.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function verification(string $provider, string $value, int $priority = 10, array $source = []): bool
    {
        $provider = strtolower(trim($provider));
        $value = trim($value);

        if (! isset(self::VERIFICATION_PROVIDERS[$provider])) {
            return $this->reject($provider, ScriptAsset::TYPE_VERIFICATION, 'unknown verification provider');
        }

        if ($value === '') {
            return $this->reject($provider, ScriptAsset::TYPE_VERIFICATION, 'empty verification value');
        }

        return $this->store(new ScriptAsset(
            $provider,
            ScriptAsset::TYPE_VERIFICATION,
            ScriptAsset::POSITION_HEAD,
            ['provider' => $provider, 'value' => $value],
            $priority,
            $this->nextSequence(),
        ), $source);
    }

    /**
     * Register a JSON-LD block. Accepts an array or a JSON string; invalid JSON
     * is rejected and never rendered.
     *
     * @param  array<mixed>|string  $data
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function jsonLd(string $key, array|string $data, int $priority = 10, array $source = []): bool
    {
        if (trim($key) === '') {
            return $this->reject($key, ScriptAsset::TYPE_JSON_LD, 'empty asset key');
        }

        $normalized = $this->normalizeJsonLd($data);

        if ($normalized === null) {
            return $this->reject($key, ScriptAsset::TYPE_JSON_LD, 'invalid JSON-LD payload');
        }

        return $this->store(new ScriptAsset(
            $key,
            ScriptAsset::TYPE_JSON_LD,
            ScriptAsset::POSITION_HEAD,
            ['json' => $normalized],
            $priority,
            $this->nextSequence(),
        ), $source);
    }

    /**
     * Register a trusted iframe embed. Position may be head or footer.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function embed(string $key, string $html, string $position = ScriptAsset::POSITION_HEAD, int $priority = 10, array $source = []): bool
    {
        if (trim($key) === '') {
            return $this->reject($key, ScriptAsset::TYPE_EMBED, 'empty asset key');
        }

        if ($position !== ScriptAsset::POSITION_HEAD && $position !== ScriptAsset::POSITION_FOOTER) {
            return $this->reject($key, ScriptAsset::TYPE_EMBED, 'invalid embed position');
        }

        $sanitized = $this->sanitizeEmbed($html);

        if ($sanitized === null) {
            return $this->reject($key, ScriptAsset::TYPE_EMBED, 'rejected embed markup');
        }

        return $this->store(new ScriptAsset(
            $key,
            ScriptAsset::TYPE_EMBED,
            $position,
            ['html' => $sanitized],
            $priority,
            $this->nextSequence(),
        ), $source);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /** Render every head asset as a single HTML string. Never throws. */
    public function renderHead(): string
    {
        $this->fireAction('cms.scripts.rendering_head');

        $result = $this->buildLocation(ScriptAsset::POSITION_HEAD, [
            ScriptAsset::TYPE_META,
            ScriptAsset::TYPE_VERIFICATION,
            ScriptAsset::TYPE_HEAD_EXTERNAL,
            ScriptAsset::TYPE_HEAD_INLINE,
            ScriptAsset::TYPE_JSON_LD,
            ScriptAsset::TYPE_EMBED,
        ]);

        $this->renderSkipped = $result->skipped;

        return $this->applyHtmlFilter('cms.scripts.head_html', $result->html);
    }

    /** Render every footer asset as a single HTML string. Never throws. */
    public function renderFooter(): string
    {
        $this->fireAction('cms.scripts.rendering_footer');

        $result = $this->buildLocation(ScriptAsset::POSITION_FOOTER, [
            ScriptAsset::TYPE_FOOTER_EXTERNAL,
            ScriptAsset::TYPE_FOOTER_INLINE,
            ScriptAsset::TYPE_EMBED,
        ]);

        $this->renderSkipped = $result->skipped;

        return $this->applyHtmlFilter('cms.scripts.footer_html', $result->html);
    }

    /**
     * Build one document location by rendering assets grouped by the given type
     * order, then by (priority asc, sequence asc). Skips anything that throws.
     *
     * @param  list<string>  $typeOrder
     */
    private function buildLocation(string $position, array $typeOrder): ScriptRenderResult
    {
        $skipped = [];
        $chunks = [];

        foreach ($typeOrder as $type) {
            foreach ($this->sortedAssets($position, $type) as $asset) {
                try {
                    $html = $this->renderAsset($asset);
                } catch (\Throwable) {
                    $html = null;
                }

                if ($html === null || $html === '') {
                    $skipped[] = ['key' => $asset->key, 'type' => $asset->type, 'reason' => 'render skipped'];

                    continue;
                }

                $chunks[] = $html;
            }
        }

        return new ScriptRenderResult(
            $chunks === [] ? '' : implode("\n", $chunks)."\n",
            $skipped,
        );
    }

    /**
     * Assets for one position + type, ordered by priority then registration.
     *
     * @return list<ScriptAsset>
     */
    private function sortedAssets(string $position, string $type): array
    {
        $matching = array_values(array_filter(
            $this->assets,
            static fn (ScriptAsset $a): bool => $a->position === $position && $a->type === $type,
        ));

        usort($matching, static function (ScriptAsset $a, ScriptAsset $b): int {
            return [$a->priority, $a->sequence] <=> [$b->priority, $b->sequence];
        });

        return $matching;
    }

    private function renderAsset(ScriptAsset $asset): ?string
    {
        return match ($asset->type) {
            ScriptAsset::TYPE_META => $this->renderMeta($asset),
            ScriptAsset::TYPE_VERIFICATION => $this->renderVerification($asset),
            ScriptAsset::TYPE_HEAD_EXTERNAL, ScriptAsset::TYPE_FOOTER_EXTERNAL => $this->renderExternal($asset),
            ScriptAsset::TYPE_HEAD_INLINE, ScriptAsset::TYPE_FOOTER_INLINE => $this->renderInline($asset),
            ScriptAsset::TYPE_JSON_LD => $this->renderJsonLd($asset),
            ScriptAsset::TYPE_EMBED => $this->renderEmbed($asset),
            default => null,
        };
    }

    private function renderMeta(ScriptAsset $asset): string
    {
        return '<meta name="'.$this->esc($asset->data['name']).'" content="'.$this->esc($asset->data['content']).'">';
    }

    private function renderVerification(ScriptAsset $asset): ?string
    {
        $name = self::VERIFICATION_PROVIDERS[$asset->data['provider']] ?? null;

        if ($name === null) {
            return null;
        }

        return '<meta name="'.$this->esc($name).'" content="'.$this->esc($asset->data['value']).'">';
    }

    private function renderExternal(ScriptAsset $asset): string
    {
        $attrs = '';

        foreach ((array) ($asset->data['attributes'] ?? []) as $name => $value) {
            $attrs .= $this->renderAttribute((string) $name, $value);
        }

        return '<script src="'.$this->esc($asset->data['url']).'"'.$attrs.'></script>';
    }

    private function renderInline(ScriptAsset $asset): string
    {
        return '<script>'.$asset->data['content'].'</script>';
    }

    private function renderJsonLd(ScriptAsset $asset): string
    {
        return '<script type="application/ld+json">'.$asset->data['json'].'</script>';
    }

    private function renderEmbed(ScriptAsset $asset): string
    {
        return (string) $asset->data['html'];
    }

    // ---------------------------------------------------------------------
    // Validation helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    private function registerInline(string $type, string $position, string $key, string $content, int $priority, array $source = []): bool
    {
        if (trim($key) === '') {
            return $this->reject($key, $type, 'empty asset key');
        }

        if (trim($content) === '') {
            return $this->reject($key, $type, 'empty inline script');
        }

        if ($this->containsUnsafeScript($content)) {
            return $this->reject($key, $type, 'unsafe inline script pattern');
        }

        return $this->store(new ScriptAsset($key, $type, $position, ['content' => $content], $priority, $this->nextSequence()), $source);
    }

    /**
     * @param  array<string, scalar|bool>  $attributes
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    private function registerExternal(string $type, string $position, string $key, string $url, array $attributes, int $priority, array $source = []): bool
    {
        if (trim($key) === '') {
            return $this->reject($key, $type, 'empty asset key');
        }

        $url = trim($url);

        if (! $this->isTrustedScriptUrl($url)) {
            return $this->reject($key, $type, 'untrusted external script url');
        }

        return $this->store(new ScriptAsset(
            $key,
            $type,
            $position,
            ['url' => $url, 'attributes' => $this->sanitizeScriptAttributes($attributes)],
            $priority,
            $this->nextSequence(),
        ), $source);
    }

    private function containsUnsafeScript(string $content): bool
    {
        $haystack = strtolower($content);

        foreach (self::UNSAFE_SCRIPT_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        // Whitespace-tolerant variants of the same "obvious unsafe" constructs:
        // "new Function(", any inline "on*=" event handler.
        return preg_match('/new\s+function\s*\(/i', $content) === 1
            || preg_match('/\bon[a-z]+\s*=/i', $content) === 1;
    }

    private function isTrustedScriptUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Reject protocol-relative ("//host/x.js") outright: it inherits the page
        // scheme and can downgrade to HTTP even for an allowlisted host.
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Allow same-origin relative URLs ("/js/app.js").
        if (str_starts_with($url, '/')) {
            return ! $this->containsUnsafeScript($url);
        }

        $host = $this->hostOf($url);

        if ($host === null) {
            return false;
        }

        return in_array($host, self::TRUSTED_SCRIPT_HOSTS, true);
    }

    /**
     * @param  array<string, scalar|bool>  $attributes
     * @return array<string, scalar|bool>
     */
    private function sanitizeScriptAttributes(array $attributes): array
    {
        $allowed = ['async', 'defer', 'type', 'crossorigin', 'integrity', 'nonce', 'id', 'referrerpolicy'];
        $clean = [];

        foreach ($attributes as $name => $value) {
            $name = strtolower((string) $name);

            if (in_array($name, $allowed, true)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /**
     * Validate + normalize JSON-LD to an HTML-safe JSON string, or null when
     * the payload is not valid JSON.
     *
     * @param  array<mixed>|string  $data
     */
    private function normalizeJsonLd(array|string $data): ?string
    {
        if (is_string($data)) {
            $trimmed = trim($data);

            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            $data = $decoded;
        }

        if (! is_array($data) || $data === []) {
            return null;
        }

        // JSON_HEX_TAG prevents a "</script>" breakout without double-encoding.
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        return $encoded === false ? null : $encoded;
    }

    /**
     * Sanitize a trusted iframe embed. Returns clean iframe HTML or null when
     * the markup is not an allowlisted, attribute-safe single iframe.
     */
    private function sanitizeEmbed(string $html): ?string
    {
        $html = trim($html);

        if ($html === '' || stripos($html, '<iframe') === false) {
            return null;
        }

        // Reject dangerous siblings outright before DOM parsing.
        foreach (['<script', '<object', '<embed', 'javascript:', 'data:', 'blob:', 'file:'] as $needle) {
            if (stripos($html, $needle) !== false) {
                return null;
            }
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $iframes = $dom->getElementsByTagName('iframe');

        if ($iframes->length !== 1) {
            return null;
        }

        // Any non-iframe element (other than our wrapper div) is a red flag.
        foreach ($dom->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->nodeName);

            if (! in_array($tag, ['html', 'body', 'div', 'iframe'], true)) {
                return null;
            }
        }

        /** @var \DOMElement $iframe */
        $iframe = $iframes->item(0);

        $src = $iframe->getAttribute('src');

        if (! $this->isTrustedEmbedUrl($src)) {
            return null;
        }

        $attrs = '';

        foreach ($iframe->attributes as $attr) {
            $name = strtolower($attr->name);

            if (str_starts_with($name, 'on') || $name === 'style' || $name === 'srcdoc') {
                return null;
            }

            if (! in_array($name, self::ALLOWED_IFRAME_ATTRIBUTES, true)) {
                continue;
            }

            $attrs .= $this->renderAttribute($name, $attr->value);
        }

        return '<iframe'.$attrs.'></iframe>';
    }

    private function isTrustedEmbedUrl(string $url): bool
    {
        $url = trim($url);

        // Protocol-relative sources inherit the page scheme; reject them.
        if (str_starts_with($url, '//')) {
            return false;
        }

        $host = $this->hostOf($url);

        if ($host === null) {
            return false;
        }

        return in_array($host, self::TRUSTED_EMBED_HOSTS, true);
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /** Render a single HTML attribute, escaping boolean and value forms. */
    private function renderAttribute(string $name, mixed $value): string
    {
        $name = $this->esc($name);

        // Boolean attribute (async, allowfullscreen): render the name only.
        if ($value === true) {
            return ' '.$name;
        }

        // Omit the attribute entirely when explicitly disabled/absent.
        if ($value === false || $value === null) {
            return '';
        }

        return ' '.$name.'="'.$this->esc((string) $value).'"';
    }

    private function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // ---------------------------------------------------------------------
    // Registry internals
    // ---------------------------------------------------------------------

    /**
     * Store an asset. A duplicate {type}:{key} replaces the earlier one but
     * inherits its registration sequence, so replacing content never silently
     * reorders the asset relative to its siblings.
     */
    private function store(ScriptAsset $asset, array $source = []): bool
    {
        $mapKey = $asset->type.':'.$asset->key;
        $existing = $this->assets[$mapKey] ?? null;

        if ($existing !== null) {
            $key = $this->safeLabel($asset->key);

            if (! in_array($key, $this->duplicates, true)) {
                $this->duplicates[] = $key;
            }

            $this->warn('duplicate key: '.$key);
        }

        $this->assets[$mapKey] = $existing !== null
            ? $asset->withSequence($existing->sequence)
            : $asset;

        $this->sources[$mapKey] = $this->normalizeSource($source);

        return true;
    }

    /**
     * Normalize passive source metadata (v1.0.0-beta.7.1.13.3). Unknown types
     * fall back to "custom" and record a warning; an empty name defaults to the
     * type. Never affects validation or rendering.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     * @return array{type: string, name: string}
     */
    private function normalizeSource(array $source): array
    {
        $type = strtolower(trim((string) ($source['source_type'] ?? '')));
        $name = trim((string) ($source['source_name'] ?? ''));

        if ($type === '') {
            $type = self::SOURCE_DEFAULT;
        } elseif (! in_array($type, self::SOURCE_TYPES, true)) {
            $this->warn('unknown source type: '.$this->safeLabel($type));
            $type = self::SOURCE_DEFAULT;
        }

        if ($name === '') {
            $name = $type;
        }

        return ['type' => $type, 'name' => $this->safeLabel($name)];
    }

    /** Record a short, safe diagnostic warning (deduplicated, bounded). */
    private function warn(string $message): void
    {
        if (count($this->warnings) < 100 && ! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Reduce an arbitrary label (source name, key) to a short, safe string:
     * alphanumerics plus a small punctuation set, capped in length. Guarantees
     * diagnostics never leak script contents, URLs, or values.
     */
    private function safeLabel(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9:_\-. ]/', '', $value) ?? '';

        return substr(trim($clean), 0, 64);
    }

    private function reject(string $key, string $type, string $reason): bool
    {
        $this->rejected[] = ['key' => $key, 'type' => $type, 'reason' => $reason];

        return false;
    }

    private function nextSequence(): int
    {
        return ++$this->sequence;
    }

    private function fireAction(string $hook): void
    {
        try {
            if (app()->bound('cms.hooks')) {
                app('cms.hooks')->doAction($hook, $this);
            }
        } catch (\Throwable) {
            // Rendering must never throw on the frontend.
        }
    }

    /**
     * Apply a rendered-HTML filter. Like the other core render filters
     * (cms.theme.*), cms.scripts.head_html / footer_html are PRIVILEGED
     * extension points: a callback receives the fully-sanitized HTML and its
     * string return value is emitted verbatim. They must only be bound from
     * trusted plugin/theme code, never from user-configurable input.
     */
    private function applyHtmlFilter(string $hook, string $html): string
    {
        try {
            if (app()->bound('cms.hooks')) {
                $filtered = app('cms.hooks')->applyFilters($hook, $html);

                return is_string($filtered) ? $filtered : $html;
            }
        } catch (\Throwable) {
            // Rendering must never throw on the frontend.
        }

        return $html;
    }

    // ---------------------------------------------------------------------
    // Introspection
    // ---------------------------------------------------------------------

    /** @return list<ScriptAsset> */
    public function all(): array
    {
        return array_values($this->assets);
    }

    /** @return list<array{key: string, type: string, reason: string}> */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * Assets skipped by the most recent renderHead()/renderFooter() call.
     *
     * @return list<array{key: string, type: string, reason: string}>
     */
    public function lastRenderSkipped(): array
    {
        return $this->renderSkipped;
    }

    /**
     * Passive diagnostic snapshot (v1.0.0-beta.7.1.13.3). Returns METADATA ONLY —
     * counts, source breakdowns, and short safe warning strings. Never exposes
     * script code, inline JavaScript, JSON-LD content, verification values, embed
     * HTML, or URLs. Never throws; never triggers rendering.
     *
     * @return array{
     *     registered: int,
     *     rendered: int,
     *     rejected: int,
     *     sources: array<string, int>,
     *     plugins: array<string, int>,
     *     themes: array<string, int>,
     *     settings: array{verification: int, jsonld: int, head: int, footer: int, external: int, embeds: int},
     *     warnings: list<string>,
     *     duplicate_keys: list<string>
     * }
     */
    public function diagnostics(): array
    {
        $sources = array_fill_keys(self::SOURCE_TYPES, 0);
        $plugins = [];
        $themes = [];
        $settings = ['verification' => 0, 'jsonld' => 0, 'head' => 0, 'footer' => 0, 'external' => 0, 'embeds' => 0];

        foreach ($this->assets as $mapKey => $asset) {
            $source = $this->sources[$mapKey] ?? ['type' => self::SOURCE_DEFAULT, 'name' => self::SOURCE_DEFAULT];
            $type = $source['type'];
            $sources[$type] = ($sources[$type] ?? 0) + 1;

            if ($type === 'plugin') {
                $plugins[$source['name']] = ($plugins[$source['name']] ?? 0) + 1;
            } elseif ($type === 'theme') {
                $themes[$source['name']] = ($themes[$source['name']] ?? 0) + 1;
            } elseif ($type === 'settings') {
                $bucket = $this->settingsBucket($asset->type);

                if ($bucket !== null) {
                    $settings[$bucket]++;
                }
            }
        }

        $registered = count($this->assets);

        return [
            'registered' => $registered,
            'rendered' => max(0, $registered - count($this->renderSkipped)),
            'rejected' => count($this->rejected),
            'sources' => $sources,
            'plugins' => $plugins,
            'themes' => $themes,
            'settings' => $settings,
            'warnings' => $this->diagnosticWarnings(),
            'duplicate_keys' => array_values($this->duplicates),
        ];
    }

    /** Passive diagnostic warnings collected during registration. @return list<string> */
    public function warnings(): array
    {
        return $this->diagnosticWarnings();
    }

    /**
     * Merge registration warnings with (deduplicated, safe) rejection reasons.
     *
     * @return list<string>
     */
    private function diagnosticWarnings(): array
    {
        $out = $this->warnings;

        foreach ($this->rejected as $rejection) {
            $message = 'rejected '.$rejection['type'].': '.$rejection['reason'];

            if (! in_array($message, $out, true)) {
                $out[] = $message;
            }
        }

        return array_values($out);
    }

    /** Map a ScriptAsset type to a settings-breakdown bucket, or null. */
    private function settingsBucket(string $type): ?string
    {
        return match ($type) {
            ScriptAsset::TYPE_VERIFICATION => 'verification',
            ScriptAsset::TYPE_JSON_LD => 'jsonld',
            ScriptAsset::TYPE_HEAD_INLINE => 'head',
            ScriptAsset::TYPE_FOOTER_INLINE => 'footer',
            ScriptAsset::TYPE_HEAD_EXTERNAL, ScriptAsset::TYPE_FOOTER_EXTERNAL => 'external',
            ScriptAsset::TYPE_EMBED => 'embeds',
            default => null,
        };
    }

    /** Reset all registrations (primarily for tests). */
    public function flush(): void
    {
        $this->assets = [];
        $this->sources = [];
        $this->warnings = [];
        $this->duplicates = [];
        $this->rejected = [];
        $this->renderSkipped = [];
        $this->sequence = 0;
    }

    private function countType(string $type): int
    {
        return count(array_filter($this->assets, static fn (ScriptAsset $a): bool => $a->type === $type));
    }

    /**
     * Count-only snapshot for /cms-health. Never exposes script contents,
     * URLs, keys, or values. Never throws.
     *
     * @return array{
     *     script_manager_ready: bool,
     *     registered_head_assets: int,
     *     registered_footer_assets: int,
     *     registered_meta: int,
     *     registered_json_ld: int,
     *     registered_verifications: int,
     *     registered_embeds: int,
     *     script_source_count: int,
     *     script_warning_count: int,
     *     plugin_script_sources: int,
     *     theme_script_sources: int
     * }
     */
    public function healthSnapshot(): array
    {
        $diagnostics = $this->diagnostics();

        return [
            'script_manager_ready' => true,
            'registered_head_assets' => $this->countType(ScriptAsset::TYPE_HEAD_INLINE) + $this->countType(ScriptAsset::TYPE_HEAD_EXTERNAL),
            'registered_footer_assets' => $this->countType(ScriptAsset::TYPE_FOOTER_INLINE) + $this->countType(ScriptAsset::TYPE_FOOTER_EXTERNAL),
            'registered_meta' => $this->countType(ScriptAsset::TYPE_META),
            'registered_json_ld' => $this->countType(ScriptAsset::TYPE_JSON_LD),
            'registered_verifications' => $this->countType(ScriptAsset::TYPE_VERIFICATION),
            'registered_embeds' => $this->countType(ScriptAsset::TYPE_EMBED),
            // Source/warning observability (v1.0.0-beta.7.1.13.3) — COUNTS only.
            'script_source_count' => array_sum($diagnostics['sources']),
            'script_warning_count' => count($diagnostics['warnings']),
            'plugin_script_sources' => count($diagnostics['plugins']),
            'theme_script_sources' => count($diagnostics['themes']),
        ];
    }
}
