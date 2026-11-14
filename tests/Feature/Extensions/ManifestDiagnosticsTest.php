<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-05: an unreadable plugin.json / theme.json was listed only as
 * "Missing or invalid …", hiding the JSON parser error an extension author needs. The invalid-extension lists
 * (shown on the Installed Plugins and Themes admin pages) now name the cause.
 */
final class ManifestDiagnosticsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/p0-05-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/plugins');
        File::ensureDirectoryExists($this->root.'/themes');
        config(['cms.paths.plugins' => $this->root.'/plugins', 'cms.paths.themes' => $this->root.'/themes']);

        foreach (['plugins' => 'plugin.json', 'themes' => 'theme.json'] as $kind => $file) {
            File::ensureDirectoryExists("{$this->root}/{$kind}/no-manifest");
            $this->write($kind, 'broken', $file, '');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function write(string $kind, string $slug, string $file, string $body): void
    {
        File::ensureDirectoryExists("{$this->root}/{$kind}/{$slug}");
        File::put("{$this->root}/{$kind}/{$slug}/{$file}", $body);
    }

    /** @return array<string, string> slug => reason */
    private function reasons(array $rows): array
    {
        return array_column($rows, 'reason', 'slug');
    }

    public function test_plugin_manifest_reasons_name_the_cause(): void
    {
        $this->write('plugins', 'syntax', 'plugin.json', '{"name": "Broken", "slug": "syntax",}');
        $this->write('plugins', 'scalar', 'plugin.json', '"just a string"');

        $reasons = $this->reasons(app('cms.extension')->invalidPlugins());

        $this->assertSame('Missing plugin.json', $reasons['no-manifest']);
        $this->assertSame('Invalid plugin.json: Syntax error', $reasons['syntax']);
        $this->assertSame('Invalid plugin.json: the top level must be a JSON object', $reasons['scalar']);
        $this->assertSame('Invalid plugin.json: Syntax error', $reasons['broken'], 'an empty file is a syntax error');
    }

    public function test_theme_manifest_reasons_name_the_cause(): void
    {
        $this->write('themes', 'syntax', 'theme.json', "{\"name\": \"Broken\"\n\"slug\": \"x\"}");
        $this->write('themes', 'scalar', 'theme.json', '42');

        $reasons = $this->reasons(app('cms.theme')->invalidThemes());

        $this->assertSame('Missing theme.json', $reasons['no-manifest']);
        $this->assertSame('Invalid theme.json: Syntax error', $reasons['syntax']);
        $this->assertSame('Invalid theme.json: the top level must be a JSON object', $reasons['scalar']);
    }
}
