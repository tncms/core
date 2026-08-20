<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Account;

/**
 * Immutable value object for one account navigation entry
 * (v1.0.0-beta.7.1.15).
 *
 * Core items (Dashboard/Profile/Security/Sessions/Preferences) and plugin
 * items (Orders/Addresses/Wishlist/…) share this same shape, so the
 * cms.account.navigation_items filter can add, remove, and reorder uniformly.
 * "New object, never mutate": {@see withActive()} returns a fresh instance.
 */
final class AccountNavItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $icon = null,
        public readonly int $priority = 100,
        public readonly bool $active = false,
        public readonly ?string $permission = null,
        public readonly ?string $badge = null,
    ) {}

    /**
     * Build from a loose array (what plugins commonly return through the
     * navigation filter). Unknown keys are ignored; only `key`, `label`, and
     * `url` are required.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            url: (string) ($data['url'] ?? '#'),
            icon: isset($data['icon']) ? (string) $data['icon'] : null,
            priority: (int) ($data['priority'] ?? 100),
            active: (bool) ($data['active'] ?? false),
            permission: isset($data['permission']) ? (string) $data['permission'] : null,
            badge: isset($data['badge']) ? (string) $data['badge'] : null,
        );
    }

    /** Return a copy with the active flag set. */
    public function withActive(bool $active): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            url: $this->url,
            icon: $this->icon,
            priority: $this->priority,
            active: $active,
            permission: $this->permission,
            badge: $this->badge,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'priority' => $this->priority,
            'active' => $this->active,
            'permission' => $this->permission,
            'badge' => $this->badge,
        ];
    }
}
