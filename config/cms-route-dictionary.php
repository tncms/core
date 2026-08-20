<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform Route Dictionary (persisted) — P6.1
|--------------------------------------------------------------------------
|
| The persistent source of localized static route segments: key => [locale => segment].
| Read ONCE at boot by RouteDictionaryLoader and turned into the immutable Runtime
| RouteSegmentDictionary — never read during a request. Only NON-DEFAULT locales are listed;
| the default locale always projects to the canonical Route Key, so default-locale URLs stay
| byte-identical. Populated from the frozen P5H.1B mappings.
|
| Editing this file changes localized URLs; run `php artisan config:clear` if config is cached.
| Adding a locale or key is pure data — no Runtime change (INV-DICTIONARY-RUNTIME-06).
|
*/

return [
    // Ecommerce.
    'products' => ['vi' => 'san-pham', 'de' => 'produkte', 'fr' => 'produits', 'ja' => '商品'],
    'product-category' => ['vi' => 'danh-muc-san-pham', 'de' => 'produkt-kategorien'],
    'brand' => ['vi' => 'thuong-hieu', 'de' => 'marken'],

    // Core CMS.
    'category' => ['vi' => 'danh-muc', 'de' => 'kategorien'],
    'tag' => ['vi' => 'the'],
    'page' => ['vi' => 'trang'],
    'post' => ['vi' => 'bai-viet'],
    'author' => ['vi' => 'tac-gia'],
    'search' => ['vi' => 'tim-kiem'],

    // Documentation.
    'docs' => ['vi' => 'tai-lieu'],
    'doc-category' => ['vi' => 'chu-de'],
];
