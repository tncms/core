<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

/** CORE-L10N.1B — the built-in Category localized-resource resolver. */
final class CategoryResolver extends AbstractTermResolver
{
    protected function type(): string
    {
        return 'category';
    }
}
