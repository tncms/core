<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Models;

use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Revision\Enums\RevisionSource;
use TheNguyen\CMS\Revision\Exceptions\RevisionException;

/**
 * One immutable row in the append-only, locale-scoped revision history.
 *
 * A persisted revision can never be updated or deleted through Eloquent — both
 * are guarded and throw. The ONLY sanctioned removal is retention pruning, done
 * at the query-builder level (see {@see \TheNguyen\CMS\Revision\Repositories\DatabaseRevisionRepository::pruneKeeping()}),
 * which bypasses these model events. A correction is a new revision, never an
 * edit of an old one — the append-only doctrine.
 *
 * @property int $id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $locale
 * @property int $revision_number
 * @property array<string, mixed>|null $snapshot
 * @property int|null $author_id
 * @property string $source
 * @property string|null $reason
 * @property string $checksum
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Revision extends Model
{
    protected $table = 'cms_revisions';

    /**
     * Rows carry created_at only — there is no updated_at, because a row is never
     * updated. created_at is set explicitly by the repository on append.
     */
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'locale',
        'revision_number',
        'snapshot',
        'author_id',
        'source',
        'reason',
        'checksum',
        'created_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'author_id' => 'integer',
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Append-only: a persisted revision can never be rewritten or deleted
        // through the model. Retention pruning uses a query-builder bulk delete
        // that bypasses these events by design.
        static::updating(function (self $revision): void {
            throw new RevisionException(
                'Revisions are immutable and cannot be updated (id: '.$revision->getKey().').'
            );
        });

        static::deleting(function (self $revision): void {
            throw new RevisionException(
                'Revisions are append-only and cannot be deleted (id: '.$revision->getKey().').'
            );
        });
    }

    public function sourceEnum(): RevisionSource
    {
        return RevisionSource::tryFrom((string) $this->source) ?? RevisionSource::System;
    }
}
