<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Contracts\Auth\Authenticatable;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * The Core RBAC table for post / page content, shared by the write gate
 * ({@see ContentWriteGate}) and the read-only decision API
 * ({@see ContentActionAuthority}) so both judge with one rule set
 * (CORE-CONTENT-ACTION-AUTHORIZATION-R1).
 *
 * `view`, `create`, `edit` and `delete` use the lenient check (fail-open only
 * while RBAC is not initialised, as `cms_can()`); `*_others` and `publish` are
 * strict (never fail-open).
 *
 * @internal
 */
final class ContentRbac
{
    /** Content types whose access is governed by `{type}s.*` permissions. */
    public const PREFIXES = ['post' => 'posts', 'page' => 'pages'];

    private const STRICT_ABILITIES = ['edit_others', 'delete_others', 'publish'];

    public function __construct(private readonly PermissionManager $permissions) {}

    public static function prefixFor(string $type): ?string
    {
        return self::PREFIXES[$type] ?? null;
    }

    /** Ownership by author; a NULL author counts as someone else. */
    public static function isAuthor(mixed $authorId, Authenticatable $actor): bool
    {
        return $authorId !== null && (string) $authorId === (string) $actor->getAuthIdentifier();
    }

    /** Whether the actor holds `{prefix}.{ability}` with the strictness the table assigns. */
    public function grants(Authenticatable $actor, string $prefix, string $ability): bool
    {
        $permission = $prefix.'.'.$ability;

        return in_array($ability, self::STRICT_ABILITIES, true)
            ? $this->permissions->userCanStrict($actor, $permission)
            : $this->permissions->userCan($actor, $permission);
    }
}
