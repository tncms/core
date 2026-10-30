<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * The sanctioned Core content write operations a {@see ContentWriteIntent} can
 * describe (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 */
enum ContentOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Status = 'status';
    case Delete = 'delete';
    case Claim = 'claim';
    case Detach = 'detach';
    case Transfer = 'transfer';
}
