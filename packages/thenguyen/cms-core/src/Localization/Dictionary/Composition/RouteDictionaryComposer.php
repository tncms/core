<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

use TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionaryCompositionConflictException;

/**
 * P6.2 — merges multiple {@see RouteDictionarySourceInterface} into ONE canonical raw payload,
 * at BUILD time (never per request). It produces data only — it creates no Runtime object and
 * performs no projection; the loader turns the payload into the immutable dictionary.
 *
 * Precedence model (Phase A/D): sources are applied in ascending priority (stable by id for equal
 * priority). For a given (key, locale):
 *   • a HIGHER-priority source OVERRIDES a lower one — an intentional, RECORDED override (never silent);
 *   • two EQUAL-priority sources with the SAME segment are idempotent;
 *   • two EQUAL-priority sources with DIFFERENT segments are a CONFLICT and fail closed.
 * Cross-key segment collisions (two keys → one locale+segment) are rejected downstream at
 * RouteSegmentDictionary construction (the frozen collision authority), not duplicated here.
 */
final class RouteDictionaryComposer
{
    /** @var array<int, RouteDictionarySourceInterface> */
    private array $sources = [];

    /** @var array<int, array{key: string, locale: string, from: string, to: string, source: string, overrode: string}> */
    private array $overrides = [];

    /**
     * @param  iterable<RouteDictionarySourceInterface>  $sources
     */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->add($source);
        }
    }

    public function add(RouteDictionarySourceInterface $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * The merged canonical payload (`key => [locale => segment]`), deterministic across runs.
     *
     * @return array<string, array<string, string>>
     */
    public function compose(): array
    {
        $ordered = $this->sources;
        usort($ordered, static fn (RouteDictionarySourceInterface $a, RouteDictionarySourceInterface $b): int => [$a->priority(), $a->id()] <=> [$b->priority(), $b->id()]);

        /** @var array<string, array<string, array{segment: string, priority: int, source: string}>> $seen */
        $seen = [];
        /** @var array<string, array<string, string>> $out */
        $out = [];
        $this->overrides = [];

        foreach ($ordered as $source) {
            foreach ($source->payload() as $key => $localeMap) {
                foreach ($localeMap as $locale => $segment) {
                    $key = (string) $key;
                    $locale = (string) $locale;
                    $segment = (string) $segment;
                    $existing = $seen[$key][$locale] ?? null;

                    if ($existing !== null) {
                        if ($existing['priority'] === $source->priority()) {
                            if ($existing['segment'] === $segment) {
                                continue; // idempotent
                            }

                            throw RouteDictionaryCompositionConflictException::segment(
                                $key, $locale, $existing['source'], $source->id(), $existing['segment'], $segment,
                            );
                        }

                        // Lower-priority existing → intentional override by the higher source (recorded).
                        $this->overrides[] = [
                            'key' => $key, 'locale' => $locale,
                            'from' => $existing['segment'], 'to' => $segment,
                            'source' => $source->id(), 'overrode' => $existing['source'],
                        ];
                    }

                    $seen[$key][$locale] = ['segment' => $segment, 'priority' => $source->priority(), 'source' => $source->id()];
                    $out[$key][$locale] = $segment;
                }
            }
        }

        return $out;
    }

    /**
     * Recorded overrides from the last {@see compose()} — a higher-priority source replacing a
     * lower one's (key, locale). Diagnostics only.
     *
     * @return array<int, array{key: string, locale: string, from: string, to: string, source: string, overrode: string}>
     */
    public function overrides(): array
    {
        return $this->overrides;
    }
}
