<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

use Normalizer;
use TheNguyen\CMS\Localization\Dictionary\LocalizedRouteSegment;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

/**
 * Core-owned validation of plugin route-segment sources before composition
 * (CORE-LOTTERY-P05-SEAMS-1 Gate D). Pure: no I/O, no state.
 *
 * Each {@see PluginRouteDictionarySource} is checked against the Core base
 * payload, the active locales and every other plugin source. A source that
 * fails ANY rule is refused as a whole; Core sources (store, host) are trusted
 * and pass through. Refusal is order-independent: when two plugin sources
 * collide, BOTH are refused (never last-writer-wins), so Core and every
 * uninvolved plugin keep working.
 *
 * Missing-locale policy (CORE-PLATFORM-SEAMS-R2), per key and active locale:
 *   1. an explicit segment for the locale is used;
 *   2. the DEFAULT locale without one uses the route key — the existing Core
 *      contract (the default locale renders the canonical key); it is validated
 *      like any segment, so a key that is not a valid public segment is refused;
 *   3. a non-default locale without one uses the key's default-locale segment
 *      ONLY when the source opted in (fallbackToDefaultSegment); the value is
 *      materialized into the composed dictionary, validated and collision-checked
 *      for that locale, and reported in `fallbacks`;
 *   4. otherwise the whole source is refused (`missing_locale:{key}:{locale}`).
 * The route key itself is never emitted for a non-default locale implicitly.
 *
 * Segment rules: non-empty, ≤ 100 chars, no surrounding whitespace, no control
 * or format characters, no `/ \ % . ? #`, NFKC-stable (rejects decomposed and
 * compatibility forms), lowercase, LocalizedRouteSegment pattern, and not a
 * reserved prefix (an active locale code, the admin path, or a Core path).
 * Collisions compare NFKC-casefolded segments per locale.
 */
final class PluginRouteSourcePolicy
{
    public const MAX_SEGMENT = 100;

    /** First-path segments Core or the framework owns. */
    public const RESERVED_SEGMENTS = [
        'admin', 'api', 'install', 'upgrade', 'livewire', 'filament', 'storage', 'vendor', 'themes', 'plugins',
        'uploads', 'media', 'account', 'login', 'logout', 'register', 'password', 'email', 'search', 'preview',
        'cms-health', 'up', 'sitemap', 'robots',
    ];

