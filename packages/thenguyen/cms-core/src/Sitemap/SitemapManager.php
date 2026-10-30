<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Content\Extension\CallSitePlugin;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use Throwable;

/**
 * The sitemap index and its chunked children (CORE-SITEMAP-R1), bound as
 * `cms.sitemap`.
 *
 * Each provider has a cached manifest of keyset chunk boundaries; children
 * are rendered live from bounded provider queries, so visibility is always
 * evaluated at serve time. Manifests are keyed by provider version and the
 * context fingerprint and rebuilt by one request at a time (stale served
 * meanwhile). See docs/core-sitemap/CORE-SITEMAP-R1-ADR.md.
 */
final class SitemapManager
{
    private const SCHEMA = 2;

    /** Owner decision (CORE-37): 2,000 URLs per child unless configured. */
    public const DEFAULT_CHUNK = 2000;

    /** Floor: smaller chunks multiply boundary queries and children without benefit. */
    public const MIN_CHUNK = 500;

    /** sitemaps.org: at most 50,000 sitemaps in one index. */
    private const PROTOCOL_MAX_SITEMAPS = 50000;

    private const PROTOCOL_MAX_URLS = 50000;

    private const PROTOCOL_MAX_BYTES = 52428800;

    private const TYPE = '/^[a-z][a-z0-9_]{0,31}$/';

    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private CallSitePlugin $callSite;

    public function __construct(
        private readonly SitemapProviderRegistry $registry,
        ExtensionIdentityResolver $identity,
    ) {
        $this->callSite = new CallSitePlugin($identity, [__FILE__]);
    }

    /**
     * False when the site as a whole is not indexable (the SeoManager policy
     * every page uses): index and children answer 404, robots.txt omits them.
     */
    public function enabled(): bool
    {
        return app('cms.seo')->siteIndexable();
    }

    /**
     * URLs per child: the `seo.sitemap_chunk_size` setting, else
     * `cms.sitemap.chunk_size`, validated by {@see normalizeChunkSize()}.
     */
    public function chunkSize(): int
    {
        return self::normalizeChunkSize(settings('seo.sitemap_chunk_size') ?? config('cms.sitemap.chunk_size', self::DEFAULT_CHUNK));
    }

    /**
     * Integers (or digit strings) are clamped to MIN_CHUNK..50,000; anything
     * else (empty, negative, fractional, text) falls back to the default.
     */
    public static function normalizeChunkSize(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
            $value = preg_match('/^[0-9]{1,9}$/', $value) === 1 ? (int) $value : null;
        }

        if (! is_int($value) || $value < 0) {
            return self::DEFAULT_CHUNK;
        }

