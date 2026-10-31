<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Models\Content;
use Throwable;

/**
 * The single-content gate (CORE-CONTENT-EXT-R1), bound as
 * `cms.content_response`. Runs after Core resolved the content and its
 * publication gate passed, before current-resource publication, SEO and any
 * rendering:
 *
 *   ownership → availability (persisted, then the owner's read-only probe)
 *   → status → SEO override
 *
 * Core content (managed_by NULL) returns an empty verdict and behaves exactly
 * as before. A non-200 outcome throws {@see ContentUnavailableHttpException},
 * so the status is final before a view exists. Preview never calls the gate.
 */
final class ContentResponsePipeline
{
    public function __construct(
        private readonly ContentOwnershipRegistry $ownership,
        private readonly ContentSeoRegistry $seo,
    ) {}

    public function prepare(Content $content, string $locale): PreparedContent
    {
        $owned = $this->ownership->ownershipOf((int) $content->getKey());

        if ($owned === null) {
            return new PreparedContent;
        }

        $state = $this->ownership->ownerState($owned->owner);

        if ($state === OwnerState::Active) {
            $this->enforce($owned->availability, $owned->retryAfter);
            // The probe can only restrict what the persisted state allowed (CORE-38-TOPLIST-SEAMS-R1).
            $this->enforce($this->probe($owned->owner, $content, $locale), null);
        } else {
            match ($owned->fallback) {
                // Core renders only what it can render itself (CORE-UNIFIED-OWNERSHIP-R1).
                ContentFallbackPolicy::CoreRender => $this->ownership->coreRenderable($content, $locale)
                    ? null
                    : throw ContentUnavailableHttpException::make(404),
                ContentFallbackPolicy::Hide404 => throw ContentUnavailableHttpException::make(404),
                ContentFallbackPolicy::Temporary503 => throw ContentUnavailableHttpException::make(
                    503,
                    ContentOwnershipRegistry::clampRetry($owned->retryAfter ?? ContentOwnershipRegistry::RETRY_DEFAULT),
                ),
            };
        }

        if ($state !== OwnerState::Active) {
            return new PreparedContent(ownership: new ContentOwnershipMarker($owned->owner, $state));
        }

        $override = $this->seoOverride($owned->owner, $content, $locale);

        return new PreparedContent(
            ownership: new ContentOwnershipMarker($owned->owner, $state),
            seoOverride: $override === false ? null : $override,
            neutralSeo: $override === false,
        );
    }

    private function enforce(ContentAvailability $availability, ?int $retryAfter): void
    {
        match ($availability) {
            ContentAvailability::Available => null,
            ContentAvailability::Unavailable => throw ContentUnavailableHttpException::make(404),
            ContentAvailability::Gone => throw ContentUnavailableHttpException::make(self::goneAllowed() ? 410 : 404),
            ContentAvailability::TemporarilyUnavailable => throw ContentUnavailableHttpException::make(
                503,
                ContentOwnershipRegistry::clampRetry($retryAfter ?? ContentOwnershipRegistry::RETRY_DEFAULT),
            ),
        };
    }

    /**
     * The active owner's read-only availability answer, if its provider has
     * one. Best-effort read-only on the default connection: the probe runs in
     * a transaction that is always rolled back to the level it started from,
     * and Core never persists the answer. A throw (including a TypeError for
     * a value the return type rejects), a probe that leaves the transaction
     * level unbalanced, or a failed rollback all fail closed as Unavailable.
     */
    private function probe(string $owner, Content $content, string $locale): ContentAvailability
    {
        $probe = $this->seo->providerFor($owner);

        if (! $probe instanceof ContentAvailabilityProbe) {
            return ContentAvailability::Available;
        }

        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $answer = $probe->availabilityFor(new ContentSeoRequest((int) $content->getKey(), (string) $content->type, $locale))
                ?? ContentAvailability::Available;

            if (DB::transactionLevel() !== $level + 1) {
                throw new \LogicException('probe changed the transaction level');
            }
        } catch (Throwable $e) {
            // Class only: a probe message may carry secrets.
            Log::warning('TN CMS content availability probe failed', [
                'owner' => $owner, 'content_id' => (int) $content->getKey(), 'exception' => $e::class,
            ]);
            $answer = ContentAvailability::Unavailable;
        }

        try {
            DB::rollBack($level);
        } catch (Throwable $e) {
            Log::warning('TN CMS content availability probe rollback failed', ['owner' => $owner, 'exception' => $e::class]);
            $answer = ContentAvailability::Unavailable;
        }

        return $answer;
    }

    /** 410 is opt-in through a Core setting (owner decision D-3). */
    public static function goneAllowed(): bool
    {
        return (bool) settings('content.allow_gone', false);
    }

    /**
     * The owner's sanitized override, null to keep Core's metadata, or false
     * when the provider failed: Core then renders a neutral noindex head, since
     * the editable Core metadata may have drifted from what the owner approved.
     */
    private function seoOverride(string $owner, Content $content, string $locale): ContentSeoOverride|false|null
    {
        $provider = $this->seo->providerFor($owner);

        if ($provider === null) {
            return null;
        }

        try {
            $override = $provider->seoFor(new ContentSeoRequest((int) $content->getKey(), (string) $content->type, $locale));
        } catch (Throwable $e) {
            // Class only: a provider message may carry secrets.
            Log::warning('TN CMS content SEO provider failed', [
                'owner' => $owner, 'content_id' => (int) $content->getKey(), 'exception' => $e::class,
            ]);

            return false;
        }

        $clean = $override?->sanitized();

        if ($provider instanceof ContentSitemapPolicy) {
            $clean = $this->applyIndexability($provider, $owner, $content, $locale, $clean);
        }

        return $clean === null || $clean->isEmpty() ? null : $clean;
    }

    /**
     * The owner's batched indexability answer (the one the sitemap uses) also
     * decides the page: an excluded id is noindex here too. A throw fails
     * closed exactly as in the sitemap. A provider whose override says noindex
     * for an id its policy reports indexable is logged: that is a contract
     * violation Core cannot repair in the sitemap without a per-item call.
     */
    private function applyIndexability(ContentSitemapPolicy $policy, string $owner, Content $content, string $locale, ?ContentSeoOverride $override): ?ContentSeoOverride
    {
        $id = (int) $content->getKey();

        try {
            $answer = $policy instanceof LocalizedContentSitemapPolicy
                ? $policy->sitemapExcludedIdsForLocale([$id], $locale)
                : $policy->sitemapExcludedIds([$id]);
            $excluded = in_array($id, array_map('intval', $answer), true);
        } catch (Throwable $e) {
            Log::warning('TN CMS sitemap policy failed', ['owner' => $owner, 'exception' => $e::class]);
            $excluded = true;
        }

        if ($excluded) {
            return ($override ?? new ContentSeoOverride)->withNoIndex();
        }

        if ($override?->robots?->noindex()) {
            Log::warning('TN CMS content indexability mismatch', ['owner' => $owner, 'content_id' => $id]);
        }

        return $override;
    }
}
