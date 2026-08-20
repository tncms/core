<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Exceptions;

/**
 * Raised when a write would violate a per-locale uniqueness guarantee — a second
 * row for the same `(entity, locale)`, or a colliding `(locale, slug)`. The
 * driver relies on the store's unique index as the hard guard (Driver Standard
 * §6.2) and translates the constraint violation into this typed error.
 */
final class DuplicateTranslationException extends TranslationDriverException {}
