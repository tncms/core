<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Admin\Support\LocalizedAdminEvents;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * Hydrates/dehydrates admin form state for a localized field (Phase 8.3).
 *
 * Hydration turns a stored {@see LocalizedField} into a deterministic
 * `locale => string` array ordered by the enabled locales — only locales the site
 * still enables appear, so a stale/disabled locale value never leaks into the
 * form. Dehydration turns the edited state back into a {@see LocalizedValue}:
 * present strings (including '') are kept, a null is a clear (omitted), and any
 * disabled/unknown locale is dropped. Round-tripping stored data is stable.
 *
 * Fires best-effort hydrating/hydrated and dehydrating/dehydrated events.
 */
final class LocalizedStateHydrator
{
    public function __construct(private readonly LocaleOptionsResolver $locales)
    {
    }

    /**
     * @return array<string, string> locale => value (present locales only)
     */
    public function hydrate(LocalizedField $field, ?LocaleOptions $locales = null): array
    {
        $locales ??= $this->locales->resolve();

        LocalizedAdminEvents::fire(LocalizedAdminEvents::HYDRATING, $field, $locales);

        $stored = $field->value->toArray();
        $state = [];

        foreach ($locales->codes() as $code) {
            if (array_key_exists($code, $stored)) {
                // Present (including '') is kept; absent stays missing.
                $state[$code] = (string) $stored[$code];
            }
        }

        LocalizedAdminEvents::fire(LocalizedAdminEvents::HYDRATED, $state, $locales);

        return $state;
    }

    /**
     * @param array<string, string|null> $state locale => value|null
     */
    public function dehydrate(array $state, ?LocaleOptions $locales = null): LocalizedValue
    {
        $locales ??= $this->locales->resolve();

        LocalizedAdminEvents::fire(LocalizedAdminEvents::DEHYDRATING, $state, $locales);

        $map = [];
        foreach ($state as $code => $value) {
            $code = (string) $code;

            if (! $locales->has($code)) {
                continue; // disabled/unknown locale — excluded
            }

            if ($value === null) {
                continue; // cleared — omit so it is removed on save
            }

            if (is_string($value)) {
                $map[$code] = $value; // authored string (including '')
            }
        }

        $value = new LocalizedValue($map);

        LocalizedAdminEvents::fire(LocalizedAdminEvents::DEHYDRATED, $value, $locales);

        return $value;
    }
}
