<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use RuntimeException;

/** A refused ownership operation. The reason is a stable machine code. */
final class ContentOwnershipException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Content ownership operation refused: '.$reason);
    }
}