    /**
     * @param  array<string, array<string, string>>  $base  Core (trusted) composed payload
     * @param  list<RouteDictionarySourceInterface>  $sources
     * @param  list<string>  $locales  active public locale codes
     * @return array{accepted: list<RouteDictionarySourceInterface>, refused: array<string, list<string>>, fallbacks: list<array{source: string, key: string, locale: string}>}
     */
    public function evaluate(array $base, array $sources, array $locales, string $defaultLocale, string $adminPath = 'admin'): array
    {
        $locales = array_values(array_unique([$defaultLocale, ...$locales]));
        $reserved = array_unique([...self::RESERVED_SEGMENTS, ...$locales, strtolower(trim($adminPath, '/'))]);

        $claims = []; // locale\0folded => ['owner' => string, 'key' => string]
        foreach ($base as $key => $map) {
            foreach ($locales as $locale) {
                $segment = isset($map[$locale]) ? (string) $map[$locale] : (string) $key;
                $claims[$locale."\0".$this->fold($segment)] = ['owner' => 'core', 'key' => (string) $key];
            }
        }

        $refused = [];
        $fallbacks = [];
        $materialized = []; // sourceId => key => locale => segment (opted-in fallbacks)
        $pluginClaims = []; // locale\0folded => list<[sourceId, key]>
        $pluginKeys = [];   // key => list<[sourceId, signature]>

        foreach ($sources as $source) {
            if (! $source instanceof PluginRouteDictionarySource) {
                continue;
            }

            $id = $source->id();
            $reasons = [];

            foreach ($this->untrusted($source->payload()) as $key => $map) {
                $key = (string) $key;

                try {
                    RouteKey::of($key);
                } catch (\Throwable) {
                    $reasons[] = "invalid_key:{$key}";

                    continue;
                }

                if (array_key_exists($key, $base)) {
                    $reasons[] = "core_key:{$key}";
                }

                if (! is_array($map)) {
                    $reasons[] = "invalid_map:{$key}";

                    continue;
                }

                foreach (array_keys($map) as $locale) {
                    if (! is_string($locale) || preg_match('/^[a-z]{2,3}(?:[_-][a-z0-9]{2,8})?$/', $locale) !== 1) {
                        $reasons[] = "invalid_locale:{$key}";
                    }
                }

                $signature = [];
                $defaultSegment = array_key_exists($defaultLocale, $map) ? $map[$defaultLocale] : $key;

                foreach ($locales as $locale) {
                    if (array_key_exists($locale, $map)) {
                        $segment = $map[$locale];
                    } elseif ($locale === $defaultLocale) {
                        $segment = $key;
                    } elseif ($source->fallsBackToDefaultSegment()) {
                        $segment = $defaultSegment;
                        $fallbacks[] = ['source' => $id, 'key' => $key, 'locale' => $locale];
                        $materialized[$id][$key][$locale] = $defaultSegment;
                    } else {
                        $reasons[] = "missing_locale:{$key}:{$locale}";

                        continue;
                    }

                    $problem = $this->segmentProblem($segment, $reserved);

                    if ($problem !== null) {
                        $reasons[] = "{$problem}:{$key}:{$locale}";

                        continue;
                    }

                    $claim = $locale."\0".$this->fold((string) $segment);
                    $signature[$locale] = (string) $segment;

                    if (isset($claims[$claim])) {
                        $reasons[] = "core_collision:{$key}:{$locale}";
                    }

                    $pluginClaims[$claim][] = [$id, $key];
                }

                $pluginKeys[$key][] = [$id, $signature];
            }

            if ($reasons !== []) {
                $refused[$id] = array_values(array_unique($reasons));
            }
        }

        // Plugin ↔ plugin (and within one plugin): one locale segment, two keys;
        // or one key defined differently by two sources. All parties refused.
        foreach ($pluginClaims as $claim => $owners) {
            if (count(array_unique(array_column($owners, 1))) > 1) {
                foreach ($owners as [$id]) {
                    $refused[$id][] = 'segment_collision:'.str_replace("\0", ':', $claim);
                }
            }
        }

        foreach ($pluginKeys as $key => $definitions) {
            if (count($definitions) > 1 && count(array_unique(array_map(static fn ($d) => serialize($d[1]), $definitions))) > 1) {
                foreach ($definitions as [$id]) {
                    $refused[$id][] = "key_conflict:{$key}";
                }
            }
        }

        $refused = array_map(static fn (array $r): array => array_values(array_unique($r)), $refused);
        ksort($refused);

        $accepted = [];

        foreach ($sources as $source) {
            if (isset($refused[$source->id()])) {
                continue;
            }

            // Opted-in fallbacks become explicit entries, so inbound matching and URL
            // generation read the same materialized segment (no implicit projection).
            if ($source instanceof PluginRouteDictionarySource && isset($materialized[$source->id()])) {
                $payload = $source->payload();

                foreach ($materialized[$source->id()] as $key => $perLocale) {
                    $payload[$key] = $perLocale + $payload[$key];
                }

                $source = $source->withPayload($payload);
            }

            $accepted[] = $source;
        }

        $fallbacks = array_values(array_filter($fallbacks, static fn (array $f): bool => ! isset($refused[$f['source']])));

        return ['accepted' => $accepted, 'refused' => $refused, 'fallbacks' => $fallbacks];
    }

    /**
     * A plugin payload is runtime input: its documented shape is not enforced
     * by the source's constructor, so every level is checked here.
     *
     * @param  array<mixed, mixed>  $payload
     * @return array<mixed, mixed>
     */
    private function untrusted(array $payload): array
    {
        return $payload;
    }

    /** @param  list<string>  $reserved */
    private function segmentProblem(mixed $segment, array $reserved): ?string
    {
        if (! is_string($segment) || $segment === '') {
            return 'empty_segment';
        }

        if (trim($segment) !== $segment) {
            return 'whitespace';
        }

        if (preg_match('/[\p{C}]/u', $segment) === 1) {
            return 'control_character';
        }

        if (preg_match('#[/\\\\%.?\#]#', $segment) === 1) {
            return 'separator';
        }

        if (! Normalizer::isNormalized($segment, Normalizer::FORM_KC)) {
            return 'unnormalized_unicode';
        }

        if (mb_strtolower($segment, 'UTF-8') !== $segment) {
            return 'uppercase';
        }

        if (mb_strlen($segment, 'UTF-8') > self::MAX_SEGMENT) {
            return 'too_long';
        }

        try {
            LocalizedRouteSegment::of($segment);
        } catch (\Throwable) {
            return 'invalid_segment';
        }

        if (in_array($this->fold($segment), $reserved, true)) {
            return 'reserved_segment';
        }

        return null;
    }

    private function fold(string $segment): string
    {
        $normalized = Normalizer::normalize($segment, Normalizer::FORM_KC);

        return mb_strtolower(is_string($normalized) ? $normalized : $segment, 'UTF-8');
    }
}
