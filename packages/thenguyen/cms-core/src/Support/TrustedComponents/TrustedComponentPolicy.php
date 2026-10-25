<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

/**
 * What a trusted component allows (CORE-EXTENSION-TRUST S2). Snapshotted by the
 * registry at registration and validated there; invalid values reject the
 * whole registration.
 *
 * - contexts:       render modes it supports, a non-empty subset of
 *                   public | preview | editor.
 * - invokers:       code allowed to request it besides its owner: active
 *                   plugin slugs and/or `@host` (Core, theme or host-app code,
 *                   i.e. no plugin frame on the call stack).
 * - permission:     a permission slug the VIEWER must hold (strict check, never
 *                   fail-open); null = any visitor.
 * - dataAttributes: `data-*` attribute names its markup may carry.
 * - formTargets:    {@see TrustedFormTarget}s its forms may submit to (S2-R1).
 *                   Default deny: with none, any live form rejects the render.
 */
final readonly class TrustedComponentPolicy
{
    public const MODES = ['public', 'preview', 'editor'];

    public const HOST = '@host';

    /**
     * @param  list<string>  $contexts
     * @param  list<string>  $invokers
     * @param  list<string>  $dataAttributes
     * @param  list<TrustedFormTarget>  $formTargets
     */
    public function __construct(
        public array $contexts = ['public'],
        public array $invokers = [],
        public ?string $permission = null,
        public array $dataAttributes = [],
        public array $formTargets = [],
    ) {}
}
