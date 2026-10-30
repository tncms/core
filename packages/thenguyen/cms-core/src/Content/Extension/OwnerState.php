<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/** Runtime state of a content owner plugin, derived per call (never stored). */
enum OwnerState: string
{
    case Active = 'active';       // installed and active
    case Inactive = 'inactive';   // installed, not active
    case Missing = 'missing';     // no longer installed
}
