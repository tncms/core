<?php

declare(strict_types=1);

namespace App\Search;

/**
 * A contained provider failure (Phase 3.1.6N-C). Records WHICH provider failed
 * and a SAFE, generic message — never the underlying exception text — so a
 * broken provider degrades global search into a warning instead of an error, and
 * internal details never reach the response.
 */
final class SearchFailure
{
    public function __construct(
        public readonly string $provider,
        public readonly string $message,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'message' => $this->message,
        ];
    }
}
