<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Exceptions;

use RuntimeException;

/**
 * CORE-L10N.1B — thrown when a localization strategy is misconfigured: a duplicate strategy
 * key is registered, or the active `language.routing_strategy` names a strategy that is not
 * registered. The platform fails closed (an exception) rather than silently picking a
 * strategy.
 */
final class LocalizationStrategyException extends RuntimeException
{
    public static function duplicateKey(string $key): self
    {
        return new self("A localization strategy with key [{$key}] is already registered.");
    }

    public static function unknown(string $key): self
    {
        return new self("Unknown localization strategy [{$key}]. The active routing strategy is not registered.");
    }
}
