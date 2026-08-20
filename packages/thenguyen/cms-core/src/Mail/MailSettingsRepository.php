<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use TheNguyen\CMS\Services\SettingsManager;

/**
 * The single reader/writer of the Core mail settings (Program Core Mail · Phase CORE-MAIL-1 ·
 * ADR-CORE-MAIL-003).
 *
 * It persists the `mail.*` group through the canonical {@see SettingsManager}/`cms_settings`
 * authority — NO new settings table, NO second settings system. It owns the credential
 * boundary: the SMTP password is encrypted at rest via Laravel {@see Crypt} (APP_KEY), is
 * NEVER returned to the UI ({@see formState()} exposes only a presence flag), and a blank
 * password on save PRESERVES the stored secret (an explicit clear removes it). If the stored
 * ciphertext cannot be decrypted (e.g. APP_KEY rotation) the read fails closed — the resolver
 * treats it as "not configured" and mail falls back to config/.env rather than sending with a
 * broken credential.
 */
final class MailSettingsRepository
{
    public const GROUP = 'mail';

    private const K_ENABLED = 'mail.enabled';
    private const K_MAILER = 'mail.mailer';
    private const K_HOST = 'mail.host';
    private const K_PORT = 'mail.port';
    private const K_USERNAME = 'mail.username';
    private const K_PASSWORD = 'mail.password'; // stores CIPHERTEXT only
    private const K_ENCRYPTION = 'mail.encryption';
    private const K_TIMEOUT = 'mail.timeout';
    private const K_FROM_ADDRESS = 'mail.from_address';
    private const K_FROM_NAME = 'mail.from_name';
    private const K_REPLY_TO_ADDRESS = 'mail.reply_to_address';
    private const K_REPLY_TO_NAME = 'mail.reply_to_name';
    private const K_LAST_TEST_OK = 'mail.last_test_ok_at';
    private const K_LAST_TEST_FAILED = 'mail.last_test_failed_at';
    private const K_LAST_SEND_OK = 'mail.last_send_ok_at';
    private const K_LAST_SEND_FAILED = 'mail.last_send_failed_at';
    private const K_LAST_VALIDATION = 'mail.last_validation_at';

    /** The diagnostic timestamps a settings change invalidates (they describe the OLD config). */
    private const STALE_ON_SAVE = [
        self::K_LAST_TEST_OK,
        self::K_LAST_TEST_FAILED,
        self::K_LAST_SEND_OK,
        self::K_LAST_SEND_FAILED,
    ];

