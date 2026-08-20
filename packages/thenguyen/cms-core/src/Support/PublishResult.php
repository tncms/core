<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Immutable outcome of publishing one theme's assets via
 * {@see \TheNguyen\CMS\Services\ThemeAssetPublisher} (v1.0.0-beta.7.1.12).
 */
final class PublishResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly string $theme,
        public readonly string $source,
        public readonly string $destination,
        public readonly int $copied = 0,
        public readonly int $skipped = 0,
        public readonly int $deleted = 0,
        public readonly array $errors = [],
        public readonly bool $dryRun = false,
        public readonly bool $hasAssets = true,
    ) {}

    /**
     * True when the publish completed without any errors.
     */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'theme' => $this->theme,
            'source' => $this->source,
            'destination' => $this->destination,
            'copied' => $this->copied,
            'skipped' => $this->skipped,
            'deleted' => $this->deleted,
            'errors' => $this->errors,
            'dry_run' => $this->dryRun,
        ];
    }
}
