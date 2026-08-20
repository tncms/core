<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Immutable result of a demo import (or reset). Mirrors {@see InstallResult}:
 * the importer never throws to a caller — every outcome is one of these.
 *
 * `created`/`updated`/`skipped` hold step identifiers (e.g. "homepage").
 * On a first import steps land in `created`; on a re-import they land in
 * `updated`. `skipped` covers absent-optional / not-yet-implemented steps.
 *
 * `theme`/`demo` are kept as the owner/slug of the imported package (an owner
 * is a theme slug for theme packages, a plugin slug for plugin packages), so
 * the shape stays stable across the generalized theme/plugin demo system.
 */
final class ImportResult
{
    /**
     * @param  array<int, string>  $created
     * @param  array<int, string>  $updated
     * @param  array<int, string>  $skipped
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $theme = null,
        public readonly ?string $demo = null,
        public readonly ?string $batchId = null,
        public readonly array $created = [],
        public readonly array $updated = [],
        public readonly array $skipped = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    /**
     * @param  array<int, string>  $errors
     */
    public static function failure(string $theme, string $demo, string $message, array $errors = []): self
    {
        return new self(
            success: false,
            message: $message,
            theme: $theme,
            demo: $demo,
            batchId: null,
            errors: $errors === [] ? [$message] : $errors,
        );
    }

    /**
     * @param  array<int, string>  $created
     * @param  array<int, string>  $updated
     * @param  array<int, string>  $skipped
     * @param  array<int, string>  $warnings
     */
    public static function ok(
        string $theme,
        string $demo,
        string $batchId,
        string $message,
        array $created = [],
        array $updated = [],
        array $skipped = [],
        array $warnings = [],
    ): self {
        return new self(
            success: true,
            message: $message,
            theme: $theme,
            demo: $demo,
            batchId: $batchId,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            warnings: $warnings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'theme' => $this->theme,
            'demo' => $this->demo,
            'batch_id' => $this->batchId,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
