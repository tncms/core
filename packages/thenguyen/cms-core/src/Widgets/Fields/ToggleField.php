<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** A boolean on/off toggle. */
class ToggleField extends WidgetField
{
    protected mixed $default = false;

    public function type(): string
    {
        return 'toggle';
    }
}
