<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

/**
 * The immutable, safe result of a mail diagnostic (Program Core Mail · Phase CORE-MAIL-1 ·
 * ADR-CORE-MAIL-002).
 *
 * A connection test or a test-email attempt reduces to this: success, a short human summary,
 * and whether the check was even applicable (e.g. a connection test against a non-SMTP
 * transport). It NEVER carries a credential, a raw transport exception or a stack trace — the
 * summary is a fixed, safe string produced by {@see MailConnectionTester}.
 */
final class MailTestResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $summary,
        public readonly bool $applicable = true,
    ) {
    }

    public static function ok(string $summary): self
    {
        return new self(true, $summary);
    }

    public static function failed(string $summary): self
    {
        return new self(false, $summary);
    }

    /** The check does not apply to this transport (e.g. connection test for log/array/ses). */
    public static function notApplicable(string $summary): self
    {
        return new self(true, $summary, false);
    }
}
