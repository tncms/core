<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use TheNguyen\CMS\Media\MediaUsage;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Services\MediaManager;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Owner-scoped frontend media library — `app('cms.media.frontend')` (CORE-40-FRONTEND-MEDIA-R1).
 *
 * Stable extension API for frontend authoring (Toplist, Knowledge Library, Ecommerce, …):
 *
 * - {@see listOwn()}            the actor's own non-deleted images, keyset-paged (≤ 48), newest first;
 * - {@see uploadOwn()}          store one image owned by the actor (validation, dimension ceiling,
 *                               per-user quota under a cross-process lock);
 * - {@see resolveForActor()}    authorize one reference to the actor's own image (null = not allowed);
 * - {@see resolveManyForActor()} the same for a batch of ids, in one query;
 * - {@see resolvePrivileged()}  explicit cross-owner resolution for holders of `media.view`;
 *                               never used by listOwn() and never reachable from the HTTP API.
 *
 * Authorization is decided HERE on every call with the strict RBAC check (never fail-open):
 * `media.view_own` for listing/resolution, `media.upload_own` for uploads. Neither implies nor
 * requires `admin.access`. Ownership is `cms_media.uploaded_by`, always taken from the actor
 * object the caller passes — never from request input. Callers must pass the signed-in user.
 *
 * Files are stored by {@see MediaManager} on the public disk. Ownership limits discovery and
 * selection through this API; it does not make a known public URL private.
 *
 * Detaching an image from a document stays the consumer's own operation. {@see deleteOwn()}
 * (CORE-P12-1, `media.delete_own`) removes the actor's own asset itself, and is refused while the
 * asset is still referenced ({@see MediaUsage}).
 */
final class FrontendMediaLibrary
{
    public const PERMISSION_VIEW_OWN = 'media.view_own';

    public const PERMISSION_UPLOAD_OWN = 'media.upload_own';

    public const PERMISSION_VIEW_ANY = 'media.view';

    /** CORE-P12-1: delete one's own media (frontend). Never implies admin.access. */
    public const PERMISSION_DELETE_OWN = 'media.delete_own';

    /** Frontend uploads are images only; each extension must match the sniffed content type. */
    public const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public const MAX_RESOLVE_BATCH = 200;

    private const CURSOR_PREFIX = 'm1:';

    private const LOCK_ATTEMPTS = 3;

    /** @var list<string> */
    private const SUMMARY_COLUMNS = ['id', 'url', 'width', 'height', 'mime_type', 'title', 'alt', 'created_at'];

    public function __construct(
        private readonly MediaManager $media,
        private readonly PermissionManager $permissions,
        private readonly ?MediaUsage $usage = null,
    ) {}

    public function canViewOwn(?Authenticatable $actor): bool
    {
        return $this->ownerId($actor) !== null && $this->permissions->userCanStrict($actor, self::PERMISSION_VIEW_OWN);
    }

    public function canUploadOwn(?Authenticatable $actor): bool
    {
        return $this->ownerId($actor) !== null && $this->permissions->userCanStrict($actor, self::PERMISSION_UPLOAD_OWN);
    }

    public function canDeleteOwn(?Authenticatable $actor): bool
    {
        return $this->ownerId($actor) !== null && $this->permissions->userCanStrict($actor, self::PERMISSION_DELETE_OWN);
    }

