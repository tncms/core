<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Assets\Asset;
use TheNguyen\CMS\Support\Assets\AssetRenderResult;

/**
 * Asset Registry (v1.0.0-beta.7.1.13.1 — Asset Registry Foundation).
 *
 * A Core-level registry + renderer that lets themes and plugins declare
 * frontend/admin CSS/JS assets by handle, enqueue them when needed, resolve
 * dependencies, and render them in the correct order. A modern, cleaner
 * equivalent of the WordPress enqueue API.
 *
 * Model:
 *  - register* defines an asset by handle (style/script/module). Definitions
 *    do not render until enqueued. Handles are a single namespace; the last
 *    registration for a handle wins.
 *  - enqueue* activates a handle (and, transitively, its dependencies).
 *  - inline* registers inline CSS/JS that either stands alone or attaches
 *    before/after a target handle; inline assets are active on registration.
 *  - render* emits one bucket (scope + head/footer position) in dependency
 *    order. Rendering never throws; invalid assets are skipped and reported.
 *
 * Dependency rules:
 *  - A dependency renders before its dependent.
 *  - A missing dependency is reported as a warning; the dependent still renders.
 *  - A circular dependency is broken safely (never an infinite loop) and reported.
 *
 * Security: this is a trusted theme/plugin API, not a full sanitizer. Sources
 * and inline content are screened for obviously unsafe patterns, external URLs
 * must be same-origin or on a small trusted allowlist, and attributes are
 * escaped with event handlers rejected.
 *
 * NOT a bundler/minifier/build pipeline — no concatenation, no minification,
 * no file hashing, no PHP execution.
 */
class AssetRegistry
{
    /** @var array<string, Asset> Registered style/script/module/marker by handle. */
    private array $assets = [];

    /** @var array<string, true> Explicitly enqueued handles. */
    private array $enqueued = [];

    /** @var list<Asset> Registered inline assets (active immediately). */
    private array $inlines = [];

    /**
     * Source metadata per registration (v1.0.0-beta.7.1.13.3). Registered assets
     * key by handle; inlines key by "inline:{type}:{handle}". Passive diagnostics
     * only — never affects dependency resolution or rendering.
     *
     * @var array<string, array{type: string, name: string}>
     */
    private array $sources = [];

    /** @var list<string> Registration-time diagnostic warnings (short, safe strings). */
    private array $registrationWarnings = [];

    /** @var list<string> Handles that were registered more than once (last wins). */
    private array $duplicates = [];

    /** @var list<array{handle: string, reason: string}> */
    private array $rejected = [];

    /** @var list<array{handle: string, reason: string}> Diagnostics for the last render. */
    private array $warnings = [];

    /** @var list<array{handle: string, reason: string}> Assets skipped by the last render. */
    private array $renderSkipped = [];

    private int $sequence = 0;

    /** Allowed source_type values; anything else falls back to SOURCE_DEFAULT. */
    private const SOURCE_TYPES = ['core', 'settings', 'theme', 'plugin', 'custom'];

    private const SOURCE_DEFAULT = 'custom';

    /** HTTPS hosts allowed to serve an external asset src (exact host match). */
    private const TRUSTED_ASSET_HOSTS = [
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'unpkg.com',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'esm.sh',
    ];

    /** Attributes kept for <script>/<link> when set to true/scalar. */
    private const ALLOWED_SCRIPT_ATTRIBUTES = [
        'defer', 'async', 'type', 'crossorigin', 'integrity', 'referrerpolicy', 'nomodule', 'id', 'nonce',
    ];

    private const ALLOWED_STYLE_ATTRIBUTES = [
        'media', 'crossorigin', 'integrity', 'referrerpolicy', 'id',
    ];

    /** Case-insensitive substrings that reject an inline <script> body. */
    private const UNSAFE_SCRIPT_PATTERNS = [
        '</script', 'eval(', 'document.write', 'javascript:', 'vbscript:', 'data:text/html', 'blob:', 'file:',
    ];

