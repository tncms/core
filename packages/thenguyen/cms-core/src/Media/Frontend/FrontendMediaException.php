<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

use RuntimeException;

/**
 * A bounded, leak-free failure of the frontend media service.
 *
 * `errorCode` is one of the documented codes; `status` is the HTTP status the JSON API maps it
 * to; `details` carries only safe, documented data (e.g. quota figures of the actor's OWN usage).
 */
final class FrontendMediaException extends RuntimeException
{
    public const PERMISSION_DENIED = 'media_permission_denied';

    public const INVALID = 'media_invalid';

    public const INVALID_QUERY = 'media_invalid_query';

    public const QUOTA_EXCEEDED = 'media_quota_exceeded';

    public const BUSY = 'media_busy';

    public const UPLOAD_FAILED = 'media_upload_failed';

    /** CORE-P12-1: unknown, deleted and foreign ids are indistinguishable. */
    public const NOT_FOUND = 'media_not_found';

    public const IN_USE = 'media_in_use';

    public const DELETE_FAILED = 'media_delete_failed';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permissionDenied(): self
    {
        return new self(self::PERMISSION_DENIED, 403, __('You do not have permission to use this media library.'));
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, 422, $message);
    }

    public static function invalidQuery(string $message): self
    {
        return new self(self::INVALID_QUERY, 422, $message);
    }

    public static function quotaExceeded(int $usedBytes, int $limitBytes, int $fileBytes): self
    {
        return new self(self::QUOTA_EXCEEDED, 422, __('Your media storage quota is full.'), [
            'quota' => ['used_bytes' => $usedBytes, 'limit_bytes' => $limitBytes, 'file_bytes' => $fileBytes],
        ]);
    }

    public static function busy(?\Throwable $previous = null): self
    {
        return new self(self::BUSY, 503, __('The media library is busy. Please try again.'), [], $previous);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404, __('The media item was not found.'));
    }

    public static function inUse(): self
    {
        return new self(self::IN_USE, 409, __('This media item is still used and cannot be deleted.'));
    }

    public static function deleteFailed(?\Throwable $previous = null): self
    {
        return new self(self::DELETE_FAILED, 500, __('The media item could not be deleted. Please try again.'), [], $previous);
    }

    public static function uploadFailed(?\Throwable $previous = null): self
    {
        return new self(self::UPLOAD_FAILED, 500, __('The file could not be stored. Please try again.'), [], $previous);
    }
}
