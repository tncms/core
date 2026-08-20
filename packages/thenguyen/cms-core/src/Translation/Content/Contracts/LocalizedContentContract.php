<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content\Contracts;

use TheNguyen\CMS\Translation\Content\LocalizedFieldDefinition;

/**
 * The canonical declaration a module makes to describe its multilingual content
 * (Phase 8.4): its content type and the localized fields it owns.
 *
 * A module implements this and registers itself with the
 * {@see \TheNguyen\CMS\Translation\Content\LocalizedContentRegistry}. The type
 * string is the same as the entity type used by the Phase 8.2 storage
 * (`{type}::{id}:{field}`), so the contract, the entity layer and the admin
 * components all speak of the same fields.
 */
interface LocalizedContentContract
{
    /** The content type (e.g. 'post', 'product') — the storage namespace. */
    public function localizedContentType(): string;

    /**
     * The localized field definitions this content type owns.
     *
     * @return array<int, LocalizedFieldDefinition>
     */
    public function localizedFieldDefinitions(): array;
}
