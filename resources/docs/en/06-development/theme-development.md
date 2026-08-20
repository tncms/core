---
title: Theme Development
description: Theme Development Guide for TN CMS.
order: 3
---

# Theme Development

This guide explains how to build, package, install, publish, and maintain a theme for **TN CMS**.

Themes live in `themes/{slug}/`, are discovered through `theme.json`, and render the public site through the `theme::` Blade namespace. TN CMS keeps one effective active theme whenever at least one valid theme exists.

## 1. Theme directory structure

```txt
themes/{theme-slug}/
├── theme.json                 # required manifest
├── screenshot.png             # optional admin preview, 1280×800 recommended
├── functions.php              # optional config-returning file for options, widgets, hooks
├── lang/                      # optional theme interface translations
│   ├── en.json
│   └── vi.json
├── assets/                    # source public assets, published by command
│   ├── css/
│   ├── js/
│   └── images/
└── views/                     # Blade views resolved via the theme:: namespace
    ├── layouts/
    │   └── master.blade.php
    ├── pages/
    │   ├── home.blade.php
    │   └── page.blade.php
    ├── posts/
    │   └── post.blade.php
    ├── archives/
    │   ├── index.blade.php
    │   ├── category.blade.php
    │   └── tag.blade.php
    └── partials/
        ├── header.blade.php
        ├── footer.blade.php
        └── seo.blade.php
```

The `themes/{slug}` directory is source code. It is not served directly by the web server. Public files must be copied to `public/themes/{slug}` through the asset publishing command.

## 2. `theme.json`

```json
{
  "name": "Default Theme",
  "slug": "default",
  "version": "1.0.0",
  "description": "Default starter theme for TN CMS.",
  "author": "The Nguyen Media",
  "author_uri": "https://tncms.org",
  "support_email": "support@tncms.org",
  "screenshot": "screenshot.png",
  "requires": { "cms": ">=1.0.0" },
  "supports": {
    "menus": ["header", "footer"],
    "theme_options": true,
    "widgets": true
  }
}
```

Required keys: `name`, `slug`, `version`, and `author`. A theme with an invalid or missing manifest is skipped and shown as invalid in the admin UI instead of breaking the frontend.

`requires.cms` and `supports.*` are descriptive. Feature availability is decided by actual files and schemas, especially `functions.php` for theme options, widgets, and hook registrations.

## 3. Required and optional views

Required views:

```txt
views/layouts/master.blade.php
views/pages/page.blade.php
views/posts/post.blade.php
views/archives/index.blade.php
views/partials/seo.blade.php
```

Optional views:

```txt
views/pages/home.blade.php
views/archives/category.blade.php
views/archives/tag.blade.php
views/partials/header.blade.php
views/partials/footer.blade.php
views/maintenance.blade.php
```

Reference views through the namespace:

```blade
@extends('theme::layouts.master')
@include('theme::partials.header')
@include('theme::partials.seo')
```

The active theme is resolved first, then the default theme is used as fallback. This allows a child or partial theme to override only the files it needs.

## 4. Public asset publishing

TN CMS does not serve CSS, JavaScript, images, or fonts directly from `themes/{slug}/assets`. Theme assets are published into:

```txt
public/themes/{slug}/
```

This design avoids symlink requirements, works on shared hosting, keeps source files private, and makes deployment predictable.

### Asset lifecycle

```txt
Edit source assets
        ↓
themes/{slug}/assets
        ↓
php artisan theme:publish
        ↓
public/themes/{slug}
        ↓
Browser / CDN
```

### Commands

```bash
php artisan theme:publish
php artisan theme:publish default
php artisan theme:publish --all
php artisan theme:publish default --dry-run
php artisan theme:publish default --clean
```

Command behavior:

| Command | Purpose |
| --- | --- |
| `theme:publish` | Publish the active theme. |
| `theme:publish default` | Publish one named theme. |
| `theme:publish --all` | Publish assets for every valid theme. |
| `theme:publish --dry-run` | Preview what would be copied without writing files. |
| `theme:publish --clean` | Remove `public/themes/{slug}` first, then republish. |

Theme activation also publishes assets automatically, but development and deployment workflows should still run the command explicitly after asset changes.

### Published file types

Allowed public files include:

```txt
css, js, mjs, json, png, jpg, jpeg, gif, svg, webp, avif,
ico, woff, woff2, ttf, eot, txt, xml, webmanifest
```

