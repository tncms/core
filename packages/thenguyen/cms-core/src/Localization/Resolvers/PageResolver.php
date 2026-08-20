<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

/** CORE-L10N.1B — the built-in Page localized-resource resolver. */
final class PageResolver extends AbstractContentResolver
{
    protected function type(): string
    {
        return 'page';
    }
}
