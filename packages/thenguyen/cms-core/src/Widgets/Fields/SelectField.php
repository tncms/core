<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/** A single-choice dropdown. Pass choices via options(['value' => 'Label']). */
class SelectField extends WidgetField
{
    public function type(): string
    {
        return 'select';
    }
}
