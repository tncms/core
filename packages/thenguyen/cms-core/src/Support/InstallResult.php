<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Immutable result of an extension (theme/plugin) ZIP installation.
 *
 * The installer never throws to the UI — every outcome (success or controlled
 * failure) is expressed as one of these. `errors` holds machine-ish failure
 * reasons; `warnings` holds non-fatal notes (e.g. a declared provider class that
 * could not be found on disk — install still succeeds, the plugin is inactive).
 */
final class InstallResult
{
    /**
     * @param  'plugin'|'theme'        $type
     * @param  array<int, string>      $errors
     * @param  array<int, string>      $warnings
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $type,
        public readonly ?string $slug = null,
        public readonly ?string $installedPath = null,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @param  'plugin'|'theme'    $type
     * @param  array<int, string>  $errors
     */
    public static function failure(string $type, string $message, array $errors = [], ?string $slug = null): self
    {
        return new self(
            success: false,
            message: $message,
            type: $type,
            slug: $slug,
            installedPath: null,
            errors: $errors === [] ? [$message] : $errors,
        );
    }

    /**
     * @param  'plugin'|'theme'    $type
     * @param  array<int, string>  $warnings
     */
    public static function ok(string $type, string $slug, string $installedPath, string $message, array $warnings = []): self
    {
        return new self(
            success: true,
            message: $message,
            type: $type,
            slug: $slug,
            installedPath: $installedPath,
            errors: [],
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
            'type' => $this->type,
            'slug' => $this->slug,
            'installed_path' => $this->installedPath,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
