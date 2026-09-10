<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * CORE-EDITOR-1B — Classic Editor writable-fallback contract.
 *
 * When TinyMCE fails to load, the plain textarea is the live editor. It has
 * no wire:model, so before this fix nothing typed there ever reached Livewire
 * state: Save re-persisted the stale server-side value and notified success
 * (false success, reproduced on authentic public .27). The fallback must be
 * genuinely writable — textarea input synced to the field's Livewire state —
 * and visibly announced. These are structural pins on the shipped blade; the
 * behavioural matrix lives in ClassicEditorPersistenceTest and the runtime
 * browser certification.
 */
final class RichEditorFallbackContractTest extends TestCase
{
    private function editorBlade(): string
    {
        return (string) file_get_contents(
            resource_path('views/filament/admin/components/rich-editor.blade.php')
        );
    }

    public function test_fallback_textarea_syncs_input_to_livewire_state(): void
    {
        $blade = $this->editorBlade();

        $this->assertStringContainsString(
            'x-on:input="syncFallback()"',
            $blade,
            'The fallback textarea must push edits into Livewire state — without this, '
            .'saving with TinyMCE unavailable silently persists the old content (false success).'
        );

        // The sync must target the field's real state path and stay inert
        // while TinyMCE owns the field.
        $this->assertMatchesRegularExpression(
            '/syncFallback:\s*function\s*\(\)\s*\{\s*if\s*\(this\.editor\s*===\s*null\)\s*\{\s*this\.\$wire\.set\(statePath,\s*this\.\$refs\.input\.value,\s*false\)/s',
            $blade,
        );
    }

    public function test_fallback_mode_is_flagged_and_announced(): void
    {
        $blade = $this->editorBlade();

        $this->assertStringContainsString('fallbackMode: false', $blade);
        $this->assertStringContainsString('self.fallbackMode = true;', $blade);
        $this->assertStringContainsString('x-show="fallbackMode"', $blade);

        $key = 'The rich text editor failed to load. You are editing the raw HTML directly; your changes will still be saved.';
        $this->assertStringContainsString($key, $blade);

        foreach (['en', 'vi'] as $locale) {
            $lines = json_decode(
                (string) file_get_contents(base_path("lang/$locale.json")),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertArrayHasKey($key, $lines, "lang/$locale.json must translate the fallback notice");
            $this->assertNotSame('', trim((string) $lines[$key]));
        }
    }
}
