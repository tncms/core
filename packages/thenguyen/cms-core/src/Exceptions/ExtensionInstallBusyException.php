<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use RuntimeException;

/**
 * Another install/overwrite of the same extension slug currently holds the per-slug install lock
 * (CORE-PLUGIN-UPGRADE-ATOMIC-ROLLBACK-R1). The message is user-safe.
 */
final class ExtensionInstallBusyException extends RuntimeException {}
