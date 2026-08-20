<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\Support\DemoImportContext;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * A custom importer for a demo file the core does not import natively.
 *
 * The core {@see \TheNguyen\CMS\Services\DemoImporter} natively imports the
 * known files `media`, `theme_options`, and `homepage`. Any other logical file
 * a package declares (e.g. a plugin's `products` or `categories`) is dispatched
 * to the handler class named in the manifest's `handlers` map.
 *
 * A handler is resolved through the application container, so a plugin's handler
 * is available once the plugin's PSR-4 autoload is registered (the importer only
 * discovers plugin packages for active plugins). When a declared handler class
 * does not exist, the importer skips that file with a clear warning rather than
 * failing the whole import.
 *
 * Handlers report outcomes through the {@see DemoImportContext} collectors
 * (addCreated / addWarning / addError); they must not throw — the importer wraps
 * a thrown handler in a warning, but a well-behaved handler degrades gracefully.
 */
interface DemoImportHandler
{
    /**
     * Import one decoded demo file.
     *
     * @param  array<string, mixed>  $data  the decoded JSON of the package file
     */
    public function import(DemoPackage $package, array $data, DemoImportContext $context): void;
}
