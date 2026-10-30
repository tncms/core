<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The single writer of `cms_content_authority_events`
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1, extended in CORE-UNIFIED-OWNERSHIP-R1).
 *
 * Called inside the same transaction as the ownership change it records, so
 * the audit row and the change commit or roll back together. `owner` keeps
 * its R1 meaning (the owner the event is about); `previous_owner`/`new_owner`
 * record the transition. Only identifiers, the trusted origin, a reason the
 * operator typed and a server-generated correlation id are stored — never
 * request payloads.
 *
 * Actions: created, claimed (adoption of existing content), transferred,
 * released, detached, reconciled.
 */
final class ContentAuthorityAudit
{
    private static ?bool $extended = null;

    private static ?string $processCorrelation = null;

    public static function record(
        int $contentId,
        string $action,
        string $owner,
        ?string $previousOwner,
        ?string $newOwner,
        string $origin,
        ?int $actorId,
        int $revision,
        ?string $reason,
    ): void {
        $row = [
            'content_id' => $contentId,
            'action' => $action,
            'owner' => $owner,
            'actor_id' => $actorId,
            'revision' => $revision,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'created_at' => now(),
        ];

        if (self::extended()) {
            $row += [
                'previous_owner' => $previousOwner,
                'new_owner' => $newOwner,
                'origin' => mb_substr($origin, 0, 32),
                'correlation_id' => self::correlationId(),
            ];
        }

        DB::table('cms_content_authority_events')->insert($row);
    }

    /** One id per HTTP request (set by the request marker) or per process. */
    public static function correlationId(): string
    {
        if (app()->bound('cms.correlation_id')) {
            return (string) app('cms.correlation_id');
        }

        return self::$processCorrelation ??= (string) Str::uuid();
    }

    /** @internal tests */
    public static function flush(): void
    {
        self::$extended = null;
        self::$processCorrelation = null;
    }

    private static function extended(): bool
    {
        return self::$extended ??= Schema::hasColumn('cms_content_authority_events', 'correlation_id');
    }
}