    /** Case-insensitive substrings that reject an inline style body. */
    private const UNSAFE_STYLE_PATTERNS = [
        '<script', 'javascript:', 'expression(', 'behavior:',
    ];

    // ---------------------------------------------------------------------
    // Registration API
    // ---------------------------------------------------------------------

    /**
     * Register a stylesheet by handle. Renders in the head by default.
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     */
    public function registerStyle(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        string $scope = Asset::SCOPE_FRONTEND,
        string $position = Asset::POSITION_HEAD,
        array $attributes = [],
        ?string $media = null,
        array $source = [],
    ): bool {
        if ($media !== null && $media !== '') {
            $attributes['media'] = $media;
        }

        return $this->registerSourced(Asset::TYPE_STYLE, $handle, $src, $deps, $version, $scope, $position, $attributes, $source);
    }

    /**
     * Register a classic <script src> by handle. Defaults to the footer.
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function registerScript(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        string $scope = Asset::SCOPE_FRONTEND,
        string $position = Asset::POSITION_FOOTER,
        array $attributes = [],
        array $source = [],
    ): bool {
        return $this->registerSourced(Asset::TYPE_SCRIPT, $handle, $src, $deps, $version, $scope, $position, $attributes, $source);
    }

    /**
     * Register an ES module (<script type="module">) by handle. Footer by default.
     *
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function registerModule(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        string $scope = Asset::SCOPE_FRONTEND,
        string $position = Asset::POSITION_FOOTER,
        array $attributes = [],
        array $source = [],
    ): bool {
        return $this->registerSourced(Asset::TYPE_MODULE, $handle, $src, $deps, $version, $scope, $position, $attributes, $source);
    }

    /**
     * Register a no-output dependency anchor (e.g. tncms.frontend). Lets
     * plugins depend on a core "bucket" without a missing-dependency warning.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function registerMarker(string $handle, string $scope = Asset::SCOPE_BOTH, array $source = []): bool
    {
        $handle = trim($handle);

        if ($handle === '') {
            return $this->reject($handle, 'empty handle');
        }

        return $this->store(new Asset(
            handle: $handle,
            type: Asset::TYPE_MARKER,
            scope: $this->normalizeScope($scope),
            sequence: $this->nextSequence(),
        ), $source);
    }

    /** Enqueue a registered style so it (and its deps) render. */
    public function enqueueStyle(string $handle): bool
    {
        return $this->enqueue($handle);
    }

    /** Enqueue a registered script so it (and its deps) render. */
    public function enqueueScript(string $handle): bool
    {
        return $this->enqueue($handle);
    }

    /** Enqueue a registered module so it (and its deps) render. */
    public function enqueueModule(string $handle): bool
    {
        return $this->enqueue($handle);
    }

    /** Register and enqueue a style in one call. */
    public function style(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = Asset::SCOPE_FRONTEND, string $position = Asset::POSITION_HEAD, array $attributes = [], ?string $media = null, array $source = []): bool
    {
        return $this->registerStyle($handle, $src, $deps, $version, $scope, $position, $attributes, $media, $source)
            && $this->enqueue($handle);
    }

    /** Register and enqueue a script in one call. */
    public function script(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = Asset::SCOPE_FRONTEND, string $position = Asset::POSITION_FOOTER, array $attributes = [], array $source = []): bool
    {
        return $this->registerScript($handle, $src, $deps, $version, $scope, $position, $attributes, $source)
            && $this->enqueue($handle);
    }

