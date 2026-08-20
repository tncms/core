<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Concerns;

use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Entity\LocalizedEntityManager;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * Makes a model a localized entity with a write API (Phase 8.2).
 *
 * Drop onto any model that owns translatable fields and declare the field list
 * via a `$localizedFields` property (and optionally a `$localizedType` to override
 * the default, which is the table name):
 *
 *   class Post extends Model implements HasLocalizedFieldsInterface {
 *       use HasLocalizedFields;
 *       protected array $localizedFields = ['title', 'slug', 'excerpt', 'content'];
 *   }
 *
 * Identity defaults: type → table name, key → primary key. All writes route
 * through the {@see LocalizedEntityManager} (the single entry point), so events,
 * validation and cache invalidation apply. When the model is deleted, its
 * translations are cascaded automatically.
 *
 * Pair with {@see ResolvesLocalizedFields} for the read/resolve helpers.
 */
trait HasLocalizedFields
{
    public function localizedType(): string
    {
        if (property_exists($this, 'localizedType') && is_string($this->localizedType) && $this->localizedType !== '') {
            return $this->localizedType;
        }

        return method_exists($this, 'getTable') ? (string) $this->getTable() : static::class;
    }

    public function localizedKey(): string
    {
        if (method_exists($this, 'getKey')) {
            return (string) $this->getKey();
        }

        return (string) ($this->id ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function localizedFieldNames(): array
    {
        if (property_exists($this, 'localizedFields') && is_array($this->localizedFields)) {
            return array_values(array_map('strval', $this->localizedFields));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $translations
     */
    public function saveTranslations(array $translations, ?string $locale = null): void
    {
        $this->localizedEntityManager()->saveTranslations($this, $translations, $locale);
    }

    /**
     * @return array<string, array<string, string>> locale => field => value
     */
    public function translations(): array
    {
        return $this->localizedEntityManager()->translations($this);
    }

    /**
     * @return array<string, LocalizedField> field => LocalizedField
     */
    public function localizedFields(): array
    {
        return $this->localizedEntityManager()->localizedFields($this);
    }

    /** Delete all translations for this entity, or one field. */
    public function deleteTranslations(?string $field = null): void
    {
        $this->localizedEntityManager()->delete($this, $field);
    }

    /**
     * Cascade-delete translations when the model is deleted. Registered only on
     * Eloquent models (guarded), and only affects models that use this trait.
     */
    public static function bootHasLocalizedFields(): void
    {
        if (! method_exists(static::class, 'deleted')) {
            return;
        }

        static::deleted(function ($model): void {
            if ($model instanceof LocalizedEntityInterface) {
                try {
                    app('cms.translation.entity')->delete($model);
                } catch (\Throwable) {
                    // Cascade is best-effort; never break the model delete.
                }
            }
        });
    }

    protected function localizedEntityManager(): LocalizedEntityManager
    {
        return app('cms.translation.entity');
    }
}