    public function __construct(
        private readonly SettingsManager $settings,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::K_ENABLED, false);
    }

    public function mailer(): string
    {
        return (string) ($this->settings->get(self::K_MAILER) ?: 'smtp');
    }

    public function hasPassword(): bool
    {
        $cipher = $this->settings->get(self::K_PASSWORD);

        return is_string($cipher) && $cipher !== '';
    }

    /**
     * The decrypted SMTP password, or null when none is stored. Throws when a stored
     * ciphertext cannot be decrypted (APP_KEY rotation) so callers can fail closed.
     */
    public function decryptedPassword(): ?string
    {
        $cipher = $this->settings->get(self::K_PASSWORD);

        if (! is_string($cipher) || $cipher === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException $e) {
            throw new RuntimeException('mail_credential_undecryptable', 0, $e);
        }
    }

    /**
     * The current settings as the admin form should see them — the password is NEVER
     * returned; only a presence flag. Diagnostics timestamps are included.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        return [
            'enabled' => $this->enabled(),
            'mailer' => $this->mailer(),
            'host' => (string) ($this->settings->get(self::K_HOST) ?? ''),
            'port' => $this->settings->get(self::K_PORT),
            'username' => (string) ($this->settings->get(self::K_USERNAME) ?? ''),
            'has_password' => $this->hasPassword(),
            'password' => '', // never hydrate the secret into the form
            'clear_password' => false,
            'encryption' => (string) ($this->settings->get(self::K_ENCRYPTION) ?? ''),
            'timeout' => $this->settings->get(self::K_TIMEOUT),
            'from_address' => (string) ($this->settings->get(self::K_FROM_ADDRESS) ?? ''),
            'from_name' => (string) ($this->settings->get(self::K_FROM_NAME) ?? ''),
            'reply_to_address' => (string) ($this->settings->get(self::K_REPLY_TO_ADDRESS) ?? ''),
            'reply_to_name' => (string) ($this->settings->get(self::K_REPLY_TO_NAME) ?? ''),
        ];
    }

    /**
     * Persist mail settings. The password rule: a non-empty `password` is encrypted and
     * stored; a blank password KEEPS the existing secret; `clear_password = true` removes it.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): void
    {
        $this->settings->set(self::K_ENABLED, (bool) ($input['enabled'] ?? false), 'boolean');
        $this->settings->set(self::K_MAILER, (string) ($input['mailer'] ?? 'smtp'), 'string');
        $this->settings->set(self::K_HOST, $this->str($input['host'] ?? null), 'string');
        $this->settings->set(self::K_PORT, $this->intOrNull($input['port'] ?? null), 'integer');
        $this->settings->set(self::K_USERNAME, $this->str($input['username'] ?? null), 'string');
        $this->settings->set(self::K_ENCRYPTION, $this->str($input['encryption'] ?? null), 'string');
        $this->settings->set(self::K_TIMEOUT, $this->intOrNull($input['timeout'] ?? null), 'integer');
        $this->settings->set(self::K_FROM_ADDRESS, $this->str($input['from_address'] ?? null), 'string');
        $this->settings->set(self::K_FROM_NAME, $this->str($input['from_name'] ?? null), 'string');
        $this->settings->set(self::K_REPLY_TO_ADDRESS, $this->str($input['reply_to_address'] ?? null), 'string');
        $this->settings->set(self::K_REPLY_TO_NAME, $this->str($input['reply_to_name'] ?? null), 'string');

        $this->savePassword($input);

        // A configuration change makes the previous connection/send-test evidence stale:
        // an old "OK" must never imply the freshly-saved settings are healthy.
        foreach (self::STALE_ON_SAVE as $key) {
            $this->settings->forget($key);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function savePassword(array $input): void
    {
        if ((bool) ($input['clear_password'] ?? false)) {
            $this->settings->forget(self::K_PASSWORD);

            return;
        }

        $password = $input['password'] ?? null;

        // Blank submit keeps the existing secret untouched.
        if (! is_string($password) || $password === '') {
            return;
        }

        $this->settings->set(self::K_PASSWORD, Crypt::encryptString($password), 'string');
    }

    public function recordConnectionTest(bool $ok): void
    {
        $this->settings->set($ok ? self::K_LAST_TEST_OK : self::K_LAST_TEST_FAILED, now()->toIso8601String(), 'string');
    }

    public function recordSendTest(bool $ok): void
    {
        $this->settings->set($ok ? self::K_LAST_SEND_OK : self::K_LAST_SEND_FAILED, now()->toIso8601String(), 'string');
    }

    public function recordValidation(): void
    {
        $this->settings->set(self::K_LAST_VALIDATION, now()->toIso8601String(), 'string');
    }

    /**
     * Credential-free diagnostics timestamps for the health/settings surface.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'enabled' => $this->enabled(),
            'has_password' => $this->hasPassword(),
            'last_test_ok_at' => $this->settings->get(self::K_LAST_TEST_OK),
            'last_test_failed_at' => $this->settings->get(self::K_LAST_TEST_FAILED),
            'last_send_ok_at' => $this->settings->get(self::K_LAST_SEND_OK),
            'last_send_failed_at' => $this->settings->get(self::K_LAST_SEND_FAILED),
            'last_validation_at' => $this->settings->get(self::K_LAST_VALIDATION),
        ];
    }

    private function str(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
