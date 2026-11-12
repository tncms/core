<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

/** A media lookup request outside the documented bounds (CORE-P12-1). Carries a reason code only. */
final class InvalidMediaLookup extends \InvalidArgumentException
{
    public const REASON_TOO_MANY_IDS = 'too_many_ids';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid media lookup: '.$reason);
    }
}
