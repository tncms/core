<?php

declare(strict_types=1);

namespace App\Search\Exceptions;

use RuntimeException;

/**
 * Base for every failure raised by the shared search registry (Phase 3.1.6N-B).
 */
class SearchRegistryException extends RuntimeException
{
}