        return max(self::MIN_CHUNK, min(self::PROTOCOL_MAX_URLS, $value));
    }

    /** @return list<string> public locales, default first-class order as configured */
    public static function locales(): array
    {
        $language = app('cms.language');
        $codes = array_values(array_map('strval', $language->getPublicLocales()));

        return $codes !== [] ? $codes : [$language->defaultCode()];
    }

    /** @return array<string, SitemapProvider> provider id => provider; Core first, then plugins by slug */
    public function providers(): array
    {
        return [SitemapProviderRegistry::CORE => app(CoreSitemapProvider::class)] + $this->registry->active();
    }

    /**
     * Every child sitemap of the index, in deterministic order.
     *
     * @return list<array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}>
     */
    public function parts(): array
    {
        $parts = [];
        $names = [];

        foreach ($this->providers() as $id => $provider) {
            foreach ($this->manifest($id, $provider) as $part) {
                if (count($parts) >= self::PROTOCOL_MAX_SITEMAPS) {
                    Log::warning('TN CMS sitemap index full', ['provider' => $id, 'max' => self::PROTOCOL_MAX_SITEMAPS]);

                    return $parts;
                }

                if (isset($names[$part['name']])) {
                    Log::warning('TN CMS sitemap part name collision', ['provider' => $id, 'name' => $part['name']]);

                    continue;
                }

                $names[$part['name']] = true;
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /** @return array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}|null */
    public function part(string $name): ?array
    {
        foreach ($this->parts() as $part) {
            if ($part['name'] === $name) {
                return $part;
            }
        }

        return null;
    }

    public function indexXml(): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="'.self::XMLNS.'">'];

        foreach ($this->parts() as $part) {
            $lines[] = '  <sitemap><loc>'.self::escape(route('cms.sitemap.chunk', ['name' => $part['name']])).'</loc></sitemap>';
        }

        $lines[] = '</sitemapindex>';

        return implode("\n", $lines)."\n";
    }

    /**
     * Render one child into a temp stream (bounded memory). A part listed in
     * the index always renders (an empty urlset when nothing qualifies right
     * now), so the index never references a 404. Null only when the provider
     * is gone. Throws {@see SitemapUnavailableException} when it fails.
     *
     * @param  array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}  $part
     * @return resource|null
     */
    public function chunkStream(array $part)
    {
        $provider = $this->providers()[$part['provider']] ?? null;

        if ($provider === null) {
            return null;
        }

        $owner = $part['provider'] === SitemapProviderRegistry::CORE ? null : $part['provider'];
        $validator = $this->validator();
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        fwrite($stream, '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="'.self::XMLNS.'" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n");

        try {
            // One read scope per chunk: URL generation reads the same settings
            // (permalink bases, locale prefix) for every entry.
            $count = settings()->withinReadScope(fn (): int => $this->writeEntries($provider, $part, $validator, $owner, $stream));
        } catch (Throwable $e) {
            fclose($stream);
            Log::warning('TN CMS sitemap provider failed', ['provider' => $part['provider'], 'exception' => $e::class]);

            throw new SitemapUnavailableException;
        }

        fwrite($stream, "</urlset>\n");
        rewind($stream);

        return $stream;
    }

    /**
     * @param  array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}  $part
     * @param  resource  $stream
     */
    private function writeEntries(SitemapProvider $provider, array $part, SitemapUrlValidator $validator, ?string $owner, $stream): int
    {
        $count = 0;
        $read = 0;
        $seen = [];
        // The limit recorded when the part was cut, so a last-good manifest
        // served across a chunk-size change still renders whole parts.
        $limit = (int) ($part['limit'] ?? min(2 * $this->chunkSize(), self::PROTOCOL_MAX_URLS));

        /** @var iterable<mixed> $entries provider output is untrusted */
        $entries = $provider->entries($part['type'], $part['locale'], $part['after'], $part['until'], $limit);

        foreach ($entries as $entry) {
            // Bounded by output size and by entries read, valid or not.
            if ($count >= $limit || ++$read > 4 * $limit || ftell($stream) > self::PROTOCOL_MAX_BYTES - 65536) {
                break;
            }

            $hash = $entry instanceof SitemapEntry ? md5($entry->loc, true) : '';

            if ($hash === '' || isset($seen[$hash]) || ! $validator->valid($entry->loc, $part['locale'], $owner)) {
                continue;
            }

            $seen[$hash] = true;
            fwrite($stream, $this->urlNode($entry, $validator, $owner));
            $count++;
        }

        return $count;
    }

    /**
     * Drop the cached manifest of the CALLING plugin (call-site derived; a
     * plugin cannot invalidate another). Outside plugin code it is Core's.
     */
    public function invalidate(): void
    {
        $caller = $this->callSite->resolve();

        if ($caller === '') {
            return;
        }

        $this->bump($caller === false ? SitemapProviderRegistry::CORE : $caller);
    }

    /** @internal Core write paths (content, terms, ownership). */
    public function invalidateCore(): void
    {
        $this->bump(SitemapProviderRegistry::CORE);
    }

    private function validator(): SitemapUrlValidator
    {
        $language = app('cms.language');

        return new SitemapUrlValidator(
            app('router'),
            url('/'),
            self::locales(),
            $language->defaultCode(),
            $language->shouldPrefixDefaultLocale(),
        );
    }

    private function urlNode(SitemapEntry $entry, SitemapUrlValidator $validator, ?string $owner): string
    {
        $node = "  <url>\n    <loc>".self::escape($entry->loc)."</loc>\n";

        if ($validator->validLastmod($entry->lastmod)) {
            $node .= '    <lastmod>'.$entry->lastmod->format(DATE_ATOM)."</lastmod>\n";
        }

        $default = app('cms.language')->defaultCode();
        $alternates = [];
        /** @var array<mixed, mixed> $declared provider output is untrusted */
        $declared = $entry->alternates;
        foreach ($declared as $locale => $href) {
            $check = $locale === 'x-default' ? $default : $locale;
            if (is_string($locale) && is_string($href) && $validator->valid($href, $check, $owner)) {
                $alternates[$locale] = $href;
            }
        }

        if (count(array_diff_key($alternates, ['x-default' => true])) > 1) {
            foreach ($alternates as $locale => $href) {
                $node .= '    <xhtml:link rel="alternate" hreflang="'.self::escape($locale).'" href="'.self::escape($href)."\"/>\n";
            }
        }

        return $node."  </url>\n";
    }

    /**
     * @return list<array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}>
     */
    private function manifest(string $id, SitemapProvider $provider): array
    {
        $store = Cache::store();
        $key = 'cms.sitemap.manifest.'.sha1((string) json_encode([
            self::SCHEMA, $id, $provider::class, $this->version($id), $this->fingerprint($id),
        ]));
        $lastKey = 'cms.sitemap.last.'.$id;
        $cached = $store->get($key);

        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            return $cached['parts'];
        }

        $lock = Cache::lock('cms.sitemap.build.'.$id, 120);

        if ($lock->get()) {
            try {
                $parts = $this->rebuild($id, $provider, $key, $lastKey) ?? ($cached['parts'] ?? $store->get($lastKey));
            } finally {
                $lock->release();
            }

            return $this->usable($id, $parts);
        }

        // Another request is rebuilding: serve the expired or last-good manifest.
        $stale = is_array($cached) ? $cached['parts'] : $store->get($lastKey);

        if (is_array($stale)) {
            return $stale;
        }

        try {
            $lock->block(10);
            $lock->release();
        } catch (LockTimeoutException) {
            throw new SitemapUnavailableException;
        }

        $fresh = $store->get($key);

        return $this->usable($id, is_array($fresh) ? $fresh['parts'] : $store->get($lastKey));
    }

    /**
     * No manifest at all: a plugin is omitted for now, but Core's absence
     * would publish an empty index, so that is a 503 instead.
     *
     * @return list<array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}>
     */
    private function usable(string $id, mixed $parts): array
    {
        if (is_array($parts)) {
            return $parts;
        }

        if ($id === SitemapProviderRegistry::CORE) {
            throw new SitemapUnavailableException;
        }

        return [];
    }

    /** @return list<array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}>|null */
    private function rebuild(string $id, SitemapProvider $provider, string $key, string $lastKey): ?array
    {
        try {
            [$parts, $degraded] = $this->build($id, $provider);
        } catch (Throwable $e) {
            Log::warning('TN CMS sitemap manifest failed', ['provider' => $id, 'exception' => $e::class]);

            return null;
        }

        // A degraded Core build (e.g. a database blip) must not replace a good
        // index: serve last-good, or 503 when there is none.
        if ($degraded && $id === SitemapProviderRegistry::CORE) {
            return null;
        }

        $ttl = $degraded ? 60 : max(60, (int) config('cms.sitemap.manifest_ttl', 3600));
        $store = Cache::store();
        $store->put($key, ['expires' => time() + $ttl, 'parts' => $parts], $ttl * 2);

        if (! $degraded) {
            $store->forever($lastKey, $parts);
        }

        return $parts;
    }

    /**
     * Each (type, locale) set is isolated: a set whose sample is invalid or
     * whose provider calls throw is omitted, and the manifest is then marked
     * degraded so it is cached briefly instead of for the full TTL.
     *
     * @return array{0: list<array{name: string, provider: string, type: string, locale: string, after: int|null, until: int|null, limit?: int}>, 1: bool}
     */
    private function build(string $id, SitemapProvider $provider): array
    {
        /** @var array<mixed> $declared provider output is untrusted */
        $declared = $provider->types();
        $types = array_values(array_unique(array_filter($declared, static fn ($t) => is_string($t) && preg_match(self::TYPE, $t) === 1)));
        sort($types, SORT_STRING);
        $parts = [];
        $degraded = false;
        $limit = min(2 * $this->chunkSize(), self::PROTOCOL_MAX_URLS);

        foreach ($types as $type) {
            foreach (self::locales() as $locale) {
                try {
                    $ranges = $this->ranges($id, $provider, $type, $locale);
                } catch (Throwable $e) {
                    Log::warning('TN CMS sitemap set failed', ['provider' => $id, 'type' => $type, 'locale' => $locale, 'exception' => $e::class]);
                    $degraded = true;

                    continue;
                }

                foreach ($ranges as $i => [$from, $to]) {
                    $parts[] = [
                        'name' => $id.'-'.$type.'-'.$locale.'-'.($i + 1),
                        'provider' => $id, 'type' => $type, 'locale' => $locale, 'after' => $from, 'until' => $to, 'limit' => $limit,
                    ];
                }
            }
        }

        return [$parts, $degraded];
    }

    /**
     * Keyset boundaries of one set: N/chunk bounded keyAt() calls; the last
     * range is open-ended so later inserts are served until the next rebuild.
     *
     * @return list<array{0: int|null, 1: int|null}>
     */
    private function ranges(string $id, SitemapProvider $provider, string $type, string $locale): array
    {
        if ($provider->keyAt($type, $locale, null, 0) === null
            || ($id !== SitemapProviderRegistry::CORE && ! $this->sampleValid($id, $provider, $type, $locale))) {
            return [];
        }

        $chunk = $this->chunkSize();
        $after = null;
        $ranges = [];

        while (true) {
            $until = $provider->keyAt($type, $locale, $after, $chunk - 1);

            if ($until === null) {
                if ($after === null || $provider->keyAt($type, $locale, $after, 0) !== null) {
                    $ranges[] = [$after, null];
                } else {
                    $ranges[array_key_last($ranges)][1] = null;
                }

                return $ranges;
            }

            if (($after !== null && $until <= $after) || count($ranges) >= self::PROTOCOL_MAX_SITEMAPS) {
                throw new SitemapUnavailableException('provider keys are not ascending or exceed the part limit');
            }

            $ranges[] = [$after, $until];
            $after = $until;
        }
    }

    /** The first entry of a plugin set must validate, or the set is omitted (no phantom parts). */
    private function sampleValid(string $id, SitemapProvider $provider, string $type, string $locale): bool
    {
        /** @var iterable<mixed> $sample provider output is untrusted */
        $sample = $provider->entries($type, $locale, null, null, 1);

        foreach ($sample as $entry) {
            if ($entry instanceof SitemapEntry && $this->validator()->valid($entry->loc, $locale, $id)) {
                return true;
            }

            break;
        }

        Log::warning('TN CMS sitemap set omitted (invalid sample)', ['provider' => $id, 'type' => $type, 'locale' => $locale]);

        return false;
    }

    private function fingerprint(string $id): string
    {
        $language = app('cms.language');

        // No host or URL here: manifests hold keys only, and a request-controlled
        // Host must not mint new cache entries.
        return sha1((string) json_encode([
            self::locales(),
            $language->defaultCode(),
            $language->shouldPrefixDefaultLocale(),
            $this->chunkSize(),
            $id === SitemapProviderRegistry::CORE ? app('cms.content_ownership')->activeOwnerSlugs() : null,
        ]));
    }

    private function version(string $id): string
    {
        $key = 'cms.sitemap.version.'.$id;
        $version = Cache::store()->get($key);

        if (! is_string($version) || $version === '') {
            // Atomic first write: concurrent cold requests must agree on one
            // version, or a waiter would look for a manifest nobody builds.
            Cache::store()->add($key, bin2hex(random_bytes(8)));
            $version = (string) Cache::store()->get($key);
        }

        return $version;
    }

    private function bump(string $id): void
    {
        try {
            Cache::store()->forever('cms.sitemap.version.'.$id, bin2hex(random_bytes(8)));
        } catch (Throwable $e) {
            Log::warning('TN CMS sitemap invalidation failed', ['provider' => $id, 'exception' => $e::class]);
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
