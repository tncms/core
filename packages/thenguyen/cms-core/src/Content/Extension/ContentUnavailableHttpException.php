<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by the content gate BEFORE any view renders. The frontend error
 * responder brands it; its headers (no-store, Retry-After) are preserved.
 */
final class ContentUnavailableHttpException extends HttpException
{
    public static function make(int $status, ?int $retryAfter = null): self
    {
        $headers = ['Cache-Control' => 'no-store, private'];

        if ($status === 503 && $retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new self($status, '', null, $headers);
    }
}
