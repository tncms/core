<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use PDO;
use Tests\TestCase;
use Throwable;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — real cross-connection contention on
 * MySQL (T-C1, T-X7, G-P08-MYSQL). Two separate PHP processes, each with its own
 * MySQL connection, write the same content against a DISPOSABLE database
 * (`cdq_scr1_*`, created and dropped here). SQLite cannot prove row locking.
 *
 * Skipped (with the reason) when no local MySQL server is reachable as root.
 */
final class ContentRevisionMySqlConcurrencyTest extends TestCase
{
    private ?PDO $server = null;

    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->server = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        } catch (Throwable $e) {
            $this->markTestSkipped('No local MySQL at 127.0.0.1:3306 (root): '.$e->getMessage());
        }

        $this->database = 'cdq_scr1_'.getmypid().'_'.bin2hex(random_bytes(3));
        $this->assertMatchesRegularExpression('/^cdq_scr1_[0-9]+_[0-9a-f]{6}$/', $this->database);
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && str_starts_with($this->database, 'cdq_scr1_')) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }

        parent::tearDown();
    }

    /** @return resource */
    private function spawn(array $args, ?int $userId = null, &$pipes = null)
    {
        $env = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => $this->database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'TN_CMS_INSTALLED' => 'false',
            'CA_USER_ID' => (string) ($userId ?? 0),
        ];

        foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'PATH', 'Path', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            if (getenv($key) !== false) {
                $env[$key] = getenv($key);
            }
        }

        $command = array_merge([PHP_BINARY, base_path('tests/Fixtures/ContentAuthority/mysql_writer.php')], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);

        return $process;
    }

    /** Read one JSON line from a child (blocking, with a deadline). */
    private function line($pipe, float $timeout = 60.0): array
    {
        $deadline = microtime(true) + $timeout;
        stream_set_blocking($pipe, false);
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($pipe);

            if ($chunk !== false) {
                $buffer .= $chunk;

                if (str_ends_with($buffer, "\n")) {
                    $decoded = json_decode(trim($buffer), true);
                    $this->assertIsArray($decoded, 'child output: '.$buffer);

                    return $decoded;
                }
            } elseif (feof($pipe)) {
                break;
            } else {
                usleep(20_000);
            }
        }

        $this->fail('No output from the child process: '.$buffer);
    }

    private function runChild(array $args, ?int $userId = null): array
    {
        $process = $this->spawn($args, $userId, $pipes);
        $out = $this->line($pipes[1], 300);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        $this->assertArrayNotHasKey('error', $out, $err);

        return $out;
    }

    public function test_a_stale_writer_on_a_second_connection_loses_cleanly_while_the_first_holds_the_lock(): void
    {
        $setup = $this->runChild(['setup']);
        $this->assertSame($this->database, $setup['database']);
        [$post, $revision, $user] = [$setup['post'], $setup['revision'], $setup['user']];

        // Process A locks the row, writes, and holds the transaction open.
        $holder = $this->spawn(['hold', (string) $post, (string) $revision, 'Written by A', '5000'], $user, $holdPipes);
        $this->assertSame(['event' => 'locked'], $this->line($holdPipes[1]));

        // Process B read the same revision and saves now: it must block on A's
        // row lock, then see A's committed revision and lose — no overwrite.
        $writer = $this->spawn(['write', (string) $post, (string) $revision, 'Written by B'], $user, $writePipes);
        $result = $this->line($writePipes[1]);
        $committed = $this->line($holdPipes[1]);
        proc_close($writer);
        proc_close($holder);

        $this->assertSame('committed', $committed['event']);
        $this->assertSame('conflict', $result['result']);
        $this->assertSame($revision, $result['expected']);
        $this->assertSame($revision + 1, $result['actual']);
        // The conflict itself proves B read A's COMMITTED revision, i.e. it blocked
        // on A's row lock (an unblocked B would have seen the old revision and
        // overwritten). The timing is a secondary signal: B's process start-up
        // eats into A's 5 s hold, so only a conservative lower bound is asserted.
        $this->assertGreaterThanOrEqual(1000, $result['ms'], 'B must have waited on A\'s row lock');

        $this->assertSame(['title' => 'Written by A', 'revision' => $revision + 1], $this->runChild(['state', (string) $post]));

        // With the fresh revision B succeeds.
        $this->assertSame('ok', $this->runChild(['write', (string) $post, (string) ($revision + 1), 'Written by B'], $user)['result']);
        $this->assertSame(['title' => 'Written by B', 'revision' => $revision + 2], $this->runChild(['state', (string) $post]));
    }
}
