<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

/**
 * The immutable, fully-resolved mail configuration the platform applies to Laravel
 * (Program Core Mail · Phase CORE-MAIL-1 · ADR-CORE-MAIL-002).
 *
 * Produced ONLY by {@see MailConfigurationResolver} when persisted settings are enabled AND
 * structurally valid AND (for SMTP) the credential decrypts. It carries the DECRYPTED
 * password for the single moment it is pushed into Laravel's config by
 * {@see MailConfigurationBridge}; it is never logged, never serialized to the UI, and never
 * placed in diagnostics — {@see safe()} is the only representation allowed to leave the
 * platform boundary.
 */
final class MailConfiguration
{
    public function __construct(
        public readonly string $mailer,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly ?string $encryption,
        public readonly ?int $timeout,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly ?string $replyToAddress = null,
        public readonly ?string $replyToName = null,
    ) {
    }

    /**
     * The Laravel `config('mail.*')` overrides this configuration applies. For SMTP it
     * includes the transport array; for other mailers only the default + from are set (their
     * transport/credentials live in Laravel's own config/services). Reply-to is intentionally
     * absent — Laravel has no global reply-to; it is a per-message concern the Core test
     * mailer and opt-in consumers read from {@see replyToAddress}.
     *
     * @return array<string, mixed>
     */
    public function toOverrides(): array
    {
        $overrides = [
            'mail.default' => $this->mailer,
            'mail.from' => ['address' => $this->fromAddress, 'name' => $this->fromName],
        ];

        if ($this->mailer === 'smtp') {
            $overrides['mail.mailers.smtp'] = array_filter([
                'transport' => 'smtp',
                'host' => $this->host,
                'port' => $this->port,
                'username' => $this->username,
                'password' => $this->password,
                'timeout' => $this->timeout,
                'encryption' => $this->encryption,
                'scheme' => $this->encryption === 'ssl' ? 'smtps' : null,
            ], static fn ($v): bool => $v !== null);
        }

        return $overrides;
    }

    /**
     * A credential-free projection for UI/diagnostics/health. The password is reduced to a
     * boolean presence flag — the value is never exposed.
     *
     * @return array<string, mixed>
     */
    public function safe(): array
    {
        return [
            'mailer' => $this->mailer,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'has_password' => $this->password !== null && $this->password !== '',
            'encryption' => $this->encryption,
            'timeout' => $this->timeout,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'reply_to_address' => $this->replyToAddress,
            'reply_to_name' => $this->replyToName,
        ];
    }
}
