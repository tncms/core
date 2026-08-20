<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

/**
 * Structural validation of mail settings (Program Core Mail · Phase CORE-MAIL-1 ·
 * ADR-CORE-MAIL-003).
 *
 * STRUCTURAL ONLY — it never opens a transport connection (that is {@see MailConnectionTester}).
 * It checks the mailer is one Laravel actually knows, that SMTP has a host + a valid port, that
 * the encryption is a supported value, and that the sender/reply-to addresses are well-formed.
 * It is the gate {@see MailConfigurationResolver} consults before applying persisted settings,
 * and the same gate the admin save path uses, so invalid settings never reach Laravel.
 */
final class MailSettingsValidator
{
    /** The mailers Laravel ships in config/mail.php — no arbitrary transport is accepted. */
    public const ALLOWED_MAILERS = ['smtp', 'ses', 'postmark', 'resend', 'sendmail', 'log', 'array', 'failover', 'roundrobin'];

    public const ALLOWED_ENCRYPTION = ['', 'tls', 'ssl'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string> field => first error message (empty = valid)
     */
    public function validate(array $input): array
    {
        $errors = [];

        $mailer = (string) ($input['mailer'] ?? '');
        if (! in_array($mailer, self::ALLOWED_MAILERS, true)) {
            $errors['mailer'] = 'Unsupported mailer.';
        }

        if ($mailer === 'smtp') {
            $host = trim((string) ($input['host'] ?? ''));
            if ($host === '') {
                $errors['host'] = 'SMTP host is required.';
            } elseif (! $this->isSafeHost($host)) {
                $errors['host'] = 'SMTP host must be a plain hostname or IP — no scheme, credentials, path or spaces.';
            }

            $port = $input['port'] ?? null;
            if (! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
                $errors['port'] = 'Port must be between 1 and 65535.';
            }

            $encryption = (string) ($input['encryption'] ?? '');
            if (! in_array($encryption, self::ALLOWED_ENCRYPTION, true)) {
                $errors['encryption'] = 'Unsupported encryption.';
            }

            $timeout = $input['timeout'] ?? null;
            if ($timeout !== null && $timeout !== '' && (! is_numeric($timeout) || (int) $timeout < 0)) {
                $errors['timeout'] = 'Timeout must be a non-negative number.';
            }
        }

        $from = trim((string) ($input['from_address'] ?? ''));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_address'] = 'A valid sender email is required.';
        }

        $replyTo = trim((string) ($input['reply_to_address'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reply_to_address'] = 'Reply-to must be a valid email.';
        }

        // Display names must never carry control/newline characters — a CRLF here is a
        // header-injection vector before the transport ever escapes it.
        foreach (['from_name' => 'From name', 'reply_to_name' => 'Reply-to name'] as $field => $label) {
            $value = (string) ($input[$field] ?? '');
            if ($value !== '' && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $errors[$field] = $label . ' contains invalid characters.';
            }
        }

        return $errors;
    }

    /**
     * A safe SMTP host is a plain hostname or IP literal only. It rejects a scheme,
     * embedded credentials, a path/query/fragment, whitespace and control characters
     * (CRLF) so a malformed or injection-style host can never reach the live transport.
     */
    private function isSafeHost(string $host): bool
    {
        return preg_match('/[^A-Za-z0-9.\-:\[\]_]/', $host) !== 1;
    }
}
