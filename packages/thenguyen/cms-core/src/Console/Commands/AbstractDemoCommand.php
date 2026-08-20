<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Support\DemoPackage;
use TheNguyen\CMS\Support\ImportResult;

/**
 * Shared base for the `tncms:demo:*` commands. Holds the package resolution and
 * result rendering used by import and reset, so each concrete command stays a
 * thin entry point. `owner` is a theme slug (theme packages) or a plugin slug
 * (plugin packages); `slug` is the package slug.
 */
abstract class AbstractDemoCommand extends Command
{
    /**
     * Resolve the {owner} {slug} arguments to a discovered package, or null
     * (with a clear error printed) when the arguments are incomplete or no
     * package matches.
     */
    protected function resolvePackage(DemoImporter $importer): ?DemoPackage
    {
        $owner = (string) $this->argument('owner');
        $slug = (string) $this->argument('slug');

        if ($owner === '' || $slug === '') {
            $this->error('Both {owner} and {slug} are required for this action.');

            return null;
        }

        $package = $importer->find($owner, $slug);

        if ($package === null) {
            $this->error("Demo package '{$owner}/{$slug}' was not found.");
        }

        return $package;
    }

    /**
     * Print an {@see ImportResult} (warnings, then errors/success) and map it to
     * a process exit code.
     */
    protected function render(ImportResult $result): int
    {
        foreach ($result->warnings as $warning) {
            $this->warn('• '.$warning);
        }

        if (! $result->success) {
            foreach ($result->errors as $error) {
                $this->error('• '.$error);
            }

            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message.($result->batchId !== null && $result->batchId !== '' ? " (batch {$result->batchId})" : ''));

        return self::SUCCESS;
    }
}
