<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/**
 * A repeating group of sub-fields. The stored value is a list of rows, each row
 * a map of sub-field key => value. Declare the row shape with fields([...]).
 *
 *   RepeaterField::make('links')->fields([
 *       TextField::make('label'),
 *       TextField::make('url'),
 *   ])
 */
class RepeaterField extends WidgetField
{
    protected mixed $default = [];

    public function type(): string
    {
        return 'repeater';
    }
}
