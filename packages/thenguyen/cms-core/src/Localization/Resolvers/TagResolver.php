<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

/** CORE-L10N.1B — the built-in Tag localized-resource resolver. */
final class TagResolver extends AbstractTermResolver
{
    protected function type(): string
    {
        return 'tag';
    }
}
