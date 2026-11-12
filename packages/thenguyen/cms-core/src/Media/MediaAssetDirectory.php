<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use TheNguyen\CMS\Media\Frontend\FrontendMediaLibrary;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Seo\CanonicalHost;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Asset-by-ID lookup for plugins — `app('cms.media.assets')` (CORE-P12-1).
 *
 * Read-only and bounded: at most {@see MAX_IDS} distinct ids per call, one query, no per-row
 * queries. Three explicit scopes; the caller chooses the one matching the surface it serves:
 *
 * - {@see lookupPublic()}  render published content to anyone. Non-deleted assets on the public
 *                          disk. Use only for ids already stored in content the visitor may see.
 * - {@see lookupOwned()}   authoring: the actor's own non-deleted assets (`media.view_own`, strict).
 *                          Validate every submitted id with this before saving a user's document.
 * - {@see lookupManaged()} editorial/admin: any owner's non-deleted asset (`media.view`, strict).
 *
 * Without the permission every id is `missing`; unknown, deleted, foreign and private ids are
 * `missing` too, so the lookup reveals nothing beyond the requested scope. Client ids are parsed
 * strictly and never trusted. Results never expose paths, disks or uploader ids.
 */
final class MediaAssetDirectory
{
    public const MAX_IDS = 200;

    /** @var list<string> */
    private const COLUMNS = ['id', 'url', 'mime_type', 'extension', 'size', 'width', 'height', 'alt', 'title', 'created_at'];

    public function __construct(private readonly PermissionManager $permissions) {}

    /**
     * @param  iterable<mixed>  $ids
     *
     * @throws InvalidMediaLookup
     */
    public function lookupPublic(iterable $ids): MediaLookupResult
    {
        return $this->lookup($ids, static fn (Builder $q): Builder => $q);
    }

    /**
     * @param  iterable<mixed>  $ids
     *
     * @throws InvalidMediaLookup
     */
    public function lookupOwned(?Authenticatable $actor, iterable $ids): MediaLookupResult
    {
        $owner = self::ownerId($actor);
        $allowed = $owner !== null && $this->permissions->userCanStrict($actor, FrontendMediaLibrary::PERMISSION_VIEW_OWN);

        return $this->lookup($ids, static fn (Builder $q): Builder => $q->where('uploaded_by', $owner), $allowed);
    }

    /**
     * @param  iterable<mixed>  $ids
     *
     * @throws InvalidMediaLookup
     */
    public function lookupManaged(?Authenticatable $actor, iterable $ids): MediaLookupResult
    {
        $allowed = self::ownerId($actor) !== null && $this->permissions->userCanStrict($actor, FrontendMediaLibrary::PERMISSION_VIEW_ANY);

        return $this->lookup($ids, static fn (Builder $q): Builder => $q, $allowed);
    }

    public function findPublic(int|string $id): ?MediaAsset
    {
        return array_values($this->lookupPublic([$id])->found)[0] ?? null;
    }

    /** Absolute delivery URL on the configured canonical origin (APP_URL), or null when unknown. */
    public function absoluteUrl(MediaAsset $asset): ?string
    {
        if (preg_match('#^https?://#i', $asset->url) === 1) {
            return $asset->url;
        }
        $origin = CanonicalHost::configured()['origin'] ?? null;

        return is_string($origin) && $origin !== '' && str_starts_with($asset->url, '/') ? $origin.$asset->url : null;
    }

    /**
     * @param  iterable<mixed>  $ids
     * @param  callable(Builder): Builder  $scope
     */
    private function lookup(iterable $ids, callable $scope, bool $allowed = true): MediaLookupResult
    {
        [$wanted, $invalid] = self::parseIds($ids);

        if (count($wanted) > self::MAX_IDS) {
            throw new InvalidMediaLookup(InvalidMediaLookup::REASON_TOO_MANY_IDS);
        }
        if ($wanted === [] || ! $allowed) {
            return new MediaLookupResult([], $wanted, $invalid);
        }

        $rows = $scope(Media::query())
            ->whereKey($wanted)
            ->where('disk', 'public')
            ->get(self::COLUMNS)
            ->keyBy(static fn (Media $m): int => (int) $m->getKey());

        $found = [];
        $missing = [];
        foreach ($wanted as $id) {
            $row = $rows->get($id);
            $row instanceof Media ? $found[$id] = MediaAsset::fromMedia($row) : $missing[] = $id;
        }

        return new MediaLookupResult($found, $missing, $invalid);
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array{0: list<int>, 1: list<string>}
     */
    private static function parseIds(iterable $ids): array
    {
        $wanted = [];
        $invalid = [];
        foreach ($ids as $raw) {
            $id = match (true) {
                is_int($raw) => $raw,
                is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D', $raw) === 1 => (int) $raw,
                default => 0,
            };
            if ($id < 1) {
                $invalid[] = mb_substr(is_scalar($raw) ? (string) $raw : get_debug_type($raw), 0, 32);

                continue;
            }
            $wanted[$id] = $id;
        }

        return [array_values($wanted), $invalid];
    }

    private static function ownerId(?Authenticatable $actor): ?int
    {
        $id = $actor?->getAuthIdentifier();

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