    /**
     * Register inline CSS. Stands alone, or attaches before/after a handle.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function inlineStyle(
        string $handle,
        string $code,
        ?string $before = null,
        ?string $after = null,
        string $scope = Asset::SCOPE_FRONTEND,
        string $position = Asset::POSITION_HEAD,
        array $source = [],
    ): bool {
        return $this->registerInline(Asset::TYPE_INLINE_STYLE, $handle, $code, $before, $after, $scope, $position, $source);
    }

    /**
     * Register inline JS. Stands alone, or attaches before/after a handle.
     *
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    public function inlineScript(
        string $handle,
        string $code,
        ?string $before = null,
        ?string $after = null,
        string $scope = Asset::SCOPE_FRONTEND,
        string $position = Asset::POSITION_FOOTER,
        array $source = [],
    ): bool {
        return $this->registerInline(Asset::TYPE_INLINE_SCRIPT, $handle, $code, $before, $after, $scope, $position, $source);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /** Render the frontend head bucket (styles + head scripts). Never throws. */
    public function renderFrontendStyles(): string
    {
        return $this->render(Asset::SCOPE_FRONTEND, Asset::POSITION_HEAD);
    }

    /** Render the frontend footer bucket (footer scripts + styles). Never throws. */
    public function renderFrontendScripts(): string
    {
        return $this->render(Asset::SCOPE_FRONTEND, Asset::POSITION_FOOTER);
    }

    /** Render the admin head bucket. Never throws. */
    public function renderAdminStyles(): string
    {
        return $this->render(Asset::SCOPE_ADMIN, Asset::POSITION_HEAD);
    }

    /** Render the admin footer bucket. Never throws. */
    public function renderAdminScripts(): string
    {
        return $this->render(Asset::SCOPE_ADMIN, Asset::POSITION_FOOTER);
    }

    /** Generic: render the head bucket for a scope (defaults to head). */
    public function renderStyles(string $scope, string $position = Asset::POSITION_HEAD): string
    {
        return $this->render($this->normalizeScope($scope), $this->normalizePosition($position, Asset::POSITION_HEAD));
    }

    /** Generic: render the footer bucket for a scope (defaults to footer). */
    public function renderScripts(string $scope, string $position = Asset::POSITION_FOOTER): string
    {
        return $this->render($this->normalizeScope($scope), $this->normalizePosition($position, Asset::POSITION_FOOTER));
    }

    /**
     * Render one position bucket (head or footer) for a scope. Styles render
     * before scripts within the bucket; both follow dependency order. This
     * mirrors wp_head/wp_footer: a head-positioned script renders in the head
     * bucket, a footer-positioned style in the footer bucket. Never throws.
     */
    private function render(string $scope, string $position): string
    {
        $this->warnings = [];
        $this->renderSkipped = [];

        try {
            $result = $this->buildBucket($scope, $position);
        } catch (\Throwable) {
            // Defensive: rendering must never throw.
            return '';
        }

        $this->renderSkipped = $result->skipped;

        return $result->html;
    }

    /** Build a position bucket's HTML from the enqueued closure. */
    private function buildBucket(string $scope, string $position): AssetRenderResult
    {
        $ordered = $this->resolveOrder();
        $skipped = [];
        $chunks = [];

        // Styles first, then scripts — each in dependency order, so a script
        // that depends on a style still sees it in the document.
        foreach ([true, false] as $stylesPass) {
            foreach ($ordered as $handle) {
                $asset = $this->assets[$handle];

                if ($asset->type === Asset::TYPE_MARKER) {
                    continue;
                }

                if ($stylesPass ? ! $asset->isStyle() : ! $asset->isScript()) {
                    continue;
                }

                if (! $asset->inScope($scope) || $asset->position !== $position) {
                    continue;
                }

                foreach ($this->inlinesFor($handle, 'before', $scope) as $inline) {
                    $this->emit($inline, $chunks, $skipped);
                }

                $this->emit($asset, $chunks, $skipped);

                foreach ($this->inlinesFor($handle, 'after', $scope) as $inline) {
                    $this->emit($inline, $chunks, $skipped);
                }
            }

            // Standalone inlines (no target) of this family for the bucket.
            foreach ($this->standaloneInlines($scope, $position, $stylesPass) as $inline) {
                $this->emit($inline, $chunks, $skipped);
            }
        }

        return new AssetRenderResult(
            $chunks === [] ? '' : implode("\n", $chunks)."\n",
            $skipped,
        );
    }

