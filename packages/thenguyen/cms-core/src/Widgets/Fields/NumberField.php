<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** An integer field, optionally clamped with min()/max(). */
class NumberField extends WidgetField
{
    protected mixed $default = 0;

    public function type(): string
    {
        return 'number';
    }
}
