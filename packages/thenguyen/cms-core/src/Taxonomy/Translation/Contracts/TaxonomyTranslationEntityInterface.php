<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Contracts;

/**
 * The entity-side contract for a translatable taxonomy entity (Phase 9.2B).
 *
 * A taxonomy entity is a single term of SOME taxonomy — Category, Tag, Brand,
 * Genre, Location, Series, Collection, Product Category, Forum Category, … — that
 * the Translation Platform can localize. The platform treats every such entity
 * IDENTICALLY: it resolves everything through this contract and the taxonomy
 * TYPE, and it NEVER branches on a concrete taxonomy (no `if type === 'category'`).
 *
 * OWNERSHIP (see TAXONOMY-TYPE-ARCHITECTURE.md): the entity owns identity,
 * hierarchy, visibility, ordering and relationships. The translation rows own the
 * localized name/slug/description/SEO. This interface exposes ONLY the entity-side
 * facts the platform needs to translate the entity; it exposes NO localized text
 * (that is the read contract's job) and NO persistence (the driver/write contract).
 *
 * This is a STABLE SDK contract: a plugin taxonomy implements it and adopts the
 * whole Translation Platform lifecycle without any Core or platform change.
 *
 * @since 1.0
 *
 * @stable
 */
interface TaxonomyTranslationEntityInterface
{
    /**
     * The stable identifier used as the translation driver's entity id
     * (the term's primary key). Never a localized value.
     */
    public function getTranslationKey(): int|string;

    /**
     * The generic taxonomy type this entity belongs to — e.g. 'category', 'tag',
     * 'brand', 'genre'. It is an OPAQUE selector: the platform stores and forwards
     * it but never branches on a specific value.
     */
    public function getTaxonomyType(): string;

    /**
     * The locales for which this entity may hold a translation. Drives fallback
     * scope and (later) revision scope. Typically the site's public locales.
     *
     * @return array<int, string>
     */
    public function translatableLocales(): array;
}
