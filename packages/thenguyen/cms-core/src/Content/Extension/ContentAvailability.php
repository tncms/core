<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * The owner's persisted availability state for a managed content item
 * (CORE-CONTENT-EXT-R1). Core maps it to an HTTP status; owners never choose
 * status codes or headers.
 */
enum ContentAvailability: string
{
    case Available = 'available';                             // 200
    case Unavailable = 'unavailable';                         // 404
    case Gone = 'gone';                                       // 410 when content.allow_gone, else 404
    case TemporarilyUnavailable = 'temporarily_unavailable';  // 503 + Retry-After
}
