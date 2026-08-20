<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Admin\Support\MediaItems;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side image search backing the MediaLibrarySelect grid (logo/favicon
 * and featured-image pickers). Registered as a Filament authenticated route so
 * it inherits the admin panel's auth middleware — only signed-in admins reach
 * it. Returns image media matched across filename/url/path/metadata, newest
 * first, so large libraries (1000+ imported files) stay fully searchable
 * instead of being capped to the first page loaded into the grid.
 */
class MediaSearchController extends Controller
{
    /** Hard cap so a crafted ?limit= can never request an unbounded result. */
    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $search = (string) $request->query('q', '');

        $limit = (int) $request->query('limit', (string) self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return response()->json([
            'data' => MediaItems::searchImages($search, $limit),
        ]);
    }
}
