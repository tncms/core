<?php

declare(strict_types=1);

namespace App\Filament\Admin\Components;

use App\Filament\Admin\Support\MediaItems;
use Filament\Forms\Components\Field;

/**
 * Reusable TinyMCE rich-text editor field.
 *
 * Drop-in replacement for a content Textarea: `RichEditor::make('content')`.
 * The editor stores HTML; that HTML is sanitized server-side by
 * TheNguyen\CMS\Services\HtmlSanitizer when ContentManager persists it.
 *
 * TinyMCE (v8, community/GPL) is self-hosted from /vendor/tinymce — no Tiny
 * Cloud, no API key. The field is Livewire-safe (wire:ignore + $wire.get/set)
 * and provides a built-in image **Media Modal** ("Insert Media"), an
 * "Insert media by URL" button, and a "Media" library link.
 */
class RichEditor extends Field
{
    protected string $view = 'filament.admin.components.rich-editor';

    /** Editor height in pixels. */
    protected int $editorHeight = 500;

    public function height(int $pixels): static
    {
        $this->editorHeight = $pixels;

        return $this;
    }

    public function getEditorHeight(): int
    {
        return $this->editorHeight;
    }

    /**
     * Image media for the Insert Media modal (newest first, max 50).
     * Snapshot taken at form render; client-side search filters it.
     *
     * Delegates to the shared MediaItems helper so this list is identical to
     * the Featured Image modal's list. `seo_alt`/`seo_title` carry the resolved
     * defaults (alt/title falling back to the humanized filename) for seeding.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMediaItems(): array
    {
        return MediaItems::imageItems(50);
    }
}
