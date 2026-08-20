<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Contracts;

/**
 * The minimal identity of a localized entity (Phase 8.2).
 *
 * An entity is anything that owns localized fields addressed by a stable
 * (type, id) pair — a Post, Product, Category, Menu, … The `type` maps onto the
 * storage namespace and the `id` onto the record identity, so two entities of
 * different types never collide even with the same id.
 *
 * This carries identity only; the field list lives in
 * {@see HasLocalizedFieldsInterface}.
 */
interface LocalizedEntityInterface
{
    /** The entity type — used as the storage namespace (e.g. 'post', 'product'). */
    public function localizedType(): string;

    /** The stable record identity within its type (e.g. the primary key). */
    public function localizedKey(): string;
}
