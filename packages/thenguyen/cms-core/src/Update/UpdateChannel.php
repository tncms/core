<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Release channel (CORE-UPGRADE-2).
 *
 * The site opts in to exactly ONE channel, chosen by the operator through
 * configuration (never by the remote feed — remote data cannot switch a site's
 * channel, §discovery). This is a pure value object: it owns the channel
 * vocabulary and nothing else.
 */
final class UpdateChannel
{
    public const STABLE = 'stable';

    public const BETA = 'beta';

    public const DEVELOPMENT = 'development';

    /** All channels, most-conservative first. */
    public const ALL = [self::STABLE, self::BETA, self::DEVELOPMENT];

    public function __construct(public readonly string $name)
    {
        if (! self::isKnown($name)) {
            throw UpdateException::of('channel_unknown', "Unknown update channel: {$name}.");
        }
    }

    /** Resolve an operator-supplied channel, falling back to the safe default. */
    public static function fromConfig(?string $name): self
    {
        $name = strtolower(trim((string) $name));

        return new self(self::isKnown($name) ? $name : self::STABLE);
    }

    public static function isKnown(string $name): bool
    {
        return \in_array($name, self::ALL, true);
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
