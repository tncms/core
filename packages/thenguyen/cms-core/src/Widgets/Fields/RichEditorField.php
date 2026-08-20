<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets\Fields;

/**
 * A rich HTML field. Its stored value must always be passed through the CMS
 * HtmlSanitizer before output (the admin editor sanitises on save and widgets
 * sanitise on render).
 */
class RichEditorField extends WidgetField
{
    public function type(): string
    {
        return 'richeditor';
    }
}
