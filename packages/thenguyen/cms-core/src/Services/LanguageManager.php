<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Language;

/**
 * Language Manager (Phase 9).
 *
 * Owns the registry of available languages (cms_languages), the default /
 * current locale resolution, and locale-aware URL prefixing. Every method is
 * defensive: before the table exists it falls back to a single virtual `vi`
 * locale so helpers, routes, and /cms-health never crash.
 *
 * The "current" locale is per-request state held on this singleton — it is set
 * by the FrontendController and is never persisted.
 */
class LanguageManager
{
    private const FALLBACK_CODE = 'vi';

    private const TABLE = 'cms_languages';

    /**
     * Tables carrying a per-locale row. A language that still owns rows here
     * cannot be deleted (B3) — doing so would orphan the rows and silently break
     * that locale's public URLs.
     *
     * @var array<int, string>
     */
    private const LOCALE_DATA_TABLES = [
        'cms_content_translations',
        'cms_term_translations',
        'cms_menu_translations',
        'cms_menu_item_translations',
        'cms_slugs',
    ];

    /** Per-request current locale (set by the frontend controller). */
    private ?string $currentCode = null;

    /** Memoised active-language collection for the request. */
    private ?Collection $activeCache = null;

    /**
     * Memoized affirmative existence of the languages table, so locale reads
     * (default_locale()/current_locale()) do not run a schema introspection on
     * every call. Only `true` is cached; reset by {@see flush()}.
     */
    private ?bool $languageTableExists = null;

    /** Memoized default language for the request (resolved flag guards null). */
    private bool $defaultResolved = false;

    private ?Language $defaultCache = null;

