<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Robots directives an owner may ADD. Core merges them per directive with its
 * own policy, so an override can only make a page more restrictive.
 */
enum RobotsRestriction: string
{
    case NoIndex = 'noindex';
    case NoFollow = 'nofollow';
    case NoIndexNoFollow = 'noindex,nofollow';

    public function noindex(): bool
    {
        return $this !== self::NoFollow;
    }

    public function nofollow(): bool
    {
        return $this !== self::NoIndex;
    }
}
