<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N.1B (Phase P3.3) — the canonical marker for the site root.
 *
 * Home is a legitimate current-frontend "resource" that has no backing model.
 * Rather than weaken {@see CurrentResourceReference} with a nullable resource,
 * home is represented by this immutable, identity-free sentinel so the reference
 * contract stays "always a real object".
 */
final class HomeResource
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }
}
