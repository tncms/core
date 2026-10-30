<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Support\Plugin;

/**
 * Content ownership facade for plugins (CORE-CONTENT-EXT-R1), bound as
 * `cms.content_ownership`, re-based on the single authority in
 * CORE-UNIFIED-OWNERSHIP-R1.
 *
 * The owner is `cms_contents.managed_by` and nothing else. The owner's
 * per-content policy (fallback while inactive, persisted availability) lives
 * in `cms_content_owner_policies`, which has no owner column; a managed row
 * without a policy row uses the defaults (available, core_render).
 *
 * Creating owned content goes through ContentManager with an extension
 * context (signed-in actor, RBAC, publish permission, the owner's guard);
 * adopting existing content is ContentManager::claimOwnership() and needs the
 * explicitly granted `content.ownership.adopt`; operator transfer is
 * {@see ContentOwnershipAdmin}. Every ownership change is audited by Core.
 *
 * Visibility is persisted, so the single page and every discovery surface
 * read the same state with no plugin code at render time and no per-item
 * calls.
 */
final class ContentOwnershipRegistry
{
    public const POLICY_TABLE = 'cms_content_owner_policies';

    public const RETRY_DEFAULT = 300;

    private const RETRY_MIN = 60;

    private const RETRY_MAX = 86400;

    private bool $columnReady = false;

    private ?bool $policyReady = null;

    private CallSitePlugin $callSite;

    public function __construct(
        private readonly ExtensionManager $extensions,
        ExtensionIdentityResolver $identity,
    ) {
        $this->callSite = new CallSitePlugin($identity, [__FILE__]);
    }

    /**
     * Create a Core content item owned by the CALLING plugin, atomically with
     * its owner policy. Requires a signed-in actor allowed to create (and, for
     * published content, publish) it, and the plugin's registered
     * ContentWriteGuard — owned content is governed by its owner's guard.
     *
     * @param  array<string, mixed>  $data  ContentManager::create() attributes
     */
    public function createOwned(array $data, ContentFallbackPolicy $fallback = ContentFallbackPolicy::Hide404): Content
    {
        $this->requireCallerPlugin();

        return app('cms.content')->create($data, ContentWriteContext::extension()->claimingOwnership($fallback));
    }

    /** Persist the owner's availability state (owner only). */
    public function setAvailability(int $contentId, ContentAvailability $state, ?int $retryAfter = null): void
    {
        $retry = $state === ContentAvailability::TemporarilyUnavailable
            ? self::clampRetry($retryAfter ?? self::RETRY_DEFAULT)
            : null;

        $this->ownerPolicyUpdate($contentId, ['availability' => $state->value, 'retry_after' => $retry]);
    }

    /**
     * Change the stored inactive-owner fallback policy (owner only). An
     * operator-chosen fallback is not the plugin's to change (D-2).
     */
    public function setFallback(int $contentId, ContentFallbackPolicy $fallback): void
    {
        $this->ownerPolicyUpdate($contentId, ['fallback_policy' => $fallback->value], allowOperatorAssigned: false);
    }

    /** Hand the content back to Core (owner only, audited). */
    public function release(int $contentId): void
    {
        $owner = $this->requireCallerPlugin();

        if (! app('cms.content')->releaseOwnership($contentId, $owner)) {
            throw new ContentOwnershipException('not_owner');
        }
    }

    public function ownershipOf(int $contentId): ?ContentOwnership
    {
        if (! $this->ready()) {
            return null;
        }

        $owner = Content::withTrashed()->whereKey($contentId)->value('managed_by');

        if (! is_string($owner) || $owner === '') {
            return null;
        }

        $policy = $this->policyReady()
            ? DB::table(self::POLICY_TABLE)->where('content_id', $contentId)->first()
            : null;

        return new ContentOwnership(
            contentId: $contentId,
            owner: $owner,
            fallback: ContentFallbackPolicy::tryFrom((string) ($policy->fallback_policy ?? '')) ?? ContentFallbackPolicy::CoreRender,
            availability: ContentAvailability::tryFrom((string) ($policy->availability ?? '')) ?? ContentAvailability::Available,
            retryAfter: isset($policy->retry_after) ? (int) $policy->retry_after : null,
            operatorAssigned: (bool) ($policy->operator_assigned ?? false),
        );
    }

    public function ownerState(string $owner): OwnerState
    {
        if (in_array($owner, $this->activeOwnerSlugs(), true)) {
            return OwnerState::Active;
        }

        return $this->extensions->findPlugin($owner) !== null ? OwnerState::Inactive : OwnerState::Missing;
    }

    public function markerFor(int $contentId): ?ContentOwnershipMarker
    {
        return $this->markersFor([$contentId])[$contentId] ?? null;
    }

