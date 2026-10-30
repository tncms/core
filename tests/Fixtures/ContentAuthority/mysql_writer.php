<?php

declare(strict_types=1);

/**
 * Child process for ContentRevisionMySqlConcurrencyTest
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1). Boots the real application against
 * the DISPOSABLE MySQL database named in DB_DATABASE and performs one content
 * operation, printing one JSON line per event. It refuses to run against any
 * database whose name does not start with "cdq_scr1_".
 *
 * Usage: php mysql_writer.php setup
 *        php mysql_writer.php hold  <post> <expectedRevision> <title> <holdMs>
 *        php mysql_writer.php write <post> <expectedRevision> <title>
 *        php mysql_writer.php state <post>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\ContentAuthority\ContentRevisionConflict;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Models\Content;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$emit = static function (array $event): void {
    fwrite(STDOUT, json_encode($event)."\n");
    fflush(STDOUT);
};

$database = (string) DB::connection()->getDatabaseName();

if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with($database, 'cdq_scr1_')) {
    $emit(['error' => 'refusing to run outside a disposable cdq_scr1_* MySQL database', 'database' => $database]);
    exit(2);
}

$mode = $argv[1] ?? '';
$userId = (int) (getenv('CA_USER_ID') ?: 0);

if ($userId > 0) {
    auth()->setUser(App\Models\User::query()->findOrFail($userId));
}

switch ($mode) {
    case 'setup':
        Artisan::call('migrate', ['--force' => true]);
        (new TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder)->run();
        $user = App\Models\User::query()->create(['name' => 'SCR1', 'email' => 'scr1@example.invalid', 'password' => bcrypt('x')]);
        (new TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder)->run(); // first user becomes super admin
        $post = app('cms.content')->create(
            ['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Original', 'author_id' => $user->id],
            ContentWriteContext::system('install_seed'),
        );
        $emit(['post' => $post->id, 'revision' => (int) $post->revision, 'user' => $user->id, 'database' => $database]);
        break;

    case 'hold':
        [$post, $revision, $title, $holdMs] = [(int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5]];
        $started = microtime(true);
        app('cms.content')->transaction($post, $revision, ContentWriteContext::interactive(), function (Content $locked) use ($title, $holdMs, $emit): void {
            app('cms.content')->update($locked, ['locale' => 'en', 'title' => $title], null, ContentWriteContext::interactive());
            $emit(['event' => 'locked']);
            usleep($holdMs * 1000);
        });
        $emit(['event' => 'committed', 'ms' => (int) ((microtime(true) - $started) * 1000)]);
        break;

    case 'write':
        [$post, $revision, $title] = [(int) $argv[2], (int) $argv[3], $argv[4]];
        $started = microtime(true);

        try {
            $fresh = app('cms.content')->update(Content::query()->findOrFail($post), ['locale' => 'en', 'title' => $title], $revision, ContentWriteContext::interactive());
            $emit(['result' => 'ok', 'revision' => (int) $fresh->revision, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
        } catch (ContentRevisionConflict $e) {
            $emit(['result' => 'conflict', 'expected' => $e->expected(), 'actual' => $e->actual(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);
        }
        break;

    case 'state':
        $content = Content::query()->findOrFail((int) $argv[2]);
        $emit(['title' => $content->translations()->where('locale', 'en')->value('title'), 'revision' => (int) $content->revision]);
        break;

    default:
        $emit(['error' => 'unknown mode']);
        exit(2);
}
