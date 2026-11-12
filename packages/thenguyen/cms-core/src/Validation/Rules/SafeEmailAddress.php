<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Core's shared e-mail safety contract (R-INP-1, CORE-40-INSTALLER-INPUT-CLOSURE-R1).
 *
 * Used next to — never instead of — Laravel's `email` rule on every identity / mail address input.
 * It rejects ASCII control characters (U+0000–U+001F and U+007F: NUL, CR, LF, TAB, ESC, DEL, …),
 * which never belong in a deliverable address or a mail header. Laravel's `email` rule already
 * rejects all of them except a NUL inside the local part (`us\0er@example.test`), which Symfony
 * Mime then refuses only when a message is built — after the address was stored. Printable
 * Unicode (IDN domains, UTF-8 local parts) is untouched. Non-strings are left to the `string` rule.
 */
final class SafeEmailAddress implements ValidationRule
{
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::CONTROL_CHARACTERS, $value) === 1) {
            $fail('validation.email')->translate();
        }
    }
}