    /**
     * @param  list<string>  $chunks
     * @param  list<array{handle: string, reason: string}>  $skipped
     */
    private function emit(Asset $asset, array &$chunks, array &$skipped): void
    {
        try {
            $html = $this->renderAsset($asset);
        } catch (\Throwable) {
            $html = null;
        }

        if ($html === null || $html === '') {
            $skipped[] = ['handle' => $asset->handle, 'reason' => 'render skipped'];

            return;
        }

        $chunks[] = $html;
    }

    private function renderAsset(Asset $asset): ?string
    {
        return match ($asset->type) {
            Asset::TYPE_STYLE => $this->renderStyleTag($asset),
            Asset::TYPE_SCRIPT => $this->renderScriptTag($asset, module: false),
            Asset::TYPE_MODULE => $this->renderScriptTag($asset, module: true),
            Asset::TYPE_INLINE_STYLE => '<style>'.$asset->code.'</style>',
            Asset::TYPE_INLINE_SCRIPT => '<script>'.$asset->code.'</script>',
            default => null,
        };
    }

    private function renderStyleTag(Asset $asset): ?string
    {
        if ($asset->src === null) {
            return null;
        }

        $attrs = $this->renderAttributes($asset->attributes);

        return '<link rel="stylesheet" href="'.$this->esc($this->withVersion($asset)).'"'.$attrs.'>';
    }

    private function renderScriptTag(Asset $asset, bool $module): ?string
    {
        if ($asset->src === null) {
            return null;
        }

        $attributes = $asset->attributes;

        if ($module) {
            $attributes['type'] = 'module';
        }

        $attrs = $this->renderAttributes($attributes);

        return '<script src="'.$this->esc($this->withVersion($asset)).'"'.$attrs.'></script>';
    }

    /** Append ?ver= for cache busting when a version is set and safe. */
    private function withVersion(Asset $asset): string
    {
        $src = (string) $asset->src;

        if ($asset->version === null || $asset->version === '' || str_contains($src, 'ver=')) {
            return $src;
        }

        $separator = str_contains($src, '?') ? '&' : '?';

        return $src.$separator.'ver='.rawurlencode($asset->version);
    }

    /**
     * @param  array<string, scalar|bool>  $attributes
     */
    private function renderAttributes(array $attributes): string
    {
        $out = '';

        foreach ($attributes as $name => $value) {
            $out .= $this->renderAttribute((string) $name, $value);
        }

        return $out;
    }

    private function renderAttribute(string $name, mixed $value): string
    {
        $name = $this->esc($name);

        if ($value === true) {
            return ' '.$name;
        }

        if ($value === false || $value === null) {
            return '';
        }

        return ' '.$name.'="'.$this->esc((string) $value).'"';
    }

    // ---------------------------------------------------------------------
    // Dependency resolution
    // ---------------------------------------------------------------------

    /**
     * Resolve the enqueued closure to a render order (dependencies first).
     * Missing deps warn; cycles break safely.
     *
     * @return list<string>
     */
    private function resolveOrder(): array
    {
        $order = [];
        $state = []; // handle => 'visiting' | 'done'

        foreach (array_keys($this->enqueued) as $handle) {
            $this->visit($handle, $state, $order);
        }

        return $order;
    }

    /**
     * Depth-first post-order visit. `visiting` marks the current path so a
     * back edge (cycle) is detected and skipped instead of recursing forever.
     *
     * @param  array<string, string>  $state
     * @param  list<string>  $order
     */
    private function visit(string $handle, array &$state, array &$order): void
    {
        if (($state[$handle] ?? null) === 'done') {
            return;
        }

        if (($state[$handle] ?? null) === 'visiting') {
            $this->warnings[] = ['handle' => $handle, 'reason' => 'circular dependency broken'];

            return;
        }

        $asset = $this->assets[$handle] ?? null;

        if ($asset === null) {
            $this->warnings[] = ['handle' => $handle, 'reason' => 'missing dependency'];

            return;
        }

        $state[$handle] = 'visiting';

        foreach ($asset->deps as $dep) {
            $this->visit($dep, $state, $order);
        }

        $state[$handle] = 'done';
        $order[] = $handle;
    }

