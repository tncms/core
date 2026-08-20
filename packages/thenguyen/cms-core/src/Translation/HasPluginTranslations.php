<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Generic plugin translation support (Phase 1C).
 *
 * Drop this trait onto any plugin Eloquent model that should be translatable.
 * Each row is a single-locale variant; rows that translate one another share a
 * `translation_group` key. The trait is intentionally schema-light: the model's
 * table only needs a nullable `locale` column and a nullable `translation_group`
 * column (both indexed). Nothing about a specific plugin is hard-coded here, so
 * the same mechanism is reusable by future plugins.
 *
 * Conventions reused from the core CMS:
 * - locale codes come from the LanguageManager (current_locale()/default_locale()).
 * - scopeForLocale() returns rows for a locale PLUS locale-agnostic (null) rows,
 *   matching how the core content queries fall back.
 *
 * A model that uses this trait should also implement
 * {@see \TheNguyen\CMS\Contracts\TranslatablePluginContent}.
 */
trait HasPluginTranslations
{
    /**
     * Locale integrity (Phase 1E): a translation group never coexists with a null
     * locale. A grouped row is, by definition, one language's variant of a piece of
     * content — so it must declare which language. A locale-neutral row (README,
     * License, Changelog …) is the only legitimate null-locale case and it stays
     * ungrouped.
     *
     * Two guards enforce this:
     *  - creating: a brand-new *localised* row that has no group yet gets a fresh
     *    one so every independent record starts its own group. A neutral
     *    (null-locale) row is intentionally left ungrouped.
     *  - saving: any row that carries a group MUST carry a locale, or the save is
     *    rejected — invalid rows are never silently written.
     *
     * Linking happens explicitly via createTranslationFor() (admin) or a shared
     * front-matter / auto-derived key (sync).
     */
    public static function bootHasPluginTranslations(): void
    {
        static::creating(function ($model): void {
            $groupColumn = $model->getTranslationGroupColumn();

            if (blank($model->getAttribute($groupColumn)) && filled($model->getAttribute($model->getLocaleColumn()))) {
                $model->setAttribute($groupColumn, (string) Str::uuid());
            }
        });

        static::saving(function ($model): void {
            $group = $model->getAttribute($model->getTranslationGroupColumn());
            $locale = $model->getAttribute($model->getLocaleColumn());

            if (filled($group) && blank($locale)) {
                throw new \RuntimeException(sprintf(
                    'Locale integrity violation: %s has a translation group but no locale. A grouped translation must declare its locale; only ungrouped rows may be locale-neutral.',
                    $model::class,
                ));
            }
        });
    }

    public function getLocaleColumn(): string
    {
        return 'locale';
    }

    public function getTranslationGroupColumn(): string
    {
        return 'translation_group';
    }

    public function getLocale(): ?string
    {
        $value = $this->getAttribute($this->getLocaleColumn());

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function translationGroup(): ?string
    {
        $value = $this->getAttribute($this->getTranslationGroupColumn());

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Rows for $locale plus locale-agnostic (null) rows. A null/empty locale is a
     * no-op so callers can pass current_locale() unconditionally.
     */
    public function scopeForLocale(Builder $query, ?string $locale): Builder
    {
        if ($locale === null || $locale === '') {
            return $query;
        }

        $column = $this->getLocaleColumn();

        return $query->where(function (Builder $q) use ($column, $locale): void {
            $q->where($column, $locale)->orWhereNull($column);
        });
    }

    /**
     * Rows belonging to the given translation group. A null/empty group matches
     * nothing (an ungrouped record has no siblings to find).
     */
    public function scopeInTranslationGroup(Builder $query, ?string $group): Builder
    {
        if ($group === null || $group === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->getTranslationGroupColumn(), $group);
    }

    /**
     * All sibling translations in this record's group (including this record),
     * or just this record when it is not grouped.
     *
     * @return Collection<int, static>
     */
    public function translationSiblings(): Collection
    {
        $group = $this->translationGroup();

        if ($group === null) {
            /** @var Collection<int, static> $self */
            $self = new Collection([$this]);

            return $self;
        }

        return static::query()->inTranslationGroup($group)->get();
    }

    public function translationFor(string $locale): ?self
    {
        $group = $this->translationGroup();

        if ($group === null) {
            return $this->getLocale() === $locale ? $this : null;
        }

        return static::query()
            ->inTranslationGroup($group)
            ->where($this->getLocaleColumn(), $locale)
            ->first();
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->translationFor($locale) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function availableTranslationLocales(): array
    {
        $group = $this->translationGroup();

        if ($group === null) {
            $locale = $this->getLocale();

            return $locale !== null ? [$locale] : [];
        }

        return static::query()
            ->inTranslationGroup($group)
            ->pluck($this->getLocaleColumn())
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ensure this record has a persisted translation group, returning its key.
     */
    public function ensureTranslationGroup(): string
    {
        $group = $this->translationGroup();

        if ($group !== null) {
            return $group;
        }

        $group = (string) Str::uuid();
        $this->setAttribute($this->getTranslationGroupColumn(), $group);

        if ($this->exists) {
            $this->save();
        }

        return $group;
    }

    /**
     * Create and persist a sibling translation for $locale. The new record is a
     * structural copy of this one (the editor then translates its fields), linked
     * by the shared translation group. Deterministic and side-effect-free beyond
     * the insert.
     */
    public function createTranslationFor(string $locale): self
    {
        $group = $this->ensureTranslationGroup();

        /** @var static $clone */
        $clone = $this->replicate();
        $clone->setAttribute($this->getLocaleColumn(), $locale);
        $clone->setAttribute($this->getTranslationGroupColumn(), $group);
        $clone->save();

        return $clone;
    }
}
