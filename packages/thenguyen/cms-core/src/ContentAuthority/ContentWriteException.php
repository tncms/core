<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use RuntimeException;

/**
 * Base class of every typed content-write refusal. It always propagates out of
 * the write transaction, so a refused write leaves no rows behind.
 *
 * `errorCode()` is the stable, documented machine code (see
 * docs/content-authority/02-PUBLIC-CONTRACT.md); `getMessage()` is a safe,
 * user-facing sentence.
 */
abstract class ContentWriteException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly ?int $contentId = null,
        private readonly ?string $owner = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function contentId(): ?int
    {
        return $this->contentId;
    }

    /** The extension that owns (or claimed) the content, when relevant. */
    public function owner(): ?string
    {
        return $this->owner;
    }
}
