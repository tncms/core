<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Security;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * Fails closed before a security link is mailed (CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1).
 *
 * Listens to NotificationSending, which Laravel dispatches for every channel
 * before a notification is delivered (also for queued notifications, on the
 * worker). When APP_URL is not a usable trusted origin, the password-reset and
 * email-verification notifications are cancelled and the configuration problem
 * is logged. The log line carries the reason only: no exception trace (the call
 * stack holds the reset token), no URL and no configured value. The caller's
 * flow and public response are unchanged, so a forgot-password form still
 * reveals no account.
 */
final class SecurityLinkNotificationGuard
{
    public function __construct(private readonly TrustedOrigin $origin) {}

    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notification instanceof ResetPassword && ! $event->notification instanceof VerifyEmail) {
            return null;
        }

        $problem = $this->origin->problem();
        if ($problem === null) {
            return null;
        }

        Log::error((new UntrustedOriginException($problem))->getMessage(), [
            'notification' => $event->notification::class,
            'channel' => $event->channel,
        ]);

        return false;
    }
}
