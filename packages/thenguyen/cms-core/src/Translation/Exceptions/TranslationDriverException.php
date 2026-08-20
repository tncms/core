<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Exceptions;

use RuntimeException;

/**
 * Base type for every fault a translation driver raises. Drivers throw typed
 * exceptions rather than swallowing failures (Driver Standard §6.3/§8): a
 * constraint violation, a duplicate row, or a database error must surface, never
 * be silently dropped.
 */
class TranslationDriverException extends RuntimeException {}
