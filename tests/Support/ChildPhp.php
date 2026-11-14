<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The command prefix for a PHP child process spawned by a test.
 *
 * A child started as a bare `PHP_BINARY` reads only the php.ini that PHP finds on its own. When the suite itself
 * runs with an explicit `php -c <ini>` (a PHP install without its own php.ini, such as an unpacked official
 * Windows build), the child would start without the extensions the test process has (zip, pdo_mysql, mbstring)
 * and fail for reasons unrelated to the contract under test. Forwarding the loaded php.ini keeps the child on the
 * same configuration as the test process; with a default php.ini it is the same file PHP would load anyway.
 */
final class ChildPhp
{
    /** @return list<string> */
    public static function command(): array
    {
        $ini = php_ini_loaded_file();

        return $ini === false ? [PHP_BINARY] : [PHP_BINARY, '-c', $ini];
    }
}
