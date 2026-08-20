<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The ordered set of admin locales (Phase 8.3): default first, then the rest in
 * registry order. Immutable. Supports any number of locales — the components are
 * driven entirely by this collection, never by a hardcoded list.
 *
 * @implements IteratorAggregate<int, LocaleOption>
 */
final class LocaleOptions implements Countable, IteratorAggregate
{
    /** @var array<int, LocaleOption> */
    private readonly array $options;

    /** @param array<int, LocaleOption> $options */
    public function __construct(array $options)
    {
        $this->options = array_values($options);
    }

    /** @return array<int, LocaleOption> */
    public function all(): array
    {
        return $this->options;
    }

    /** @return array<int, string> */
    public function codes(): array
    {
        return array_map(static fn (LocaleOption $o): string => $o->code, $this->options);
    }

    /** @return array<string, string> code => label */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->options as $option) {
            $labels[$option->code] = $option->label;
        }

        return $labels;
    }

    public function get(string $code): ?LocaleOption
    {
        foreach ($this->options as $option) {
            if ($option->code === $code) {
                return $option;
            }
        }

        return null;
    }

    public function has(string $code): bool
    {
        return $this->get($code) !== null;
    }

    public function labelFor(string $code): string
    {
        return $this->get($code)?->label ?? $code;
    }

    /** The default locale option (or the first, or null when empty). */
    public function default(): ?LocaleOption
    {
        foreach ($this->options as $option) {
            if ($option->isDefault) {
                return $option;
            }
        }

        return $this->options[0] ?? null;
    }

    public function defaultCode(): ?string
    {
        return $this->default()?->code;
    }

    public function fallback(): ?LocaleOption
    {
        foreach ($this->options as $option) {
            if ($option->isFallback) {
                return $option;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    /** Whether the locale count exceeds a UI overflow threshold. */
    public function isOverflow(int $threshold): bool
    {
        return count($this->options) > $threshold;
    }

    public function count(): int
    {
        return count($this->options);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->options);
    }
}
