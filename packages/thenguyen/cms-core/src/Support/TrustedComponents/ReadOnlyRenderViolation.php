<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use RuntimeException;

/** A read-only (preview/editor) component render attempted a side effect. */
final class ReadOnlyRenderViolation extends RuntimeException {}
