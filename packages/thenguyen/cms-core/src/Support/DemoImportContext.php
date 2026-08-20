<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * The runtime context handed to a {@see \TheNguyen\CMS\Contracts\DemoImportHandler}
 * when the {@see \TheNguyen\CMS\Services\DemoImporter} imports a plugin-specific
 * (or otherwise non-core) demo file.
 *
 * It exposes everything a handler needs without coupling the core to any plugin:
 * the package being imported, the imported-media key map (so a handler can wire
 * its records to the media rows the importer already created), the active
 * locale, and mutable collectors for the outcomes the importer rolls up into the
 * final {@see ImportResult}. Handlers report via addCreated()/addWarning()/
 * addError() rather than returning a value, so the core needs no plugin types.
 */
final class DemoImportContext
{
    /** @var array<int, string> */
    private array $created = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param  array<string, int>  $importedMedia  media import key => cms_media id
     */
    public function __construct(
        public readonly DemoPackage $package,
        public readonly array $importedMedia = [],
        public readonly ?string $locale = null,
    ) {}

    /**
     * The cms_media id an earlier media import mapped a key to, or null.
     */
    public function mediaId(string $key): ?int
    {
        return $this->importedMedia[$key] ?? null;
    }

    public function addCreated(string $step): void
    {
        $this->created[] = $step;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /** @return array<int, string> */
    public function created(): array
    {
        return $this->created;
    }

    /** @return array<int, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
