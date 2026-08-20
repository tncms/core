<?php

declare(strict_types=1);

namespace App\Filament\Admin\Components;

use App\Filament\Admin\Support\MediaItems;
use Filament\Forms\Components\Field;

/**
 * Thumbnail-grid image picker, used as the "Media Library" tab inside the
 * featured-image modal (see MediaPicker). It renders an Alpine-driven grid of
 * existing images with search and a details panel; selecting a thumbnail writes
 * its URL into this field's state (a plain URL string).
 *
 * The grid mounts with a server-rendered snapshot of the newest images (max
 * 50); typing in the search box queries the server (the authenticated
 * `filament.admin.cms-media-search` endpoint) so the FULL media table is
 * searchable, not just the first page. This matters for large libraries — e.g.
 * a WordPress import of hundreds of files, where the wanted logo/favicon would
 * otherwise never load into the grid. If the search route can't be resolved
 * (e.g. outside the admin panel) the grid degrades to client-side filtering of
 * the loaded snapshot. Nothing is uploaded or persisted here — selection only
 * sets a URL string.
 *
 * The Alpine component is defined inline in the view's `x-data` (not via a
 * global `@assets` script) because this field renders inside a lazily-mounted
 * action modal, where `@assets`/`@filamentScripts` no longer inject new scripts.
 */
class MediaLibrarySelect extends Field
{
    protected string $view = 'filament.admin.components.media-library-select';

    /**
     * Initial image snapshot for the library grid (newest first, max 50). Search
     * goes server-side from there (see getSearchUrl), so this is just the first
     * page shown before the user types.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMediaItems(): array
    {
        return MediaItems::imageItems(50);
    }

    /**
     * The currently-selected media item (resolved from this field's stored URL),
     * or null when nothing is selected or the URL has no cms_media row. Provided
     * separately from the snapshot so the details panel renders the selection
     * even when it falls outside the newest-50 grid or the current search.
     *
     * @return array<string, mixed>|null
     */
    public function getSelectedItem(): ?array
    {
        $url = $this->getState();

        return is_string($url) && $url !== '' ? MediaItems::findByUrl($url) : null;
    }

    /**
     * URL of the authenticated server-side image search endpoint, or null when
     * it can't be resolved (so the grid falls back to client-side filtering).
     */
    public function getSearchUrl(): ?string
    {
        try {
            return route('filament.admin.cms-media-search');
        } catch (\Throwable) {
            return null;
        }
    }
}
