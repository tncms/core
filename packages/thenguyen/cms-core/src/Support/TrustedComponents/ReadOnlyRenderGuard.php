<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Throwable;
use WeakMap;

/**
 * Side-effect guard for read-only component renders (CORE-EXTENSION-TRUST S2,
 * brief §3.4): signed preview and editor contexts.
 *
 * While {@see run()} executes a render:
 *   - every database statement on every connection (including one first
 *     opened during the render) must pass {@see isRead()}, otherwise it throws
 *     {@see ReadOnlyRenderViolation} BEFORE it executes;
 *   - SQLite connections additionally run with `PRAGMA query_only = 1`, so the
 *     engine itself refuses writes (defense in depth for the classifier);
 *   - every Laravel HTTP-client request throws before it is sent.
 * Outside a guarded render all hooks are inert.
 *
 * Scope (documented residual): raw sockets, curl_*, filesystem, mail
 * transports and non-database queues are not intercepted; providers must
 * honour {@see TrustedRenderContext::isReadOnly()}. The flag is a per-worker
 * boolean set/cleared in try/finally around ONE render: correct for PHP-FPM
 * and sequential persistent workers, not designed for concurrent fibers.
 */
final class ReadOnlyRenderGuard
{
    private const READ_START = '/^(select|show|describe|desc|explain|with|pragma\s+(table_info|table_xinfo|index_list|index_info|foreign_key_list))\b/i';

    /** Statements or clauses that write, lock, execute or have side effects. */
    private const DENY = '/\b(insert|update|delete|replace|merge|upsert|truncate|drop|alter|create|grant|revoke|rename|attach|detach|vacuum|reindex|call|exec|execute|copy|load|lock|unlock|handler|set|into|analy[sz]e|setval|nextval|lo_import|lo_export|lo_unlink|pg_advisory\w*|pg_sleep|load_file|get_lock|release_lock|sleep|benchmark|dblink\w*)\b/i';

    private bool $active = false;

    private bool $listening = false;

    /** @var WeakMap<Connection, true> */
    private WeakMap $hooked;

    public function __construct()
    {
        $this->hooked = new WeakMap;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    public function run(Closure $render): mixed
    {
        $this->listen();

        $restore = [];

        foreach (DB::getConnections() as $connection) {
            $this->hookConnection($connection);
            $restore[] = $this->sqliteQueryOnly($connection);
        }

        $this->active = true;

        try {
            return $render();
        } finally {
            $this->active = false;

            foreach (array_filter($restore) as $reset) {
                $reset();
            }
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether one SQL statement only reads. Fails closed: comments, stacked
     * statements, write/lock/side-effect keywords anywhere → not a read.
     */
    public static function isRead(string $sql): bool
    {
        // String literals and quoted identifiers cannot carry keywords that matter.
        $stripped = preg_replace(['/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s', '/"(?:[^"\\\\]|\\\\.|"")*"/s', '/`(?:[^`]|``)*`/s'], "''", $sql);

        if (! is_string($stripped)) {
            return false;
        }

        $stripped = trim(ltrim($stripped, " \t\n\r("));

        if (preg_match('#/\*|--|\##', $stripped)) {
            return false;
        }

        if (str_contains(rtrim($stripped, "; \t\n\r"), ';')) {
            return false;
        }

        if (! preg_match(self::READ_START, $stripped) || str_contains($stripped, '=') && preg_match('/^pragma/i', $stripped)) {
            return false;
        }

        return ! preg_match(self::DENY, $stripped);
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        // A connection first opened DURING a guarded render is hooked too.
        app('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->hookConnection($event->connection);
        });

        app(HttpFactory::class)->globalRequestMiddleware(function ($request) {
            if ($this->active) {
                throw new ReadOnlyRenderViolation('http request');
            }

            return $request;
        });
    }

    private function hookConnection(mixed $connection): void
    {
        if (! $connection instanceof Connection || isset($this->hooked[$connection])) {
            return;
        }

        $this->hooked[$connection] = true;
        $connection->beforeExecuting(function (string $query): void {
            if ($this->active && ! self::isRead($query)) {
                throw new ReadOnlyRenderViolation('database write');
            }
        });
    }

    /** Switch an open SQLite connection to query_only; returns the reset, or null. */
    private function sqliteQueryOnly(mixed $connection): ?Closure
    {
        try {
            if (! $connection instanceof SQLiteConnection) {
                return null;
            }

            $pdo = $connection->getPdo();
            $previous = (int) $pdo->query('PRAGMA query_only')->fetchColumn();
            $pdo->exec('PRAGMA query_only = 1');

            return static fn () => $pdo->exec('PRAGMA query_only = '.$previous);
        } catch (Throwable) {
            return null;
        }
    }
}
