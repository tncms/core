<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Scripts;

/**
 * Immutable descriptor for a single registered frontend script asset
 * (v1.0.0-beta.7.1.13 — Global Script Manager).
 *
 * The ScriptManager stores validated registrations as ScriptAsset value
 * objects. Payload shape depends on {@see $type}; the manager owns rendering.
 */
final class ScriptAsset
{
    public const TYPE_HEAD_INLINE = 'head_inline';

    public const TYPE_FOOTER_INLINE = 'footer_inline';

    public const TYPE_HEAD_EXTERNAL = 'head_external';

    public const TYPE_FOOTER_EXTERNAL = 'footer_external';

    public const TYPE_META = 'meta';

    public const TYPE_VERIFICATION = 'verification';

    public const TYPE_JSON_LD = 'json_ld';

    public const TYPE_EMBED = 'embed';

    public const POSITION_HEAD = 'head';

    public const POSITION_FOOTER = 'footer';

    /**
     * @param  string  $key       Unique key within the asset {@see $type}.
     * @param  string  $type      One of the TYPE_* constants.
     * @param  string  $position  POSITION_HEAD or POSITION_FOOTER.
     * @param  array<string, mixed>  $data  Type-specific validated payload.
     * @param  int  $priority     Lower renders first (default 10).
     * @param  int  $sequence     Registration order, breaks priority ties.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $position,
        public readonly array $data,
        public readonly int $priority = 10,
        public readonly int $sequence = 0,
    ) {}

    /**
     * Return a copy with a different registration sequence. Used when a
     * duplicate key replaces an earlier asset but must keep deterministic order.
     */
    public function withSequence(int $sequence): self
    {
        return new self($this->key, $this->type, $this->position, $this->data, $this->priority, $sequence);
    }
}
