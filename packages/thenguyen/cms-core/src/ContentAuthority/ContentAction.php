<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * The content actions an extension can ask Core to authorise
 * (CORE-CONTENT-ACTION-AUTHORIZATION-R1). Closed set: an unknown action string
 * is refused with {@see ContentActionReason::UnsupportedAction}.
 */
enum ContentAction: string
{
    case View = 'view';
    case Edit = 'edit';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case CreateTranslation = 'create_translation';
    case EditTranslation = 'edit_translation';
}
