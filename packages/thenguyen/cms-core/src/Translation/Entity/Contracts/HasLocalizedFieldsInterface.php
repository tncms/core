<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Contracts;

/**
 * A localized entity that declares WHICH of its fields are translatable
 * (Phase 8.2). Implemented by models via the {@see \TheNguyen\CMS\Translation\Entity\Concerns\HasLocalizedFields}
 * trait.
 *
 * The declared field names are the contract between a module and the entity
 * layer: they bound reads (so a whole entity loads in one batched query) and
 * cascade deletes. Fields not declared are simply never managed here.
 */
interface HasLocalizedFieldsInterface extends LocalizedEntityInterface
{
    /**
     * The translatable field names for this entity (e.g. ['title','slug',
     * 'excerpt','content','seo_title','seo_description']).
     *
     * @return array<int, string>
     */
    public function localizedFieldNames(): array;
}
