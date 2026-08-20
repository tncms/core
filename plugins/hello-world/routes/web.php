<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hello World plugin — web routes
|--------------------------------------------------------------------------
| Loaded automatically by the TN CMS ExtensionManager when the plugin is
| active, wrapped in the "web" middleware group, and registered BEFORE the
| frontend catch-all so /hello-world resolves here.
*/

Route::get('/hello-world', static function () {
    $greeting = app()->bound('hello-world.greeting')
        ? app('hello-world.greeting')
        : 'Hello World from TN CMS Plugin';

    if (view()->exists('hello-world::welcome')) {
        return view('hello-world::welcome', ['greeting' => $greeting]);
    }

    return $greeting;
})->name('plugin.hello-world.index');