Skipped files include:

```txt
php, blade.php, env, sql, map, log, bak, git files, hidden sensitive files
```

Do not edit files inside `public/themes/{slug}`. Always edit the source files in `themes/{slug}/assets`, then republish.

## 5. Referencing assets in Blade

Use `theme_asset()`:

```blade
<link rel="stylesheet" href="{{ theme_asset('css/app.css') }}">
<script src="{{ theme_asset('js/app.js') }}" defer></script>
<img src="{{ theme_asset('images/logo.svg') }}" alt="{{ settings('general.site_name') }}">
```

For cache busting, prefer versioned assets or append a theme version when needed:

```blade
<link rel="stylesheet" href="{{ theme_asset('css/app.css') }}?v={{ theme()->active()?->version }}">
```

## 6. Available helpers

Use CMS helpers instead of direct database queries.

| Helper | Purpose |
| --- | --- |
| `settings('group.key', $default)` | Read CMS settings. |
| `theme()` / `theme('slug')` | Access the Theme Manager or a theme object. |
| `theme_asset('css/app.css')` | Generate a public URL for active theme assets. |
| `theme_view('pages.page')` | Generate a `theme::` view name. |
| `frontend_menu('header')` | Render-ready menu tree. |
| `language()` / `current_locale()` | Language manager and current locale. |
| `language_switcher()` | Data for a frontend language switcher. |
| `content_url($content)` | Locale-aware content URL. |
| `term_url($term)` | Locale-aware taxonomy URL. |
| `localized_url('en', '/path')` | Locale-aware path. |
| `seo()` | Current SEO context. |
| `cms_html($body)` | Sanitized HTML output. |
| `theme_option('key', $default)` | Active theme option value. |
| `theme_trans('Read more')` | Theme interface translation. |
| `widget_area('footer-1')` | Render a widget area. |
| `render_hook('cms.theme.header')` | Render an action hook region. |

## 7. SEO partial

Include the SEO partial once inside the layout `<head>`:

```blade
@include('theme::partials.seo')
```

The layout should not emit another `<title>`. The SEO partial owns title, description, keywords, robots, canonical, Open Graph, Twitter card, and hreflang tags.

## 8. Content and featured images

`pages/page.blade.php` and `posts/post.blade.php` receive content data such as `$content`, `$title`, `$body`, `$excerpt`, `$featuredImage`, and `$publishedAt`.

```blade
@if ($featuredImage)
    <img src="{{ $featuredImage }}" alt="{{ $title }}" loading="lazy">
@endif

<div class="entry-content">
    {!! cms_html($body) !!}
</div>
```

Featured images are currently stored as URL strings. Always escape attributes and render rich text through `cms_html()`.

## 9. Menus

Declare menu locations in `theme.json`:

```json
"supports": {
  "menus": ["header", "footer"]
}
```

Render menus with `frontend_menu()`:

```blade
@foreach (frontend_menu('header') as $node)
    <a href="{{ $node['url'] }}">{{ $node['title'] }}</a>
@endforeach
```

Menu URLs follow the CMS slug, permalink, and locale systems automatically.

## 10. Language support

Build URLs with `content_url()`, `term_url()`, `localized_url()`, and `language_switcher()`. Do not hardcode `/en/...` or `/vi/...`.

The default locale may render without a prefix, while other active locales may use `/{locale}/...` depending on CMS settings.

## 11. Theme options

A theme can declare option sections in `functions.php`. TN CMS renders these options under **Appearance → Theme Options** and stores values in `cms_settings`.

`functions.php` must return an array and should not perform side effects.

```php
<?php

return [
    'options' => [
        'sections' => [
            [
                'key' => 'identity',
                'label' => 'Site Identity',
                'description' => 'Logo and brand display.',
                'fields' => [
                    [
                        'key' => 'logo',
                        'label' => 'Logo',
                        'type' => 'image',
                        'default' => null,
                    ],
                    [
                        'key' => 'show_tagline',
                        'label' => 'Show tagline',
                        'type' => 'boolean',
                        'default' => true,
                    ],
                ],
            ],
        ],
    ],
];
```

Supported field types:

```txt
text, textarea, boolean, number, select, image, color
```

Read values in views:

```blade
@php($logo = theme_option('logo'))
@if (is_string($logo) && $logo !== '')
    <img src="{{ $logo }}" alt="{{ settings('general.site_name') }}">
@else
    {{ settings('general.site_name') }}
@endif
```

