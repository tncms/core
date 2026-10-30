<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

use InvalidArgumentException;

/**
 * Deterministic refusal from the plugin view contract
 * (CORE-LOTTERY-P05-SEAMS-1 Gate C). `reason` is a stable code:
 * invalid_plugin, invalid_view, inactive_plugin, not_found.
 */
final class PluginViewException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $plugin, string $view)
    {
        parent::__construct(sprintf('Plugin view refused (%s): [%s] [%s]', $reason, self::printable($plugin), self::printable($view)));
    }

    private static function printable(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 120);
    }
}
