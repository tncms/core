<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use TheNguyen\CMS\Media\Frontend\FrontendMediaException;
use TheNguyen\CMS\Media\Frontend\FrontendMediaLibrary;
use TheNguyen\CMS\Media\Frontend\FrontendMediaQuery;

/**
 * JSON API of the owner-scoped frontend media library (CORE-40-FRONTEND-MEDIA-R1).
 *
 *   GET  /account/media   ?q=&cursor=&per_page=   → 200 {data: MediaSummary[], meta: {next_cursor, per_page}}
 *   POST /account/media   multipart `file`        → 201 {data: MediaSummary}
 *   DELETE /account/media/{id}                     → 200 {data: {deleted: true, quota: {used_bytes, limit_bytes}}}
 *                                                   (CORE-P12-1; 404 media_not_found, 409 media_in_use)
 *
 * Errors: {error: {code, message[, quota]}} with 403 media_permission_denied, 422 media_invalid /
 * media_invalid_query / media_quota_exceeded, 429 media_rate_limited, 503 media_busy,
 * 500 media_upload_failed. Guests get 401 (JSON) from cms.auth. Every response is no-store.
 *
 * The controller holds no authorization logic of its own: the service decides with the
 * signed-in user. Request input never selects an owner.
 */
final class AccountMediaController extends Controller
{
    public function __construct(private readonly FrontendMediaLibrary $library) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', (string) FrontendMediaQuery::DEFAULT_PER_PAGE);
            if (! is_string($perPage) || preg_match('/^[0-9]{1,3}$/', $perPage) !== 1) {
                throw FrontendMediaException::invalidQuery(__('The page size is invalid.'));
            }
            $search = $request->query('q');
            $cursor = $request->query('cursor');
            if (($search !== null && ! is_string($search)) || ($cursor !== null && ! is_string($cursor))) {
                throw FrontendMediaException::invalidQuery(__('The query is invalid.'));
            }

            $page = $this->library->listOwn($request->user(), new FrontendMediaQuery(
                search: $search,
                cursor: $cursor === '' ? null : $cursor,
                perPage: (int) $perPage,
            ));

            return $this->json($page->toArray(), 200);
        } catch (FrontendMediaException $e) {
            return $this->error($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                // Permission first, so an unauthorized caller learns nothing about validation.
                if (! $this->library->canUploadOwn($request->user())) {
                    throw FrontendMediaException::permissionDenied();
                }

                throw FrontendMediaException::invalid(__('Choose one image file to upload.'));
            }

            $summary = $this->library->uploadOwn($request->user(), $file);

            return $this->json(['data' => $summary->toArray()], 201);
        } catch (FrontendMediaException $e) {
            return $this->error($e);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            // Positional route parameters shift under the {locale} prefix: read it by name.
            $raw = $request->route('media');
            if (! $this->library->canDeleteOwn($request->user())) {
                throw FrontendMediaException::permissionDenied();
            }
            if (! is_string($raw) || preg_match('/^[1-9][0-9]{0,17}$/D', $raw) !== 1) {
                throw FrontendMediaException::notFound();
            }

            $this->library->deleteOwn($request->user(), (int) $raw);

            return $this->json(['data' => ['deleted' => true, 'quota' => $this->library->quotaUsage($request->user())]], 200);
        } catch (FrontendMediaException $e) {
            return $this->error($e);
        }
    }

    private function error(FrontendMediaException $e): JsonResponse
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details;
        $headers = $e->status === 503 ? ['Retry-After' => '2'] : [];

        return $this->json(['error' => $error], $e->status, $headers);
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, $headers + [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
