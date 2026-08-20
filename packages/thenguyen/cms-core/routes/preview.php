<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\PreviewController;

/*
|--------------------------------------------------------------------------
| CMS Preview Route (v1.0.0-beta.7.1.16)
|--------------------------------------------------------------------------
| A single secure, temporary, SIGNED preview endpoint for unpublished content
| of ANY registered previewable type (core or plugin). Loaded BEFORE the
| frontend catch-all so /cms/preview/... is never swallowed by the generic
| /{slug} resolver.
|
| The 'signed' middleware rejects unsigned/tampered/expired URLs with a 403
| before the controller runs — there is no predictable, unsigned way in. The
| prefix is configurable but defaults to the reserved "cms/preview".
*/

$prefix = trim((string) config('cms.preview.route_prefix', 'cms/preview'), '/');

Route::middleware(['web', 'signed'])
    ->get($prefix.'/{type}/{key}', PreviewController::class)
    ->where('type', '[A-Za-z0-9._-]+')
    ->where('key', '[A-Za-z0-9._-]+')
    ->name('cms.preview.show');
