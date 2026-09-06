<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\Auth;
use TheNguyen\CMS\Models\Role;

/**
 * EG-9 — safe author resolution for imported demo posts. Theme demo data NEVER
 * carries a raw user id; it declares a strategy token only. The resolver maps a
 * token to a real local user id using ONLY the strategies justified by Core's
 * actual auth/permission model:
 *
 *   - current_admin      → the authenticated admin running the import;
 *   - first_super_admin  → the earliest user holding the `super-admin` role;
 *   - configured_fallback→ config('cms.demo.fallback_author_id'), when it exists.
 *
 * An unknown/absent token defaults to `first_super_admin` (the deterministic
 * import-context author). For apply, resolution then falls back once more to
 * `first_super_admin`; if nothing resolves the caller classifies AUTHOR_UNRESOLVED
 * and fails closed. It NEVER auto-creates a privileged user and NEVER picks a
 * random user.
 */
class DemoAuthorResolver
{
    public const STRATEGIES = ['current_admin', 'first_super_admin', 'configured_fallback'];

    public function __construct(private readonly PermissionManager $permissions) {}

    /**
     * @return array{status: string, id: ?int, strategy: string}
     *                                                           status ∈ {resolved, unresolved}
     */
    public function resolve(?string $requested): array
    {
        $strategy = is_string($requested) && in_array($requested, self::STRATEGIES, true)
            ? $requested
            : 'first_super_admin';

        $id = $this->resolveStrategy($strategy);

        // Deterministic apply fallback: any requested strategy that cannot resolve
        // falls back to the first super-admin (the import-context authority).
        if ($id === null && $strategy !== 'first_super_admin') {
            $fallback = $this->resolveStrategy('first_super_admin');

            if ($fallback !== null) {
                return ['status' => 'resolved', 'id' => $fallback, 'strategy' => 'first_super_admin'];
            }
        }

        return [
            'status' => $id !== null ? 'resolved' : 'unresolved',
            'id' => $id,
            'strategy' => $strategy,
        ];
    }

    private function resolveStrategy(string $strategy): ?int
    {
        return match ($strategy) {
            'current_admin' => $this->currentAdminId(),
            'first_super_admin' => $this->firstSuperAdminId(),
            'configured_fallback' => $this->configuredFallbackId(),
            default => null,
        };
    }

    private function currentAdminId(): ?int
    {
        $user = Auth::user();

        if ($user === null || ! $this->permissions->isSuperAdmin($user)) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    private function firstSuperAdminId(): ?int
    {
        $role = Role::query()->where('slug', Role::SUPER_ADMIN)->first();

        if ($role === null) {
            return null;
        }

        $id = $role->users()->orderBy($role->users()->getModel()->getQualifiedKeyName())->value('id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    private function configuredFallbackId(): ?int
    {
        $configured = config('cms.demo.fallback_author_id');

        if (! (is_int($configured) || (is_string($configured) && ctype_digit($configured)))) {
            return null;
        }

        $model = config('auth.providers.users.model', \App\Models\User::class);
        $exists = $model::query()->whereKey((int) $configured)->exists();

        return $exists ? (int) $configured : null;
    }
}
