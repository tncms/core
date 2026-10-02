<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\PublicAuthorViewModel;

/**
 * Projects a content author into a privacy-safe PublicAuthorViewModel, or null
 * when there is no safely presentable author (guest/missing/deleted/unnamed)
 * (CORE-BLOG-1). Implementations must never return a raw user model or expose
 * private account fields.
 */
interface PublicAuthorResolver
{
    public function forContent(Content $content, string $locale): ?PublicAuthorViewModel;
}
