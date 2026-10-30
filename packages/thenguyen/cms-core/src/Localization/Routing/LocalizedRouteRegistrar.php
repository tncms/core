<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Routing;

use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;

/**
 * The single Route Dictionary finalization point (CORE-PLATFORM-SEAMS-R1),
 * bound as `cms.localized_routes`.
 *
 * Code that generates routes FROM the composed Dictionary but boots before the
 * active plugins (the host search routes, any early provider) must not read
 * the Dictionary at its own boot: the first read composes it and locks the
 * source registry, so plugin sources registered afterwards are rejected
 * (Lottery P05 gap). Such code defers its registration here instead; Core
 * finalizes once, after every active plugin's providers have registered their
 * sources (ExtensionManager pass 1) and before any plugin route loads, so the
 * deferred routes keep their pre-R1 position ahead of plugin routes:
 *
 *   plugin providers + sources → finalize() (dictionary locked, deferred
 *     localized routes registered) → plugin routes (pass 2) → catch-all
 *
 * Core also calls finalize() before the catch-all, which covers boots that
 * skip plugins (pre-install); it is idempotent.
 *
 * Deferred registrars run in registration order. A registrar deferred after
 * finalization runs immediately (and is logged: its routes register after the
 * catch-all).
 */
final class LocalizedRouteRegistrar
{
    /** @var list<callable> */
    private array $deferred = [];

    private bool $finalized = false;

    /** Plugin registration (ExtensionManager pass 1) is in progress. */
    private bool $registrationOpen = false;

    public function __construct(private readonly RouteDictionarySourceRegistry $sources) {}

    public function defer(callable $registrar): void
    {
        if ($this->finalized) {
            Log::warning('TN CMS localized routes deferred after dictionary finalization; registering late');
            $registrar();

            return;
        }

        $this->deferred[] = $registrar;
    }

    /**
     * CORE-PLATFORM-SEAMS-R2 lifecycle: REGISTRATION (open) → VALIDATION + COMPOSITION
     * + FINALIZATION (finalize) → ROUTE CONSUMPTION. A dictionary read while
     * registration is open fails closed ({@see assertReadable()}).
     */
    public function openRegistration(): void
    {
        if (! $this->finalized) {
            $this->registrationOpen = true;
        }
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registrationOpen;
    }

    /**
     * Guard every composition of the dictionary. Reads are allowed once Core has
     * started finalizing; reads during plugin registration or while the app is
     * still booting throw; a read after boot outside the boot sequence (console,
     * tests, admin rebuild) finalizes implicitly, exactly as before.
     */
    public function assertReadable(bool $appBooted): void
    {
        if ($this->finalized) {
            return;
        }

        if ($this->registrationOpen) {
            throw \TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionaryLifecycleException::earlyRead('plugin registration');
        }

        if (! $appBooted) {
            throw \TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionaryLifecycleException::earlyRead('application boot');
        }

        $this->finalized = true;
    }

    /** Compose (and lock) the Dictionary, then register every deferred route set. */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->registrationOpen = false;

        try {
            app('cms.localization.dictionary');
        } catch (\Throwable $e) {
            // An invalid source fails composition; report it but still give each
            // deferred registrar its own chance (they fail independently).
            report($e);
        }

        foreach ($this->deferred as $registrar) {
            try {
                $registrar();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->deferred = [];
    }

    public function isFinalized(): bool
    {
        return $this->finalized && $this->sources->isLocked();
    }
}
