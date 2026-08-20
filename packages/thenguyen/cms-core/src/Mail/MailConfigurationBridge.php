<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Mail;

use Illuminate\Support\Facades\Mail;

/**
 * The boot-time Laravel Mail bridge (Program Core Mail · Phase CORE-MAIL-1 · ADR-CORE-MAIL-002).
 *
 * It asks {@see MailConfigurationResolver} for the effective configuration and, when the
 * persisted layer wins, applies it to Laravel's `config('mail.*')` and purges the affected
 * mailer so the transport is rebuilt with the new settings. It does NOTHING when the resolver
 * returns null — Laravel's own config/.env stands untouched. It runs ONCE at service-provider
 * boot (not per request) so it is compatible with `config:cache` (runtime overrides mutate the
 * repository, not the cache file) and with long-running/queue workers, which pick up the
 * settings present at process boot — a settings change therefore requires a worker restart.
 * It is fully guarded: any failure leaves Laravel's configuration as-is; mail is never broken
 * by this bridge.
 */
final class MailConfigurationBridge
{
    public function __construct(
        private readonly MailConfigurationResolver $resolver,
    ) {
    }

    public function apply(): void
    {
        try {
            $configuration = $this->resolver->resolve();

            if ($configuration === null) {
                return; // fall back to Laravel config/.env
            }

            foreach ($configuration->toOverrides() as $key => $value) {
                config()->set($key, $value);
            }

            // Rebuild the mailer so an already-resolved transport picks up the new config.
            Mail::purge($configuration->mailer);
        } catch (\Throwable) {
            // Never break boot / mail on a persisted-settings problem.
        }
    }
}
