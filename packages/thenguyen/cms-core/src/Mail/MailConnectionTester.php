<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use TheNguyen\CMS\Mail\Mailables\MailTestMail;

/**
 * Mail diagnostics — connection test and test email (Program Core Mail · Phase CORE-MAIL-1 ·
 * ADR-CORE-MAIL-002/003).
 *
 * It exercises the CURRENTLY-CONFIGURED Laravel mailer (as applied by
 * {@see MailConfigurationBridge}). The connection test is transport-only: for SMTP it opens
 * and closes the transport (honouring the configured timeout/auth/TLS); for non-SMTP mailers
 * (log/array/ses/…) it reports "not applicable". The send test delivers the Core
 * {@see MailTestMail} through Laravel Mail. Both NEVER throw, NEVER expose a raw transport
 * exception or a credential — only a short, fixed, safe summary — and both record a
 * credential-free timestamp via {@see MailSettingsRepository}.
 */
final class MailConnectionTester
{
    public function __construct(
        private readonly MailSettingsRepository $settings,
    ) {
    }

    public function testConnection(): MailTestResult
    {
        $mailer = (string) config('mail.default', 'smtp');

        try {
            $transport = Mail::mailer($mailer)->getSymfonyTransport();

            if (! $transport instanceof SmtpTransport) {
                return MailTestResult::notApplicable('Connection test is only available for SMTP (current mailer: ' . $mailer . ').');
            }

            $transport->start();
            $transport->stop();

            $this->settings->recordConnectionTest(true);

            return MailTestResult::ok('Connected to the SMTP server successfully.');
        } catch (\Throwable) {
            $this->settings->recordConnectionTest(false);

            // Fixed, safe summary — the raw transport/SMTP message is discarded.
            return MailTestResult::failed('Could not connect to the SMTP server. Check host, port, encryption and credentials.');
        }
    }

    public function sendTest(string $to): MailTestResult
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return MailTestResult::failed('Enter a valid recipient email address.');
        }

        $mailer = (string) config('mail.default', 'smtp');

        try {
            Mail::mailer($mailer)->to($to)->send(new MailTestMail(
                siteName: (string) config('app.name', 'TN CMS'),
                replyToAddress: $this->nullable(config('mail.from.address')),
            ));

            $this->settings->recordSendTest(true);

            return MailTestResult::ok('Test email sent to ' . $to . '.');
        } catch (\Throwable) {
            $this->settings->recordSendTest(false);

            return MailTestResult::failed('The test email could not be sent. Verify the mail configuration.');
        }
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
