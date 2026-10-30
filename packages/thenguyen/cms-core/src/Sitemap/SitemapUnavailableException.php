<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use RuntimeException;

/** A provider failed or a manifest could not be built in time; the response is a 503. */
final class SitemapUnavailableException extends RuntimeException {}
