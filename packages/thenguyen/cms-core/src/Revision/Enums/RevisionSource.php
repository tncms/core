<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Enums;

/**
 * Where a revision came from. Recorded on the immutable row so history explains
 * how each snapshot was produced. `System` is the neutral default for
 * programmatic recording with no specific origin.
 */
enum RevisionSource: string
{
    case Admin = 'admin';
    case Api = 'api';
    case Import = 'import';
    case Restore = 'restore';
    case Cli = 'cli';
    case System = 'system';
}