Validate sensitive values before output, especially colors used in CSS.

## 12. Theme translations

Theme interface strings live in:

```txt
themes/{slug}/lang/en.json
themes/{slug}/lang/vi.json
```

Example:

```json
{
  "Read more": "Đọc thêm",
  "No posts yet.": "Chưa có bài viết nào."
}
```

Use `theme_trans()` in Blade:

```blade
<a href="{{ content_url($post) }}">{{ theme_trans('Read more') }}</a>
```

Theme translations are for frontend theme strings only. Admin UI strings belong to the CMS core translation files.

## 13. Widgets

Render widget areas with:

```blade
{!! widget_area('footer-1') !!}
{!! widget_area('sidebar-blog', current_locale()) !!}
```

Register custom widget areas and widgets from `functions.php`:

```php
<?php

return [
    'widget_areas' => [
        ['slug' => 'homepage-after-hero', 'name' => 'Homepage After Hero'],
    ],
    'widgets' => [
        \MyTheme\Widgets\PromoWidget::class,
    ],
];
```

Widget output should be safe. Escape text and sanitize rich HTML before returning it.

## 14. Hooks and shortcodes

Expose hook regions in your layout:

```blade
{!! render_hook('cms.theme.header') !!}
@include('theme::partials.header')

<main class="site-main">
    {!! render_hook('cms.theme.before_content') !!}
    @yield('content')
    {!! render_hook('cms.theme.after_content') !!}
</main>

@include('theme::partials.footer')
{!! render_hook('cms.theme.footer') !!}
```

Plugins or themes can inject markup through actions:

```php
add_action('cms.theme.header', function (): void {
    echo '<link rel="stylesheet" href="/plugins/announce/bar.css">';
});
```

Shortcodes may also be registered from PHP. TN CMS does not execute PHP stored in content.

## 15. Installing themes from ZIP

A theme ZIP may contain a wrapping folder or files at the root:

```txt
my-theme.zip
└── my-theme/
    ├── theme.json
    ├── screenshot.png
    ├── functions.php
    ├── lang/
    ├── assets/
    └── views/
        └── layouts/master.blade.php
```

Install from **Appearance → Install Theme**. The installer validates the ZIP, rejects unsafe paths and symlinks, finds a single `theme.json`, validates the manifest, and copies files into `themes/{slug}`. The installed theme remains inactive until activated.

The active theme cannot be overwritten. Activate another theme first if you need to replace it.

## 16. Deployment workflow

Recommended production flow:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan theme:publish --all
php artisan optimize
```

For local development:

```bash
# edit themes/default/assets/css/app.css
php artisan theme:publish default
php artisan optimize:clear
```

Use `--clean` when deleted files in `assets/` must also disappear from `public/themes/{slug}`.

## 17. Theme package checklist

Before shipping a theme ZIP, include:

```txt
✓ theme.json
✓ screenshot.png
✓ views/
✓ assets/
✓ functions.php, if the theme declares options/widgets/hooks
✓ lang/, if the theme has interface translations
```

Exclude:

```txt
✗ node_modules/
✗ vendor/
✗ .git/
✗ .env
✗ storage/
✗ public/themes/{slug}/
✗ build caches and temporary files
```

## 18. What not to do

- Do not edit `vendor/`.
- Do not serve files directly from `themes/{slug}`.
- Do not edit generated files in `public/themes/{slug}`.
- Do not hardcode admin paths.
- Do not emit a second `<title>` outside the SEO partial.
- Do not output unsanitized HTML.
- Do not query the database directly when a CMS helper or manager exists.
- Do not reference another theme's views directly; use `theme::` and fallback behavior.

## 19. FAQ

### Why are my CSS changes not visible?

You edited source assets but did not publish them yet:

```bash
php artisan theme:publish default
```

### Can a theme use symlinks?

No. TN CMS intentionally copies assets instead of using symlinks for shared-hosting compatibility.

### Should I commit `public/themes/{slug}`?

Usually no. Commit source files under `themes/{slug}` and publish assets during deployment.

### Can I use Vite, Tailwind, or another build tool?

Yes. Build into `themes/{slug}/assets`, then run `php artisan theme:publish`.

### Can I put PHP files in `assets/`?

No. Public asset publishing skips PHP and Blade files. Keep executable code in the theme source, not in public assets.

---

TN CMS · https://tncms.org · support@tncms.org · The Nguyen Media
