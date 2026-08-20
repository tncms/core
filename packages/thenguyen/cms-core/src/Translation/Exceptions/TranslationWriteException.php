<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Exceptions;

/**
 * Raised when a write fails for a reason other than a uniqueness violation — a
 * connection error, a NOT NULL violation, or any other database failure. The
 * original {@see \Illuminate\Database\QueryException} is chained as the previous
 * exception; the driver never swallows it (Driver Standard §8).
 */
final class TranslationWriteException extends TranslationDriverException {}
