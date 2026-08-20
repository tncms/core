<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** A media reference (id or path). Global by default unless localized(). */
class MediaField extends WidgetField
{
    public function type(): string
    {
        return 'media';
    }
}
