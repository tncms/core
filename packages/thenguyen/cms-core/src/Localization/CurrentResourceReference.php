<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use InvalidArgumentException;

/**
 * CORE-L10N.1B (Phase P3.3) — a typed, immutable, resource-agnostic reference to
 * the current frontend resource.
 *
 * It answers only WHAT the current resource is (its platform type, the backing
 * object, a stable identity, and the owning provider). It is deliberately
 * URL-free, locale-free, strategy-free, and SEO-free: it never carries a URL, a
 * localized slug, a route descriptor, HTML, SEO metadata, the request, the
 * container, a callback, or a plugin service. Every subsystem (language
 * switcher, SEO, canonical/hreflang, preview) reads the SAME reference instead
 * of reconstructing its own notion of "the current resource".
 *
 * Core resources (page/post/category/tag) and any plugin resource
 * ('ecommerce.product', 'knowledge.article', …) are represented uniformly — the
 * type is an opaque platform key a resolver matches on, so Core never needs a
 * per-resource conditional.
 */
final class CurrentResourceReference
{
    public function __construct(
        public readonly string $type,
        public readonly object $resource,
        public readonly string|int|null $identity = null,
        public readonly ?string $owner = null,
    ) {
        $type = trim($type);

        if ($type === '') {
            throw new InvalidArgumentException('A current resource reference requires a non-empty type.');
        }

        // A resource type is an opaque platform key, never a URL/path fragment.
        if (str_contains($type, '/') || str_contains($type, '://')) {
            throw new InvalidArgumentException("A current resource type must not be URL-shaped: {$type}.");
        }
    }

    /**
     * Reference a backing resource object under an opaque platform type.
     */
    public static function of(string $type, object $resource, string|int|null $identity = null, ?string $owner = null): self
    {
        return new self($type, $resource, $identity, $owner);
    }

    /**
     * The identity-free reference for the site root.
     */
    public static function home(): self
    {
        return new self('home', HomeResource::instance(), null, 'core');
    }

    public function isHome(): bool
    {
        return $this->type === 'home';
    }

    /**
     * Whether two references denote the same resource (type + identity + owner).
     * Used for idempotent writes and conflict detection — never compares the
     * backing object by value.
     */
    public function sameAs(self $other): bool
    {
        return $this->type === $other->type
            && $this->identity === $other->identity
            && $this->owner === $other->owner;
    }

    /**
     * Diagnostics-safe projection. Never exposes the backing object.
     *
     * @return array{type: string, identity: string|int|null, owner: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'identity' => $this->identity,
            'owner' => $this->owner,
        ];
    }
}
