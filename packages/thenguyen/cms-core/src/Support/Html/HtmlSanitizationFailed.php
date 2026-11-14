<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Html;

use RuntimeException;

/**
 * The sanitizer could not process the input (the parsed content root was lost), so it has no safe output to offer.
 * Thrown only by {@see \TheNguyen\CMS\Services\HtmlSanitizer::sanitizeForStorage()}; the message never contains
 * any of the input.
 */
final class HtmlSanitizationFailed extends RuntimeException {}
