<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;

/**
 * Reads preset blueprints from the active theme's `presets/` directory
 * (theme-architecture `18`). A preset is a JSON file `presets/{id}.json`
 * declaring a homepage `layout` (a pagebuilder/06 document) plus default
 * settings/section expectations. Presets are blueprints, never content
 * (theme-architecture `20` Rule 7).
 *
 * Path access is contained to the theme's `presets/` directory (realpath
 * check, slug-like id) so a crafted id can never read outside it.
 */
class PresetRepository
{
    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * The full preset blueprint, or null when missing/invalid.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, ?string $theme = null): ?array
    {
        if (! $this->isValidId($id)) {
            return null;
        }

        $file = $this->presetFile($id, $theme);

        if ($file === null || ! File::exists($file)) {
            return null;
        }

        $data = json_decode((string) File::get($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * A preset's homepage layout document (`layout`), or null.
     *
     * @return array<string, mixed>|null
     */
    public function layout(string $id, ?string $theme = null): ?array
    {
        $preset = $this->find($id, $theme);

        $layout = $preset['layout'] ?? null;

        return is_array($layout) ? $layout : null;
    }

    /**
     * All preset ids available in the active (or given) theme.
     *
     * @return array<int, string>
     */
    public function all(?string $theme = null): array
    {
        $dir = $this->presetsDir($theme);

        if ($dir === null || ! File::isDirectory($dir)) {
            return [];
        }

        $ids = [];

        foreach (File::files($dir) as $file) {
            if (strtolower($file->getExtension()) === 'json') {
                $ids[] = $file->getFilenameWithoutExtension();
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Absolute path to a preset file, with a realpath containment guard. Returns
     * null when the theme/dir cannot be resolved or the path escapes the
     * presets directory.
     */
    private function presetFile(string $id, ?string $theme): ?string
    {
        $dir = $this->presetsDir($theme);

        if ($dir === null) {
            return null;
        }

        $candidate = $dir.DIRECTORY_SEPARATOR.$id.'.json';

        // Containment: the resolved parent must be the presets directory.
        $realDir = realpath($dir);
        $realParent = realpath(dirname($candidate));

        if ($realDir !== false && $realParent !== false && $realParent !== $realDir) {
            return null;
        }

        return $candidate;
    }

    private function presetsDir(?string $theme): ?string
    {
        $slug = $theme;

        if (! is_string($slug) || $slug === '') {
            $slug = $this->themes->active()?->slug;
        }

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return $this->themes->themePath($slug).DIRECTORY_SEPARATOR.'presets';
    }

    private function isValidId(string $id): bool
    {
        return $id !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id) === 1;
    }
}
