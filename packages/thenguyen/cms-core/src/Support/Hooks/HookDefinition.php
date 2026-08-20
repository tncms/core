<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Hooks;

/**
 * Documentation for a single hook point (v1.0.0-beta.7.1.11.1).
 *
 * A HookDefinition describes — for plugin/theme authors and future Developer
 * Tools — what a named action or filter is for, what arguments its callbacks
 * receive, and where it came from. Definitions are purely descriptive: a hook
 * runs whether or not it has been defined, and a definition never registers a
 * callback.
 */
final class HookDefinition
{
    public const TYPE_ACTION = 'action';

    public const TYPE_FILTER = 'filter';

    /**
     * @param  array<int|string, mixed>  $arguments  Either a list of argument
     *                                                names, or name => type map.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $description = '',
        public readonly array $arguments = [],
        public readonly ?string $returnType = null,
        public readonly ?string $since = null,
        public readonly string $source = 'core',
        public readonly ?string $group = null,
    ) {
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public static function action(
        string $name,
        string $description = '',
        array $arguments = [],
        ?string $since = null,
        string $source = 'core',
        ?string $group = null,
    ): self {
        return new self($name, self::TYPE_ACTION, $description, $arguments, null, $since, $source, $group);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public static function filter(
        string $name,
        string $description = '',
        array $arguments = [],
        ?string $returnType = null,
        ?string $since = null,
        string $source = 'core',
        ?string $group = null,
    ): self {
        return new self($name, self::TYPE_FILTER, $description, $arguments, $returnType, $since, $source, $group);
    }

    public function isAction(): bool
    {
        return $this->type === self::TYPE_ACTION;
    }

    public function isFilter(): bool
    {
        return $this->type === self::TYPE_FILTER;
    }

    /**
     * Return a clone with the given fields overridden (last-wins redefinition).
     *
     * @param  array<string, mixed>  $meta
     */
    public function mergeMeta(array $meta): self
    {
        return new self(
            $this->name,
            is_string($meta['type'] ?? null) ? $meta['type'] : $this->type,
            is_string($meta['description'] ?? null) ? $meta['description'] : $this->description,
            is_array($meta['arguments'] ?? null) ? $meta['arguments'] : $this->arguments,
            array_key_exists('return_type', $meta) ? ($meta['return_type'] === null ? null : (string) $meta['return_type']) : $this->returnType,
            array_key_exists('since', $meta) ? ($meta['since'] === null ? null : (string) $meta['since']) : $this->since,
            is_string($meta['source'] ?? null) ? $meta['source'] : $this->source,
            array_key_exists('group', $meta) ? ($meta['group'] === null ? null : (string) $meta['group']) : $this->group,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'arguments' => $this->arguments,
            'return_type' => $this->returnType,
            'since' => $this->since,
            'source' => $this->source,
            'group' => $this->group,
        ];
    }
}
