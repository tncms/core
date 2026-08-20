<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

/** CORE-L10N.1B — the built-in Post localized-resource resolver. */
final class PostResolver extends AbstractContentResolver
{
    protected function type(): string
    {
        return 'post';
    }
}
