<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Minimal public ownership marker carried by card ViewModels: the owner slug
 * and its runtime state. No plugin settings, paths, versions or record ids.
 */
final class ContentOwnershipMarker
{
    public function __construct(
        public readonly string $owner,
        public readonly OwnerState $state,
    ) {}

    /** @return array{owner: string, state: string} */
    public function toArray(): array
    {
        return ['owner' => $this->owner, 'state' => $this->state->value];
    }
}