    // ---------------------------------------------------------------------
    // Inline helpers
    // ---------------------------------------------------------------------

    /**
     * Inlines attached before/after a target handle, in scope. Rendered
     * adjacent to the target regardless of family (an inline_script attached
     * to a script, inline_style to a style — the common cases).
     *
     * @return list<Asset>
     */
    private function inlinesFor(string $target, string $relation, string $scope): array
    {
        $out = [];

        foreach ($this->inlines as $inline) {
            if ($inline->target !== $target || $inline->relation !== $relation) {
                continue;
            }

            if ($inline->inScope($scope)) {
                $out[] = $inline;
            }
        }

        return $out;
    }

    /**
     * Standalone inlines (no target) for a bucket. `$stylesFamily` selects
     * inline_style vs inline_script.
     *
     * @return list<Asset>
     */
    private function standaloneInlines(string $scope, string $position, bool $stylesFamily): array
    {
        $out = [];

        foreach ($this->inlines as $inline) {
            if ($inline->target !== null) {
                continue;
            }

            if (! $inline->inScope($scope) || $inline->position !== $position) {
                continue;
            }

            if ($stylesFamily ? ! $inline->isStyle() : ! $inline->isScript()) {
                continue;
            }

            $out[] = $inline;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $deps
     * @param  array<string, scalar|bool>  $attributes
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    private function registerSourced(string $type, string $handle, string $src, array $deps, ?string $version, string $scope, string $position, array $attributes, array $source = []): bool
    {
        $handle = trim($handle);

        if ($handle === '') {
            return $this->reject($handle, 'empty handle');
        }

        $src = trim($src);

        if (! $this->isSafeSrc($src)) {
            return $this->reject($handle, 'unsafe or untrusted src');
        }

        $allowed = $type === Asset::TYPE_STYLE ? self::ALLOWED_STYLE_ATTRIBUTES : self::ALLOWED_SCRIPT_ATTRIBUTES;
        $clean = $this->sanitizeAttributes($attributes, $allowed);

        if ($clean === null) {
            return $this->reject($handle, 'rejected attribute');
        }

        return $this->store(new Asset(
            handle: $handle,
            type: $type,
            src: $src,
            deps: $this->normalizeDeps($deps),
            version: $version,
            scope: $this->normalizeScope($scope),
            position: $this->normalizePosition($position, $type === Asset::TYPE_STYLE ? Asset::POSITION_HEAD : Asset::POSITION_FOOTER),
            attributes: $clean,
            sequence: $this->nextSequence(),
        ), $source);
    }

    /**
     * @param  array{source_type?: string, source_name?: string}  $source
     */
    private function registerInline(string $type, string $handle, string $code, ?string $before, ?string $after, string $scope, string $position, array $source = []): bool
    {
        $handle = trim($handle);

        if ($handle === '') {
            return $this->reject($handle, 'empty handle');
        }

        if (trim($code) === '') {
            return $this->reject($handle, 'empty inline content');
        }

        $unsafe = $type === Asset::TYPE_INLINE_SCRIPT
            ? $this->containsUnsafeScript($code)
            : $this->containsUnsafeStyle($code);

        if ($unsafe) {
            return $this->reject($handle, 'unsafe inline content');
        }

        [$target, $relation] = $this->resolveInlineTarget($before, $after);

        $inline = new Asset(
            handle: $handle,
            type: $type,
            code: $code,
            scope: $this->normalizeScope($scope),
            position: $this->normalizePosition($position, $type === Asset::TYPE_INLINE_STYLE ? Asset::POSITION_HEAD : Asset::POSITION_FOOTER),
            target: $target,
            relation: $relation,
            sequence: $this->nextSequence(),
        );

        $sourceKey = 'inline:'.$type.':'.$handle;

        // Last write wins for a given inline handle+type.
        foreach ($this->inlines as $i => $existing) {
            if ($existing->handle === $handle && $existing->type === $type) {
                $this->inlines[$i] = $inline->withSequence($existing->sequence);
                $this->recordDuplicate($handle);
                $this->sources[$sourceKey] = $this->normalizeSource($source);

                return true;
            }
        }

        $this->inlines[] = $inline;
        $this->sources[$sourceKey] = $this->normalizeSource($source);

        return true;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveInlineTarget(?string $before, ?string $after): array
    {
        if (is_string($before) && trim($before) !== '') {
            return [trim($before), 'before'];
        }

        if (is_string($after) && trim($after) !== '') {
            return [trim($after), 'after'];
        }

        return [null, null];
    }

    /**
     * Validate an asset src. Allows relative + same-origin URLs and a small
     * HTTPS allowlist; rejects dangerous schemes and protocol-relative URLs.
     */
    private function isSafeSrc(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $lower = strtolower($url);

        foreach (['javascript:', 'vbscript:', 'data:text/html', 'blob:', 'file:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return false;
            }
        }

        // Reject any other data: src for scripts/styles; assets are never data URIs.
        if (str_starts_with($lower, 'data:')) {
            return false;
        }

        // Protocol-relative ("//host/app.js") inherits the page scheme; reject.
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Relative / same-origin absolute path.
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return true;
        }

        $host = $this->hostOf($url);

        // No host => a bare relative path like "css/app.css".
        if ($host === null) {
            return ! str_contains($lower, ':');
        }

        // Absolute URL: allow same-origin, else require HTTPS + trusted host.
        if ($host === $this->currentHost()) {
            return true;
        }

        return str_starts_with($lower, 'https://') && in_array($host, self::TRUSTED_ASSET_HOSTS, true);
    }

    /**
     * Allowlist + escape-check attributes. Returns null if any attribute is an
     * event handler (name starting with "on").
     *
     * @param  array<string, scalar|bool>  $attributes
     * @param  list<string>  $allowed
     * @return array<string, scalar|bool>|null
     */
    private function sanitizeAttributes(array $attributes, array $allowed): ?array
    {
        $clean = [];

        foreach ($attributes as $name => $value) {
            $name = strtolower(trim((string) $name));

            if ($name === '') {
                continue;
            }

            if (str_starts_with($name, 'on')) {
                return null;
            }

            if (in_array($name, $allowed, true)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    private function containsUnsafeScript(string $code): bool
    {
        $haystack = strtolower($code);

        foreach (self::UNSAFE_SCRIPT_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return preg_match('/new\s+function\s*\(/i', $code) === 1
            || preg_match('/\bon[a-z]+\s*=/i', $code) === 1;
    }

    private function containsUnsafeStyle(string $code): bool
    {
        $haystack = strtolower($code);

        foreach (self::UNSAFE_STYLE_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        // Reject @import of a remote/protocol-relative URL; same-origin allowed.
        if (str_contains($haystack, '@import')
            && (str_contains($haystack, 'http://') || str_contains($haystack, 'https://') || str_contains($haystack, '//'))) {
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Registry internals
    // ---------------------------------------------------------------------

    /** Enqueue a handle. Deps are pulled in at render time. */
    private function enqueue(string $handle): bool
    {
        $handle = trim($handle);

        if ($handle === '') {
            return false;
        }

        $this->enqueued[$handle] = true;

        return true;
    }

    /**
     * Store a registered asset. A duplicate handle replaces the earlier one but
     * inherits its registration sequence so replacing never reorders siblings.
     */
    private function store(Asset $asset, array $source = []): bool
    {
        $existing = $this->assets[$asset->handle] ?? null;

        if ($existing !== null) {
            $this->recordDuplicate($asset->handle);
        }

        $this->assets[$asset->handle] = $existing !== null
            ? $asset->withSequence($existing->sequence)
            : $asset;

        $this->sources[$asset->handle] = $this->normalizeSource($source);

        return true;
    }

    /**
     * Normalize passive source metadata (v1.0.0-beta.7.1.13.3). Unknown types
     * fall back to "custom" and record a warning; an empty name defaults to the
     * type. Never affects dependency resolution or rendering.
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

    private function recordDuplicate(string $handle): void
    {
        $handle = $this->safeLabel($handle);

        if (! in_array($handle, $this->duplicates, true)) {
            $this->duplicates[] = $handle;
        }

        $this->warn('duplicate handle: '.$handle);
    }

    /** Record a short, safe registration warning (deduplicated, bounded). */
    private function warn(string $message): void
    {
        if (count($this->registrationWarnings) < 100 && ! in_array($message, $this->registrationWarnings, true)) {
            $this->registrationWarnings[] = $message;
        }
    }

    /**
     * Reduce an arbitrary label (source name, handle) to a short, safe string:
     * alphanumerics plus a small punctuation set, capped in length. Guarantees
     * diagnostics never leak URLs, inline code, or file paths.
     */
    private function safeLabel(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9:_\-. ]/', '', $value) ?? '';

        return substr(trim($clean), 0, 64);
    }

    private function reject(string $handle, string $reason): bool
    {
        $this->rejected[] = ['handle' => $handle, 'reason' => $reason];

        return false;
    }

    private function nextSequence(): int
    {
        return ++$this->sequence;
    }

    /**
     * @param  list<string>  $deps
     * @return list<string>
     */
    private function normalizeDeps(array $deps): array
    {
        $out = [];

        foreach ($deps as $dep) {
            $dep = trim((string) $dep);

            if ($dep !== '' && ! in_array($dep, $out, true)) {
                $out[] = $dep;
            }
        }

        return $out;
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return in_array($scope, [Asset::SCOPE_FRONTEND, Asset::SCOPE_ADMIN, Asset::SCOPE_BOTH], true)
            ? $scope
            : Asset::SCOPE_FRONTEND;
    }

    private function normalizePosition(string $position, string $default): string
    {
        $position = strtolower(trim($position));

        return in_array($position, [Asset::POSITION_HEAD, Asset::POSITION_FOOTER], true)
            ? $position
            : $default;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function currentHost(): ?string
    {
        try {
            $host = request()->getHost();

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // ---------------------------------------------------------------------
    // Introspection
    // ---------------------------------------------------------------------

    /** @return list<Asset> */
    public function all(): array
    {
        return array_values($this->assets);
    }

    /** @return list<Asset> */
    public function inlines(): array
    {
        return $this->inlines;
    }

    /** @return array<string, true> */
    public function enqueuedHandles(): array
    {
        return $this->enqueued;
    }

    /** @return list<array{handle: string, reason: string}> */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /** @return list<array{handle: string, reason: string}> Diagnostics from the last render. */
    public function lastRenderWarnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array{handle: string, reason: string}> */
    public function lastRenderSkipped(): array
    {
        return $this->renderSkipped;
    }

    /** Reset all registrations (primarily for tests). */
    public function flush(): void
    {
        $this->assets = [];
        $this->enqueued = [];
        $this->inlines = [];
        $this->sources = [];
        $this->registrationWarnings = [];
        $this->duplicates = [];
        $this->rejected = [];
        $this->warnings = [];
        $this->renderSkipped = [];
        $this->sequence = 0;
    }

    /**
     * Passive diagnostic snapshot (v1.0.0-beta.7.1.13.3), mirroring
     * {@see ScriptManager::diagnostics()}. Returns METADATA ONLY — counts, source
     * breakdowns, dependency counts, and short safe warning strings. Never exposes
     * handles' source URLs, inline code, or file paths. Never throws.
     *
     * Warnings reflect the most recent render (missing/circular dependencies)
     * plus registration-time issues (duplicate handles, unknown sources).
     *
     * @return array{
     *     registered: int,
     *     rendered: int,
     *     rejected: int,
     *     sources: array<string, int>,
     *     plugins: array<string, int>,
     *     themes: array<string, int>,
     *     dependencies: array{declared: int, missing: int},
     *     warnings: list<string>,
     *     duplicates: list<string>
     * }
     */
    public function diagnostics(): array
    {
        $sources = array_fill_keys(self::SOURCE_TYPES, 0);
        $plugins = [];
        $themes = [];

        foreach ($this->sources as $source) {
            $type = $source['type'];
            $sources[$type] = ($sources[$type] ?? 0) + 1;

            if ($type === 'plugin') {
                $plugins[$source['name']] = ($plugins[$source['name']] ?? 0) + 1;
            } elseif ($type === 'theme') {
                $themes[$source['name']] = ($themes[$source['name']] ?? 0) + 1;
            }
        }

        $declared = 0;
        foreach ($this->assets as $asset) {
            $declared += count($asset->deps);
        }

        $missing = 0;
        foreach ($this->warnings as $warning) {
            if ($warning['reason'] === 'missing dependency') {
                $missing++;
            }
        }

        $registered = count($this->assets) + count($this->inlines);

        return [
            'registered' => $registered,
            'rendered' => max(0, $registered - count($this->renderSkipped)),
            'rejected' => count($this->rejected),
            'sources' => $sources,
            'plugins' => $plugins,
            'themes' => $themes,
            'dependencies' => ['declared' => $declared, 'missing' => $missing],
            'warnings' => $this->diagnosticWarnings(),
            'duplicates' => array_values($this->duplicates),
        ];
    }

    /** Passive diagnostic warnings (registration + last render). @return list<string> */
    public function warnings(): array
    {
        return $this->diagnosticWarnings();
    }

    /**
     * Merge registration warnings with (deduplicated, safe) render-warning and
     * rejection reasons.
     *
     * @return list<string>
     */
    private function diagnosticWarnings(): array
    {
        $out = $this->registrationWarnings;

        foreach ($this->warnings as $warning) {
            $message = $warning['reason'].': '.$this->safeLabel($warning['handle']);

            if (! in_array($message, $out, true)) {
                $out[] = $message;
            }
        }

        foreach ($this->rejected as $rejection) {
            $message = 'rejected: '.$rejection['reason'];

            if (! in_array($message, $out, true)) {
                $out[] = $message;
            }
        }

        return array_values($out);
    }

    /**
     * Count enqueued assets that render in a scope (styles + scripts, any
     * position), including in-scope inline assets. Never throws.
     */
    private function countEnqueuedForScope(string $scope): int
    {
        $ordered = $this->resolveOrder();
        $count = 0;

        foreach ($ordered as $handle) {
            $asset = $this->assets[$handle];

            if ($asset->type !== Asset::TYPE_MARKER && $asset->inScope($scope)) {
                $count++;
            }
        }

        foreach ($this->inlines as $inline) {
            if ($inline->inScope($scope)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count-only snapshot for /cms-health. Never exposes handles, URLs, or code.
     * Never throws.
     *
     * @return array{
     *     asset_registry_ready: bool,
     *     registered_asset_count: int,
     *     enqueued_frontend_asset_count: int,
     *     enqueued_admin_asset_count: int,
     *     asset_source_count: int,
     *     asset_warning_count: int
     * }
     */
    public function healthSnapshot(): array
    {
        $diagnostics = $this->diagnostics();

        return [
            'asset_registry_ready' => true,
            'registered_asset_count' => count($this->assets) + count($this->inlines),
            'enqueued_frontend_asset_count' => $this->countEnqueuedForScope(Asset::SCOPE_FRONTEND),
            'enqueued_admin_asset_count' => $this->countEnqueuedForScope(Asset::SCOPE_ADMIN),
            // Source/warning observability (v1.0.0-beta.7.1.13.3) — COUNTS only.
            'asset_source_count' => array_sum($diagnostics['sources']),
            'asset_warning_count' => count($diagnostics['warnings']),
        ];
    }
}
