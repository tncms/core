<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

/**
 * THE single mail configuration authority (Program Core Mail · Phase CORE-MAIL-1 ·
 * ADR-CORE-MAIL-002).
 *
 * It resolves the effective mail configuration with one precedence, in one place:
 *
 *   Persisted Core mail settings  (only when enabled AND structurally valid AND decryptable)
 *         ↓
 *   config/mail.php  (Laravel defaults)
 *         ↓
 *   .env  (MAIL_*)  ← fail-safe fallback
 *
 * {@see resolve()} returns a {@see MailConfiguration} ONLY when the persisted layer should
 * win; otherwise it returns null and Laravel's own configuration stands unchanged. It is
 * fail-safe by construction: disabled, invalid, or an undecryptable credential (APP_KEY
 * rotation) all resolve to null, so mail never sends with a broken persisted credential. No
 * other component resolves mail configuration; every consumer inherits the result through the
 * Laravel mailer the {@see MailConfigurationBridge} applies.
 */
final class MailConfigurationResolver
{
    private const DEFAULT_SMTP_TIMEOUT = 10;

    public function __construct(
        private readonly MailSettingsRepository $settings,
        private readonly MailSettingsValidator $validator,
    ) {
    }

    public function resolve(): ?MailConfiguration
    {
        try {
            if (! $this->settings->enabled()) {
                return null;
            }

            $state = $this->settings->formState();

            if ($this->validator->validate($state) !== []) {
                return null; // invalid persisted settings never reach Laravel
            }

            $mailer = (string) $state['mailer'];
            $password = null;

            if ($mailer === 'smtp') {
                // Fail closed: an undecryptable credential (APP_KEY rotation) throws → null.
                $password = $this->settings->decryptedPassword();
            }

            $timeout = is_numeric($state['timeout'] ?? null) ? (int) $state['timeout'] : null;

            return new MailConfiguration(
                mailer: $mailer,
                host: $this->nullable($state['host'] ?? null),
                port: is_numeric($state['port'] ?? null) ? (int) $state['port'] : null,
                username: $this->nullable($state['username'] ?? null),
                password: $password,
                encryption: $this->nullable($state['encryption'] ?? null),
                timeout: $mailer === 'smtp' ? ($timeout ?? self::DEFAULT_SMTP_TIMEOUT) : $timeout,
                fromAddress: (string) $state['from_address'],
                fromName: (string) ($state['from_name'] ?: config('mail.from.name', 'TN CMS')),
                replyToAddress: $this->nullable($state['reply_to_address'] ?? null),
                replyToName: $this->nullable($state['reply_to_name'] ?? null),
            );
        } catch (\Throwable) {
            // Any failure → fall back to Laravel config/.env. Mail never breaks on our account.
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
