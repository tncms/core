<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use Throwable;
use TheNguyen\CMS\Localization\Contracts\PublicLocaleContextContract;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * CORE-L10N.1B — the concrete {@see PublicLocaleContextContract}.
 *
 * The public locale is whatever the language authority currently holds for the request
 * ({@see LanguageManager::currentCode()}) — set by the active routing strategy (the prefix
 * middleware today; the session strategy in a later phase). The admin and editing locales are
 * read from {@see LocalePreferenceManager}. All accessors are read-only and defensive: any
 * failure degrades to the default locale rather than throwing.
 */
final class PublicLocaleContext implements PublicLocaleContextContract
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly LocalePreferenceManager $preferences,
    ) {}

    public function current(): string
    {
        return $this->languages->currentCode();
    }

    public function default(): string
    {
        return $this->languages->defaultCode();
    }

    public function isPublic(string $code): bool
    {
        return $this->languages->isActive($code);
    }

    public function adminLocale(): string
    {
        try {
            $request = request();

            return $this->preferences->resolveAdminLocale($request, $request->user());
        } catch (Throwable) {
            return $this->languages->defaultCode();
        }
    }

    public function editingLocale(): string
    {
        try {
            $request = request();

            return $this->preferences->resolveEditingLocale($request, $request->user());
        } catch (Throwable) {
            return $this->languages->defaultCode();
        }
    }
}
