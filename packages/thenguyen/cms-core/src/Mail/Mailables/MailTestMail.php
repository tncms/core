<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail\Mailables;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Core mail-configuration test message (Program Core Mail · Phase CORE-MAIL-1).
 *
 * A minimal, Core-owned plain-text email used ONLY by the mail settings "send test" action to
 * prove the configured transport delivers. It is NOT a business template and carries no
 * business meaning — it exists solely to exercise the transport. Reply-to is applied from the
 * platform mail settings when present.
 */
final class MailTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $siteName = 'TN CMS',
        public readonly ?string $replyToAddress = null,
        public readonly ?string $replyToName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->replyToAddress !== null
                ? [new Address($this->replyToAddress, $this->replyToName ?? '')]
                : [],
            subject: $this->siteName . ' — mail configuration test',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'cms::emails.mail-test',
            with: ['siteName' => $this->siteName],
        );
    }
}