    /**
     * Delete one of the actor's own media items (any type, CORE-P12-1). The owner row is locked
     * for the decision, the asset must not be referenced, then {@see MediaManager::delete()}
     * soft-deletes the row and removes the file after COMMIT. The freed bytes leave the quota.
     *
     * @throws FrontendMediaException media_permission_denied | media_not_found | media_in_use
     *                                | media_busy | media_delete_failed
     */
    public function deleteOwn(Authenticatable $actor, int $mediaId): void
    {
        if (! $this->canDeleteOwn($actor)) {
            throw FrontendMediaException::permissionDenied();
        }
        $ownerId = (int) $this->ownerId($actor);

        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($actor, $ownerId, $mediaId): void {
                    $this->lockOwner($actor, $ownerId);

                    $media = $mediaId > 0
                        ? Media::query()->whereKey($mediaId)->where('uploaded_by', $ownerId)->first()
                        : null;
                    if (! $media instanceof Media) {
                        throw FrontendMediaException::notFound();
                    }
                    if (($this->usage ?? new MediaUsage)->inUse($media)) {
                        throw FrontendMediaException::inUse();
                    }
                    if (! $this->media->delete($media)) {
                        throw FrontendMediaException::inUse();   // a listener vetoed the deletion
                    }
                });

                return;
            } catch (FrontendMediaException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($this->isLockContention($e) && $attempt < self::LOCK_ATTEMPTS) {
                    usleep(50_000 * $attempt);

                    continue;
                }
                if ($this->isLockContention($e)) {
                    throw FrontendMediaException::busy($e);
                }
                report($e);

                throw FrontendMediaException::deleteFailed($e);
            }
        }
    }

    /**
     * @throws FrontendMediaException media_permission_denied | media_invalid_query
     */
    public function listOwn(Authenticatable $actor, FrontendMediaQuery $query): FrontendMediaPage
    {
        if (! $this->canViewOwn($actor)) {
            throw FrontendMediaException::permissionDenied();
        }
        if (mb_strlen($query->search) > FrontendMediaQuery::MAX_SEARCH_LENGTH) {
            throw FrontendMediaException::invalidQuery(__('The search text is too long.'));
        }
        $after = $query->cursor !== null ? $this->decodeCursor($query->cursor) : null;

        $rows = $this->ownImages($actor)
            ->when($after !== null, static fn (Builder $q) => $q->where('id', '<', $after))
            ->when($query->search !== '', fn (Builder $q) => $this->applySearch($q, $query->search))
            ->orderByDesc('id')
            ->limit($query->perPage + 1)
            ->get(self::SUMMARY_COLUMNS);

        $hasMore = $rows->count() > $query->perPage;
        $items = $rows->take($query->perPage)->values();
        $next = $hasMore ? $this->encodeCursor((int) $items->last()->getKey()) : null;

        return new FrontendMediaPage(
            $items->map(static fn (Media $m): MediaSummary => MediaSummary::fromMedia($m))->all(),
            $next,
            $query->perPage,
        );
    }

    /** Own, non-deleted image, or null (also null when the actor lacks media.view_own). */
    public function resolveForActor(Authenticatable $actor, int $mediaId): ?MediaSummary
    {
        if ($mediaId < 1 || ! $this->canViewOwn($actor)) {
            return null;
        }

        $media = $this->ownImages($actor)->whereKey($mediaId)->first(self::SUMMARY_COLUMNS);

        return $media instanceof Media ? MediaSummary::fromMedia($media) : null;
    }

    /**
     * Authorize several references at once. Returns only the authorized ones, keyed by id
     * (ascending). Unknown, foreign, deleted and non-image ids are simply absent.
     *
     * @param  array<int, mixed>  $mediaIds
     * @return array<int, MediaSummary>
     *
     * @throws FrontendMediaException media_invalid_query when more than 200 ids are asked for
     */
    public function resolveManyForActor(Authenticatable $actor, array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $mediaIds),
            static fn (int $id): bool => $id > 0,
        )));
        if (count($ids) > self::MAX_RESOLVE_BATCH) {
            throw FrontendMediaException::invalidQuery(__('Too many media references.'));
        }
        if ($ids === [] || ! $this->canViewOwn($actor)) {
            return [];
        }

        $resolved = [];
        foreach ($this->ownImages($actor)->whereKey($ids)->orderBy('id')->get(self::SUMMARY_COLUMNS) as $media) {
            $resolved[(int) $media->getKey()] = MediaSummary::fromMedia($media);
        }

        return $resolved;
    }

    /**
     * Explicit, privileged cross-owner resolution: any non-deleted image, for an actor holding
     * `media.view` (strict). For server-side consumers that must show media chosen by someone
     * else (e.g. an editor reviewing an author's draft). Not exposed over HTTP.
     */
    public function resolvePrivileged(Authenticatable $actor, int $mediaId): ?MediaSummary
    {
        if ($mediaId < 1 || $this->ownerId($actor) === null
            || ! $this->permissions->userCanStrict($actor, self::PERMISSION_VIEW_ANY)) {
            return null;
        }

        $media = Media::query()->whereKey($mediaId)->where('mime_type', 'like', 'image/%')->first(self::SUMMARY_COLUMNS);

        return $media instanceof Media ? MediaSummary::fromMedia($media) : null;
    }

    /**
     * The actor's quota: bytes of their non-deleted media (any origin) and the current limit.
     *
     * @return array{used_bytes: int, limit_bytes: int}
     */
    public function quotaUsage(Authenticatable $actor): array
    {
        $id = $this->ownerId($actor);

        return [
            'used_bytes' => $id === null ? 0 : $this->usedBytes($id),
            'limit_bytes' => FrontendMediaLimits::quotaBytes(),
        ];
    }

    /**
     * Store one image owned by the actor.
     *
     * @throws FrontendMediaException media_permission_denied | media_invalid | media_quota_exceeded
     *                                | media_busy | media_upload_failed
     */
    public function uploadOwn(Authenticatable $actor, UploadedFile $file): MediaSummary
    {
        if (! $this->canUploadOwn($actor)) {
            throw FrontendMediaException::permissionDenied();
        }
        $ownerId = (int) $this->ownerId($actor);

        $size = $this->preflight($file);

        for ($attempt = 1; ; $attempt++) {
            $created = null;

            try {
                $media = DB::transaction(function () use ($actor, $ownerId, $file, $size, &$created): Media {
                    $this->lockOwner($actor, $ownerId);

                    $used = $this->usedBytes($ownerId);
                    $limit = FrontendMediaLimits::quotaBytes();
                    if ($used + $size > $limit) {
                        throw FrontendMediaException::quotaExceeded($used, $limit, $size);
                    }

                    $created = $this->media->upload($file, ['uploaded_by' => $ownerId]);
                    if (! $created->exists) {
                        // A model listener cancelled the insert: treat as a storage failure.
                        throw new \RuntimeException('The media record was not saved.');
                    }

                    return $created;
                });

                return MediaSummary::fromMedia($media);
            } catch (FrontendMediaException $e) {
                throw $e;
            } catch (ValidationException $e) {
                throw FrontendMediaException::invalid($this->firstMessage($e));
            } catch (\Throwable $e) {
                // The row is rolled back; MediaManager removes its file when the record step
                // fails, and a failed COMMIT is handled here.
                $this->discardFile($created);

                if ($this->isLockContention($e)) {
                    if ($attempt < self::LOCK_ATTEMPTS) {
                        usleep(50_000 * $attempt);

                        continue;
                    }

                    throw FrontendMediaException::busy($e);
                }

                report($e);

                throw FrontendMediaException::uploadFailed($e);
            }
        }
    }

    // ------------------------------------------------------------------ internals

    /** Image-specific checks before any lock is taken or byte is written. Returns the size. */
    private function preflight(UploadedFile $file): int
    {
        if (! $file->isValid()) {
            throw FrontendMediaException::invalid(__('The file could not be uploaded.'));
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = array_map('strtolower', (array) config('cms.media.allowed_extensions', []));
        $denied = array_map('strtolower', (array) config('cms.media.denied_extensions', []));
        $expectedMime = self::IMAGE_TYPES[$extension] ?? null;

        if ($extension === '' || $expectedMime === null || in_array($extension, $denied, true) || ! in_array($extension, $allowed, true)) {
            throw FrontendMediaException::invalid(__('Only JPG, PNG, GIF and WebP images can be uploaded.'));
        }

        $size = (int) ($file->getSize() ?: 0);
        if ($size <= 0) {
            throw FrontendMediaException::invalid(__('The uploaded file is empty.'));
        }

        $path = $file->getRealPath();
        // Content sniffing only (finfo); the client-declared MIME is never trusted here.
        $sniffed = $path !== false ? strtolower((string) $file->getMimeType()) : '';
        if ($sniffed !== $expectedMime) {
            throw FrontendMediaException::invalid(__('The file content does not match its extension.'));
        }

        $info = $path !== false ? @getimagesize($path) : false;
        if (! is_array($info) || image_type_to_mime_type((int) ($info[2] ?? 0)) !== $expectedMime) {
            throw FrontendMediaException::invalid(__('The file is not a readable image.'));
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $max = FrontendMediaLimits::maxDimension();
        if ($width < 1 || $height < 1) {
            throw FrontendMediaException::invalid(__('The file is not a readable image.'));
        }
        if ($width > $max || $height > $max) {
            throw FrontendMediaException::invalid(__('The image is larger than :max pixels on a side.', ['max' => $max]));
        }

        return $size;
    }

    /**
     * Serialize quota checks per owner across processes: a row lock on the owner's user record
     * (MySQL/MariaDB/PostgreSQL/SQL Server: SELECT … FOR UPDATE). SQLite has no row locks, so a
     * no-op UPDATE takes the database write lock for the rest of the transaction instead.
     */
    private function lockOwner(Authenticatable $actor, int $ownerId): void
    {
        $model = $actor instanceof Model ? $actor : null;
        $connection = $model?->getConnectionName();
        $table = $model?->getTable() ?? 'users';
        $key = $model?->getKeyName() ?? 'id';
        $db = DB::connection($connection);

        if ($db->getDriverName() === 'sqlite') {
            $db->table($table)->where($key, $ownerId)->update([$key => $db->raw($db->getQueryGrammar()->wrap($key))]);

            return;
        }

        $db->table($table)->where($key, $ownerId)->lockForUpdate()->first([$key]);
    }

    private function usedBytes(int $ownerId): int
    {
        return (int) Media::query()->where('uploaded_by', $ownerId)->sum('size');
    }

    /** @return Builder<Media> */
    private function ownImages(Authenticatable $actor): Builder
    {
        return Media::query()
            ->where('uploaded_by', (int) $this->ownerId($actor))
            ->where('mime_type', 'like', 'image/%');
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return $query->where(static function (Builder $q) use ($pattern): void {
            foreach (['original_filename', 'filename', 'title'] as $column) {
                $q->orWhereRaw($q->getQuery()->getGrammar()->wrap($column).' LIKE ? ESCAPE ?', [$pattern, '\\']);
            }
        });
    }

    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode(self::CURSOR_PREFIX.$id), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        $decoded = preg_match('/^[A-Za-z0-9_-]{1,40}$/', $cursor) === 1
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;

        if (! is_string($decoded) || preg_match('/^'.preg_quote(self::CURSOR_PREFIX, '/').'([1-9][0-9]{0,17})$/', $decoded, $m) !== 1) {
            throw FrontendMediaException::invalidQuery(__('The page cursor is invalid.'));
        }

        return (int) $m[1];
    }

    private function ownerId(?Authenticatable $actor): ?int
    {
        $id = $actor?->getAuthIdentifier();

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function discardFile(?Media $media): void
    {
        if ($media instanceof Media && is_string($media->path) && $media->path !== '') {
            File::delete(public_path($media->path));
        }
    }

    private function isLockContention(\Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // MySQL/MariaDB 1205 lock wait timeout, 1213 deadlock; SQLite 5/6 busy/locked; SQLSTATE 40001.
        return in_array($driverCode, [1205, 1213], true)
            || ($e->getCode() === '40001')
            || (str_contains($e->getMessage(), 'database is locked'));
    }

    private function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return __('The file was rejected.');
    }
}
