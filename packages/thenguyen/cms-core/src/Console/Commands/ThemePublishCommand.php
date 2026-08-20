<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\ThemeAssetPublisher;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\PublishResult;

/**
 * Publish theme source assets (themes/{slug}/assets) into the public web root
 * (public/themes/{slug}). Run this after editing theme assets or deploying a
 * theme so the served CSS/JS/images match the theme source (v1.0.0-beta.7.1.12).
 */
class ThemePublishCommand extends Command
{
    protected $signature = 'theme:publish
        {theme? : Theme slug to publish (defaults to the active theme)}
        {--all : Publish every valid discovered theme}
        {--clean : Delete public/themes/{slug} before publishing}
        {--dry-run : Show what would be copied without writing files}';

    protected $description = 'Publish theme assets into public/themes/{slug}.';

    public function handle(ThemeManager $themes, ThemeAssetPublisher $publisher): int
    {
        $options = [
            'dry_run' => (bool) $this->option('dry-run'),
            'clean' => (bool) $this->option('clean'),
        ];

        if ($this->option('all')) {
            return $this->publishAll($publisher, $options);
        }

        $slug = $this->resolveSingleTheme($themes);

        if ($slug === null) {
            return self::FAILURE;
        }

        return $this->report($publisher->publish($slug, $options));
    }

    /**
     * @param  array{dry_run: bool, clean: bool}  $options
     */
    private function publishAll(ThemeAssetPublisher $publisher, array $options): int
    {
        $results = $publisher->publishAll($options);

        if ($results === []) {
            $this->error('No valid themes discovered.');

            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($results as $result) {
            if ($this->report($result) !== self::SUCCESS) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    /**
     * Resolve the single theme slug to publish: the explicit argument (must be
     * a valid discovered theme) or, when omitted, the effective active theme
     * (which itself falls back to "default" then the first theme). Returns null
     * and prints an error when nothing valid can be resolved.
     */
    private function resolveSingleTheme(ThemeManager $themes): ?string
    {
        $argument = $this->argument('theme');

        if (is_string($argument) && $argument !== '') {
            $theme = $themes->find($argument);

            if ($theme === null) {
                $this->error("Theme [{$argument}] not found or invalid.");

                return null;
            }

            return $theme->slug;
        }

        $active = $themes->active();

        if ($active === null) {
            $this->error('No valid theme available to publish.');

            return null;
        }

        return $active->slug;
    }

    private function report(PublishResult $result): int
    {
        // Unsafe slug or another pre-flight error with no assets context.
        if (! $result->hasAssets && ! $result->ok()) {
            foreach ($result->errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // No assets directory — skipped gracefully (not an error).
        if (! $result->hasAssets) {
            $this->line("No assets directory for theme [{$result->theme}], skipped.");

            return self::SUCCESS;
        }

        $suffix = $result->dryRun ? ' (dry-run)' : '';

        $this->line("Publishing theme [{$result->theme}]{$suffix}");
        $this->line("Source: {$result->source}");
        $this->line("Destination: {$result->destination}");
        $this->line("Copied: {$result->copied}");
        $this->line("Skipped: {$result->skipped}");
        $this->line("Deleted: {$result->deleted}");

        if (! $result->ok()) {
            foreach ($result->errors as $error) {
                $this->error($error);
            }

            $this->line('Done with errors.');

            return self::FAILURE;
        }

        $this->line('Done.');

        return self::SUCCESS;
    }
}
