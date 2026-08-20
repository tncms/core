<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Support;

use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * Composes a stable {@see TranslationKey} for one field of one record.
 *
 * A module owns many localized fields per record (title, slug, excerpt, content,
 * seo_title, seo_description, …). This helper maps a (namespace, record id, field)
 * triple onto a deterministic key — `namespace::{recordId}:{field}` — so every
 * module addresses stored values the same way. Field-agnostic: the storage layer
 * never needs to know which fields exist.
 *
 * Example: FieldAddress::key('content', 123, 'title') → content::123:title
 */
final class FieldAddress
{
    public static function key(string $namespace, string|int $recordId, string $field): TranslationKey
    {
        return new TranslationKey(
            $recordId.':'.$field,
            $namespace !== '' ? $namespace : null,
        );
    }
}
