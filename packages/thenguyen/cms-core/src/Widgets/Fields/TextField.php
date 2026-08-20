<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** A single-line text field. */
class TextField extends WidgetField
{
    public function type(): string
    {
        return 'text';
    }
}
