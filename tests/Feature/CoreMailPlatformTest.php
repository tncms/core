<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use TheNguyen\CMS\Mail\Mailables\MailTestMail;
use TheNguyen\CMS\Mail\MailConfiguration;
use TheNguyen\CMS\Mail\MailConfigurationBridge;
use TheNguyen\CMS\Mail\MailConfigurationResolver;
use TheNguyen\CMS\Mail\MailSettingsRepository;
use TheNguyen\CMS\Mail\MailSettingsValidator;

/**
 * Core Mail Platform (Phase Pre-E9.8 · A3 · CORE-MAIL-1 · ADR-CORE-MAIL-001/002/003).
 *
 * Proves the localized Core mail platform behavior extracted from the Pre-E9 snapshot:
 * one settings authority (cms_settings via SettingsManager), one resolver with a fail-safe
 * precedence, one boot-time bridge into Laravel Mail, the credential boundary (encrypt at
 * rest, blank-keeps, never exposed), structural validation, the localized Filament settings
 * surface (tn_trans + permission gate), and the localized test-mail rendering (cms:: view).
 */
class CoreMailPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    private function repository(): MailSettingsRepository
    {
        return app(MailSettingsRepository::class);
    }

    /** A valid, enabled SMTP settings payload for the resolver/bridge to consume. */
    private function seedEnabledSmtp(): void
    {
        $this->repository()->save([
            'enabled' => true,
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'postmaster',
            'password' => 's3cret',
            'encryption' => 'tls',
            'timeout' => 15,
            'from_address' => 'no-reply@example.test',
            'from_name' => 'Acme CMS',
            'reply_to_address' => 'hello@example.test',
        ]);
    }

    // 1. The platform singletons are the ONE authority — the same instances the provider wires.
    public function test_platform_services_are_singletons(): void
    {
        $this->assertSame(app(MailSettingsRepository::class), app(MailSettingsRepository::class));
        $this->assertSame(app(MailConfigurationResolver::class), app(MailConfigurationResolver::class));
        $this->assertSame(app(MailConfigurationBridge::class), app(MailConfigurationBridge::class));
    }

    // 2. Settings persist through the canonical cms_settings authority (no second table).
    public function test_settings_persist_through_settings_manager(): void
    {
        $this->seedEnabledSmtp();

        $this->assertTrue((bool) $this->settings()->get('mail.enabled'));
        $this->assertSame('smtp.example.test', $this->settings()->get('mail.host'));
        $this->assertSame(587, (int) $this->settings()->get('mail.port'));
    }

    // 3. Credential boundary: the SMTP password is encrypted at rest and never in form state.
    public function test_password_is_encrypted_and_never_exposed(): void
    {
        $this->seedEnabledSmtp();

        $cipher = $this->settings()->get('mail.password');
        $this->assertIsString($cipher);
        $this->assertNotSame('s3cret', $cipher);
        $this->assertSame('s3cret', Crypt::decryptString($cipher));

        $state = $this->repository()->formState();
        $this->assertSame('', $state['password']);
        $this->assertTrue($state['has_password']);
    }

    // 4. A blank password on save PRESERVES the secret; an explicit clear removes it.
    public function test_blank_keeps_and_clear_removes_password(): void
    {
        $this->seedEnabledSmtp();

        // Re-save with a blank password — the stored secret must survive.
        $this->repository()->save([
            'enabled' => true, 'mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'from_address' => 'no-reply@example.test', 'password' => '',
        ]);
        $this->assertTrue($this->repository()->hasPassword());
        $this->assertSame('s3cret', $this->repository()->decryptedPassword());

        // Explicit clear removes it.
        $this->repository()->save([
            'enabled' => true, 'mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'from_address' => 'no-reply@example.test', 'clear_password' => true,
        ]);
        $this->assertFalse($this->repository()->hasPassword());
        $this->assertNull($this->repository()->decryptedPassword());
    }

    // 5. The resolver is fail-safe: disabled settings resolve to null (Laravel config stands).
    public function test_resolver_returns_null_when_disabled(): void
    {
        $this->repository()->save([
            'enabled' => false, 'mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'from_address' => 'no-reply@example.test',
        ]);

        $this->assertNull(app(MailConfigurationResolver::class)->resolve());
    }

    // 6. Enabled + valid settings resolve to a MailConfiguration carrying the decrypted secret.
    public function test_resolver_produces_configuration_when_enabled_and_valid(): void
    {
        $this->seedEnabledSmtp();

        $config = app(MailConfigurationResolver::class)->resolve();

        $this->assertInstanceOf(MailConfiguration::class, $config);
        $this->assertSame('smtp', $config->mailer);
        $this->assertSame('smtp.example.test', $config->host);
        $this->assertSame(587, $config->port);
        $this->assertSame('s3cret', $config->password);
        $this->assertSame('no-reply@example.test', $config->fromAddress);
        // The credential-free projection never carries the password value.
        $this->assertArrayNotHasKey('password', $config->safe());
        $this->assertTrue($config->safe()['has_password']);
    }

    // 7. The bridge applies persisted settings into Laravel's config('mail.*').
    public function test_bridge_applies_persisted_settings_to_laravel_mail(): void
    {
        $this->seedEnabledSmtp();

        app(MailConfigurationBridge::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('no-reply@example.test', config('mail.from.address'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
    }

    // 8. Structural validation gates the mailer, the SMTP host, and header-injection vectors.
    public function test_validator_rejects_invalid_settings(): void
    {
        $validator = new MailSettingsValidator;

        $this->assertSame([], $validator->validate([
            'mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'encryption' => 'tls', 'from_address' => 'ok@example.test',
        ]));

        $this->assertArrayHasKey('mailer', $validator->validate([
            'mailer' => 'telnet', 'from_address' => 'ok@example.test',
        ]));

        $this->assertArrayHasKey('host', $validator->validate([
            'mailer' => 'smtp', 'host' => 'http://evil/', 'port' => 587, 'from_address' => 'ok@example.test',
        ]));

        // A CRLF in the display name is a header-injection vector and must be rejected.
        $this->assertArrayHasKey('from_name', $validator->validate([
            'mailer' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'from_address' => 'ok@example.test', 'from_name' => "Acme\r\nBcc: victim@evil.test",
        ]));
    }

    // 9. The localized test mail renders the cms:: view with the site name and applies reply-to.
    public function test_test_mail_renders_localized_view_with_reply_to(): void
    {
        $mail = new MailTestMail(siteName: 'Acme CMS', replyToAddress: 'hello@example.test');

        $mail->assertSeeInText('Acme CMS');
        $mail->assertHasSubject('Acme CMS — mail configuration test');
        $mail->assertHasReplyTo('hello@example.test');
    }

    // 10. The Filament settings surface is permission-gated and routes its labels through tn_trans.
    public function test_settings_page_is_permission_gated_and_localized(): void
    {
        // Guest without settings.manage cannot access the page (fail-closed).
        $this->assertFalse(\App\Filament\Admin\Pages\MailSettingsPage::canAccess());

        // Every user-facing label on the page is localized via the A1 tn_trans helper.
        $source = file_get_contents(app_path('Filament/Admin/Pages/MailSettingsPage.php'));
        $this->assertStringContainsString("tn_trans('Mail')", $source);
        $this->assertStringNotContainsString('->label(\'', $source);
    }
}