    /**
     * All languages, ordered. Active-only by default.
     *
     * @return Collection<int, Language>
     */
    public function all(bool $activeOnly = true): Collection
    {
        if (! $this->tableExists()) {
            return new Collection();
        }

        $query = Language::query()->ordered();

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * Active languages (memoised for the request).
     *
     * @return Collection<int, Language>
     */
    public function active(): Collection
    {
        if ($this->activeCache !== null) {
            return $this->activeCache;
        }

        return $this->activeCache = $this->all(activeOnly: true);
    }

    public function default(): ?Language
    {
        if ($this->defaultResolved) {
            return $this->defaultCache;
        }

        if (! $this->tableExists()) {
            return null;
        }

        $this->defaultResolved = true;

        return $this->defaultCache = Language::query()->default()->first()
            ?? Language::query()->ordered()->first();
    }

    public function defaultCode(): string
    {
        $default = $this->default();

        if ($default !== null) {
            return $default->code;
        }

        $setting = settings('language.default', self::FALLBACK_CODE);

        return is_string($setting) && $setting !== '' ? $setting : self::FALLBACK_CODE;
    }

    public function current(): ?Language
    {
        return $this->find($this->currentCode());
    }

    public function currentCode(): string
    {
        if ($this->currentCode !== null && $this->currentCode !== '') {
            return $this->currentCode;
        }

        // Fall back to a locale route parameter if one is bound on this request.
        $routeLocale = request()->route('locale');

        if (is_string($routeLocale) && $routeLocale !== '') {
            return $this->normalizeCode($routeLocale);
        }

        return $this->defaultCode();
    }

    public function find(string $code): ?Language
    {
        if (! $this->tableExists()) {
            return null;
        }

        $code = $this->normalizeCode($code);

        return $this->active()->firstWhere('code', $code)
            ?? Language::query()->where('code', $code)->first();
    }

    public function setCurrent(string $code): void
    {
        $this->currentCode = $this->normalizeCode($code);

        // Best-effort: align the framework locale for translation lookups.
        app()->setLocale($this->currentCode);
    }

    /**
     * Make $code the single default language (and ensure it is active).
     */
    public function setDefault(string $code): bool
    {
        if (! $this->tableExists()) {
            return false;
        }

        $code = $this->normalizeCode($code);
        $language = Language::query()->where('code', $code)->first();

        if ($language === null) {
            return false;
        }

        DB::transaction(function () use ($language): void {
            Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
            $language->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        $this->persistDefaultSetting($code);
        $this->flush();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Language
    {
        $data = $this->normalizeData($data);

        return DB::transaction(function () use ($data): Language {
            if (! empty($data['is_default'])) {
                Language::query()->update(['is_default' => false]);
                $data['is_active'] = true;
            }

            $language = Language::query()->create($data);

            if ($language->is_default) {
                $this->persistDefaultSetting($language->code);
            }

            $this->flush();

            return $language;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Language $language, array $data): Language
    {
        $data = $this->normalizeData($data);

        return DB::transaction(function () use ($language, $data): Language {
            // The default language can never be deactivated.
            $makingDefault = ! empty($data['is_default']);

            if ($makingDefault) {
                Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
                $data['is_active'] = true;
            } elseif ($language->is_default) {
                // Editing the current default: keep it default + active.
                $data['is_default'] = true;
                $data['is_active'] = true;
            }

            $language->fill($data)->save();

            if ($language->is_default) {
                $this->persistDefaultSetting($language->code);
            }

            $this->flush();

            return $language->refresh();
        });
    }

    /**
     * Delete a language only when it is safe to do so (see
     * {@see deletionBlockReason()}). Returns false — without touching anything —
     * when deletion is blocked, so callers can surface the reason.
     */
    public function delete(Language $language): bool
    {
        if ($this->deletionBlockReason($language) !== null) {
            return false;
        }

        $deleted = (bool) $language->delete();
        $this->flush();

        return $deleted;
    }

    /**
     * Why $language may NOT be deleted, or null when it is safe to delete.
     *
     * A language is deletable only when it is not the default, not the last
     * remaining language, and owns no translated content/term/menu/slug rows.
     * Refusing (rather than cascade-deleting) keeps beta deletions
     * non-destructive: orphaning translation rows or silently breaking a
     * locale's URLs is worse than a blocked delete. Returns a translatable
     * reason string for the admin UI.
     */
    public function deletionBlockReason(Language $language): ?string
    {
        if ($language->is_default) {
            return 'The default language cannot be deleted.';
        }

        if ($this->tableExists() && Language::query()->count() <= 1) {
            return 'At least one language must remain.';
        }

        if ($this->hasTranslatedData($language->code)) {
            return 'This language still has translated content, terms, menus, or URLs. Remove or reassign them before deleting it.';
        }

        return null;
    }

    /**
     * Whether any per-locale translation/slug row exists for $code. Fully
     * guarded: a missing/locked table is treated as "no data" so the safety
     * check never throws.
     */
    public function hasTranslatedData(string $code): bool
    {
        $code = $this->normalizeCode($code);

        foreach (self::LOCALE_DATA_TABLES as $table) {
            try {
                if (Schema::hasTable($table) && DB::table($table)->where('locale', $code)->exists()) {
                    return true;
                }
            } catch (\Throwable) {
                // A broken/locked table must not break the safety check.
            }
        }

        return false;
    }

    public function isActive(string $code): bool
    {
        $code = $this->normalizeCode($code);

        return $this->active()->contains(fn (Language $l): bool => $l->code === $code);
    }

    public function normalizeCode(?string $code): string
    {
        $code = strtolower(trim((string) $code));

        return $code !== '' ? $code : $this->defaultCodeRaw();
    }

    public function shouldPrefixDefaultLocale(): bool
    {
        return (bool) settings('language.prefix_default', false);
    }

    /**
     * Build a public path for $code, honouring the prefix_default setting.
     *
     * - default locale + prefix_default=false → unprefixed: /blog/x
     * - otherwise → /{code}/blog/x  (or /{code} for the home path)
     */
    public function localizedUrl(string $code, ?string $path = null): string
    {
        $code = $this->normalizeCode($code);
        $path = '/' . ltrim((string) ($path ?? '/'), '/');

        if ($code === $this->defaultCode() && ! $this->shouldPrefixDefaultLocale()) {
            return $path;
        }

        $prefix = '/' . $code;

        return $path === '/' ? $prefix : $prefix . $path;
    }

    /**
     * Active language codes, e.g. ['vi', 'en'].
     *
     * @return array<int, string>
     */
    public function getPublicLocales(): array
    {
        return $this->active()->pluck('code')->all();
    }

    /**
     * Code => label map for admin selectors. Falls back to a single vi entry
     * before the table is seeded so forms always have a usable option.
     *
     * @return array<string, string>
     */
    public function optionList(): array
    {
        $options = $this->active()
            ->mapWithKeys(fn (Language $l): array => [$l->code => $l->label()])
            ->all();

        return $options !== [] ? $options : [self::FALLBACK_CODE => 'Vietnamese (vi)'];
    }

    /**
     * Regex alternation of active codes for route constraints, e.g. "vi|en".
     * Static + fully guarded so it is safe to call during route registration
     * (including before the table exists or during migrations).
     */
    public static function routeLocalePattern(): string
    {
        try {
            if (Schema::hasTable(self::TABLE)) {
                $codes = Language::query()
                    ->where('is_active', true)
                    ->pluck('code')
                    ->map(static fn ($c): string => preg_replace('/[^a-z0-9_-]/i', '', (string) $c) ?? '')
                    ->filter(static fn (string $c): bool => $c !== '')
                    ->all();

                if ($codes !== []) {
                    return implode('|', $codes);
                }
            }
        } catch (\Throwable) {
            // Fall through to the static default below.
        }

        return 'vi|en';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeData(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = $this->normalizeCode($data['code']);
        }

        if (array_key_exists('direction', $data)) {
            $data['direction'] = $data['direction'] === 'rtl' ? 'rtl' : 'ltr';
        }

        return $data;
    }

    private function persistDefaultSetting(string $code): void
    {
        try {
            settings()->set('language.default', $code, 'string', [
                'is_public' => true,
                'autoload' => true,
                'description' => 'Default language code',
            ]);
        } catch (\Throwable) {
            // Settings table may not exist yet — non-fatal.
        }
    }

    /**
     * defaultCode without the recursion risk of normalizeCode → defaultCode.
     */
    private function defaultCodeRaw(): string
    {
        $default = $this->default();

        if ($default !== null) {
            return $default->code;
        }

        $setting = settings('language.default', self::FALLBACK_CODE);

        return is_string($setting) && $setting !== '' ? $setting : self::FALLBACK_CODE;
    }

    private function flush(): void
    {
        $this->activeCache = null;
        $this->languageTableExists = null;
        $this->defaultResolved = false;
        $this->defaultCache = null;
    }

    private function tableExists(): bool
    {
        if ($this->languageTableExists === true) {
            return true;
        }

        $exists = false;

        try {
            $exists = Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            $exists = false;
        }

        if ($exists) {
            $this->languageTableExists = true;
        }

        return $exists;
    }
}
