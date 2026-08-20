<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** A multi-line plain-text field. */
class TextareaField extends WidgetField
{
    public function type(): string
    {
        return 'textarea';
    }
}
