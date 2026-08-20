<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Contracts;

use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;

/**
 * CORE-L10N.1B — the single resource abstraction of the localization platform.
 *
 * A resolver provides localization FACTS for one kind of resource. Core resources (page, post,
 * category, tag, home) register resolvers exactly like plugins do — there is no second resolver
 * system. A resolver must NEVER represent policy, generate a URL, mutate the locale, or reach
 * into request()/global state: it receives an explicit {@see LocalizationContext} and returns a
 * {@see RouteDescriptor} (route facts) for a target locale, or null when no translation exists.
 */
interface LocalizedResourceResolverContract
{
    /** The unique resolver key (also the registry key), e.g. 'cms.post'. */
    public function key(): string;

    /**
     * The selection priority. Higher wins when more than one resolver supports a context, so
     * selection is deterministic.
     */
    public function priority(): int;

    /** Whether this resolver handles the given context. */
    public function supports(LocalizationContext $context): bool;

    /**
     * The route facts for $context under $locale — a translated-slug-aware {@see RouteDescriptor}
     * — or null when this locale has no eligible translation (the caller decides the fallback).
     */
    public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor;
}