    /**
     * Batch markers for a listing: one query, owner state resolved once per
     * distinct owner.
     *
     * @param  list<int>  $contentIds
     * @return array<int, ContentOwnershipMarker>
     */
    public function markersFor(array $contentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $contentIds)));

        if ($ids === [] || ! $this->ready()) {
            return [];
        }

        $states = [];
        $markers = [];
        foreach (DB::table('cms_contents')->whereIn('id', $ids)->whereNotNull('managed_by')->get(['id', 'managed_by']) as $row) {
            $owner = (string) $row->managed_by;
            $states[$owner] ??= $this->ownerState($owner);
            $markers[(int) $row->id] = new ContentOwnershipMarker($owner, $states[$owner]);
        }

        return $markers;
    }

    /**
     * Exclude managed content that is not publicly available right now:
     *  - owner active and a non-available persisted state;
     *  - owner inactive/missing and a fallback other than core_render, or
     *    core_render that Core cannot honour because the current locale has
     *    no non-blank body (the page's rule, {@see coreRenderable()}).
     * Ordinary content (managed_by NULL) is never affected. Correlated
     * subqueries only; no per-item work.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrainDiscoverable(Builder $query, ?string $locale = null): Builder
    {
        if (! $this->ready()) {
            return $query;
        }

        $active = $this->activeOwnerSlugs();
        $locale ??= app('cms.language')->currentCode();
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        $owner = $model->qualifyColumn('managed_by');
        $policies = $this->policyReady() ? self::POLICY_TABLE : null;

        return $query->whereNot(static function ($hidden) use ($active, $key, $owner, $policies, $locale): void {
            $hidden->whereNotNull($owner)->where(static function ($why) use ($active, $key, $owner, $policies, $locale): void {
                if ($policies !== null) {
                    $why->where(static fn ($q) => $q->whereIn($owner, $active)
                        ->whereExists(static fn ($p) => $p->selectRaw('1')->from($policies)
                            ->whereColumn("{$policies}.content_id", $key)
                            ->where("{$policies}.availability", '!=', ContentAvailability::Available->value)));
                }

                $why->orWhere(static function ($q) use ($active, $key, $owner, $policies, $locale): void {
                    $q->whereNotIn($owner, $active)->where(static function ($inactive) use ($key, $policies, $locale): void {
                        if ($policies !== null) {
                            $inactive->whereExists(static fn ($p) => $p->selectRaw('1')->from($policies)
                                ->whereColumn("{$policies}.content_id", $key)
                                ->where("{$policies}.fallback_policy", '!=', ContentFallbackPolicy::CoreRender->value));
                        }

                        // Same rule as the page: a non-blank body in the current locale.
                        $inactive->orWhereNotExists(static fn ($t) => $t->selectRaw('1')->from('cms_content_translations as own_t')
                            ->whereColumn('own_t.content_id', $key)
                            ->where('own_t.locale', $locale)
                            ->whereNotNull('own_t.content')
                            ->whereRaw("TRIM(own_t.content) != ''"));
                    });
                });
            });
        });
    }

    /**
     * core_render safety: Core renders the stored Core body itself (theme
     * views, never plugin code — inactive plugins are not booted), so it can
     * honour core_render only when the locale's translation has a body.
     * Otherwise the item fails closed (404).
     */
    public function coreRenderable(Content $content, string $locale): bool
    {
        $body = DB::table('cms_content_translations')
            ->where('content_id', (int) $content->getKey())
            ->where('locale', $locale)
            ->value('content');

        return is_string($body) && trim($body) !== '';
    }

    /** @return list<string> installed AND active plugin slugs */
    public function activeOwnerSlugs(): array
    {
        return array_values(array_map(static fn (Plugin $p): string => $p->slug, $this->extensions->activePlugins()));
    }

    public static function clampRetry(int $seconds): int
    {
        return max(self::RETRY_MIN, min(self::RETRY_MAX, $seconds));
    }

    /** The ownership column exists (false only in the upgrade window). */
    public function ready(): bool
    {
        return $this->columnReady = $this->columnReady || Schema::hasColumn('cms_contents', 'managed_by');
    }

    private function policyReady(): bool
    {
        return $this->policyReady ??= Schema::hasTable(self::POLICY_TABLE);
    }

    private function requireCallerPlugin(): string
    {
        $caller = $this->callSite->resolve();

        if (! is_string($caller) || $caller === '') {
            throw new ContentOwnershipException('caller_not_active_plugin');
        }

        if (! $this->ready()) {
            throw new ContentOwnershipException('unsupported');
        }

        return $caller;
    }

    /**
     * Owner-only policy write, compare-and-set against managed_by under the
     * content row lock.
     *
     * @param  array<string, mixed>  $values
     */
    private function ownerPolicyUpdate(int $contentId, array $values, bool $allowOperatorAssigned = true): void
    {
        $owner = $this->requireCallerPlugin();

        if (! $this->policyReady()) {
            throw new ContentOwnershipException('unsupported');
        }

        DB::transaction(function () use ($contentId, $owner, $values, $allowOperatorAssigned): void {
            $managedBy = DB::table('cms_contents')->where('id', $contentId)->lockForUpdate()->value('managed_by');

            if ($managedBy !== $owner) {
                throw new ContentOwnershipException('not_owner');
            }

            $current = DB::table(self::POLICY_TABLE)->where('content_id', $contentId)->first(['operator_assigned']);

            if (! $allowOperatorAssigned && (bool) ($current->operator_assigned ?? false)) {
                throw new ContentOwnershipException('operator_controlled');
            }

            if ($current === null) {
                DB::table(self::POLICY_TABLE)->insert($values + [
                    'content_id' => $contentId,
                    'fallback_policy' => ContentFallbackPolicy::CoreRender->value,
                    'availability' => ContentAvailability::Available->value,
                    'operator_assigned' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table(self::POLICY_TABLE)->where('content_id', $contentId)->update($values + ['updated_at' => now()]);
            }
        });

        app('cms.sitemap')->invalidateCore();
    }
}
