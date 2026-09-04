# TN CMS â€” Theme Development Guide

> How to build a theme for **TN CMS**. This guide is code-verified against the
> theme system as of **v0.9.9**. For the authoritative architecture, see
> [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md) Â§10â€“Â§11.

- **CMS:** TN CMS Â· <https://tncms.org> Â· support@tncms.org
- **Author:** The Nguyen Media

Themes live in `themes/{slug}/`, are discovered from their `theme.json`, and
render the public site through the `theme::` Blade namespace. There is exactly
**one active theme** at a time; TN CMS always keeps one active theme for the
frontend (see [Active theme rules](#active-theme-rules)).

---

## 1. Directory structure

```
themes/{theme-slug}/
â”œâ”€â”€ theme.json                 # required manifest (see Â§2)
â”œâ”€â”€ screenshot.png             # optional admin preview (1280Ã—800 recommended)
â”œâ”€â”€ functions.php              # config-returning file; declares the Theme Options schema (loaded safely)
â”œâ”€â”€ assets/                    # published (copied) to public/themes/{slug}/
â”‚   â”œâ”€â”€ css/
â”‚   â”œâ”€â”€ js/
â”‚   â””â”€â”€ images/
â””â”€â”€ views/                     # Blade views resolved via the theme:: namespace
    â”œâ”€â”€ layouts/
    â”‚   â””â”€â”€ master.blade.php    # required â€” page shell
    â”œâ”€â”€ pages/
    â”‚   â””â”€â”€ page.blade.php       # required â€” a single page
    â”œâ”€â”€ posts/
    â”‚   â””â”€â”€ post.blade.php       # required â€” a single post
    â”œâ”€â”€ archives/
    â”‚   â”œâ”€â”€ index.blade.php      # required â€” category/tag/latest listing
    â”‚   â”œâ”€â”€ category.blade.php   # optional â€” dedicated category template
    â”‚   â””â”€â”€ tag.blade.php        # optional â€” dedicated tag template
    â””â”€â”€ partials/
        â”œâ”€â”€ header.blade.php
        â”œâ”€â”€ footer.blade.php
        â””â”€â”€ seo.blade.php        # emits <title>, meta, OG/Twitter, canonical, hreflang
```

> Asset publishing **copies** `themes/{slug}/assets` into `public/themes/{slug}`
> (no symlinks â€” shared-hosting friendly). It runs automatically on
> `ThemeManager::activate()`; there is no build step.
>
> After you **edit** a theme's assets (or deploy a theme without re-activating
> it), publish them explicitly so the served files match the source â€” otherwise
> `public/themes/{slug}` keeps the old CSS/JS and the frontend looks stale:
>
> ```bash
> php artisan theme:publish            # active theme
> php artisan theme:publish default    # one named theme
> php artisan theme:publish --all      # every valid theme
> php artisan theme:publish default --dry-run   # preview, writes nothing
> php artisan theme:publish default --clean     # wipe public/themes/default first
> ```
>
> Only web-servable file types are published (`css`, `js`, `mjs`, `json`,
> images, fonts, `txt`, `xml`, `webmanifest`). PHP/Blade/`.env`/`.sql`/`.map`
> and any other file type are always skipped, so it is safe to keep source-only
> files under `assets/`. `--clean` only ever removes `public/themes/{slug}`.

---

## 2. `theme.json` schema

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
  "requires": { "cms": ">=0.6.0" },
  "supports": {
    "menus": ["header", "footer"],
    "theme_options": false,
    "widgets": false
  }
}
```

| Key | Required | Notes |
| --- | --- | --- |
| `name` | âœ… | Human-readable theme name. |
| `slug` | âœ… | Folder-safe identifier; should match the directory name. |
| `version` | âœ… | SemVer string. |
| `author` | âœ… | Author / vendor name. |
| `description` | recommended | Shown on the Themes admin card. |
| `screenshot` | recommended | Filename relative to the theme root (admin preview). |
| `author_uri` | recommended | Author website. |
| `support_email` | recommended | Support contact. |
| `supports.menus` | recommended | Menu locations the theme renders (descriptive). |
| `supports.theme_options` | recommended | Descriptive. The actual option **schema** in `functions.php` decides availability (see Â§10) â€” set `true` when your theme declares options. |
| `requires.cms` | optional | Descriptive only â€” **not enforced**. |

**Validation (v0.9.7):** a theme is only discovered when `theme.json` is valid
JSON **and** contains non-empty `name`, `slug`, `version`, and `author`. A theme
with a missing/invalid manifest is **skipped** (not rendered) and listed under
*Invalid themes* on the admin Themes page â€” it never breaks the admin.

---

## 3. Required vs optional views

**Required** (the frontend / `/cms-health frontend_ready` expects these):

- `layouts/master.blade.php`
- `pages/page.blade.php`
- `posts/post.blade.php`
- `archives/index.blade.php`
- `partials/seo.blade.php` (for SEO tags)

**Optional:**

- `pages/home.blade.php` â€” used by the homepage when `reading.homepage_display`
  is `latest_posts` (receives `$posts`); otherwise the archive index is used.
- `archives/category.blade.php`, `archives/tag.blade.php` â€” dedicated templates
  (the controller currently renders `archives/index` for both; you may still
  include these and `@include`/`@extends` them yourself).
- `partials/header.blade.php`, `partials/footer.blade.php`.
- `maintenance.blade.php` â€” shown when **Maintenance Mode** (v1.0.0-beta.4) is on
  and the display mode is *theme* (`theme::maintenance`). A theme may provide it;
  if it does not, the core view `cms.maintenance` is used. Keep it standalone and
  minimal (no admin links). It receives `$title`, `$message`, `$statusCode`,
  `$retryAfterMinutes`, `$siteName`, and `$homeUrl`. The HTTP status, `Retry-After`
  and `noindex` headers are set by the CMS, but the shipped views also include a
  `<meta name="robots" content="noindex,nofollow">` â€” keep that in your own.

- `search/index.blade.php` - overrides the platform search page
  (`theme::search.index`). Omit it and the Core-owned search page renders inside
  your `layouts/master`. See section 14 (Frontend Search) below.
- `errors/404.blade.php`, `errors/error.blade.php` - themed error pages
  (specialized then generic). Optional: the Core ships a safe fallback that keeps
  the site functional even if a theme provides neither. See section 15 (Error
  pages) below.

> Missing-view safety: the `theme::` namespace resolves the active theme first,
> then falls back to the `default` theme, so a partially-overridden theme
> inherits any view it does not define. If a required view is missing from both,
> the controller degrades to a minimal HTML fallback instead of a 500.

Reference siblings through the namespace:

```blade
@extends('theme::layouts.master')
@include('theme::partials.header')
@include('theme::partials.seo')
```

---

## 4. Available helpers

Use these global helpers in theme views â€” **do not query the database directly**
when a helper/service exists.

| Helper | Returns |
| --- | --- |
| `settings()` / `settings('group.key', $default)` | the SettingsManager, or a resolved setting value |
| `theme()` / `theme('slug')` | the ThemeManager, or a `Theme` value object |
| `theme_asset('css/app.css')` | `/themes/{active}/css/app.css` |
| `theme_view('pages.page')` | `theme::pages.page` |
| `menu('header')` | a `Menu` model by location then slug |
| `frontend_menu('header')` | a render-ready nested menu tree (`item`,`title`,`url`,`children`) |
| `language()` / `language('en')` | the LanguageManager, or a `Language` model |
| `current_locale()` | the current request locale code |
| `language_switcher()` | per-language `code`/`label`/`url`/`active`/`direction`/`flag` for the current page |
| `content_url($content)` | locale-aware public URL for a page/post (honours permalink bases) |
| `term_url($term)` | locale-aware public URL for a category/tag |
| `localized_url('en', '/path')` | a locale-prefixed path (honours `prefix_default`) |
| `seo()` | the SeoManager for the current page context |
| `cms_html($body)` | sanitized HTML for safe `{!! !!}` output (legacy plain text is escaped + line-broken) |
| `theme_option('key', $default)` | a Theme Option value for the active theme (stored value â†’ schema default â†’ `$default`); see Â§10 |
| `theme_trans('Read more')` | a translated interface string for the active theme (theme â†’ core â†’ key); see Â§13 |

> Note: there is no standalone `setting()` (singular) helper â€” use `settings('group.key')`.

---

## 5. SEO partial usage

`layouts/master.blade.php` should `@include('theme::partials.seo')` inside
`<head>`. The partial reads `seo()` and emits `<title>`, `<meta>` description /
keywords / robots, `<link rel="canonical">`, Open Graph, Twitter cards, and
`hreflang` alternates. The layout itself should **not** emit its own `<title>` â€”
the SEO partial owns it. `seo()->titleSeparator()` is available if you build a
`{title} {sep} {site}` document title.

---

## 6. Media / featured image usage

Featured images are stored as a URL string on the content record. In views:

```blade
@if ($featuredImage)
    <img src="{{ $featuredImage }}" alt="{{ $title }}" loading="lazy">
@endif

<div class="entry-content">{!! cms_html($body) !!}</div>
```

`$content`, `$title`, `$body`, `$excerpt`, `$featuredImage`, and `$publishedAt`
are passed to `pages/page` and `posts/post`. Archive views receive `$term`,
`$title`, and `$posts`.

---

## 7. Menu locations

Declare the locations your theme renders in `theme.json` (`supports.menus`,
descriptive) and render them with `frontend_menu('header')` /
`frontend_menu('footer')`. Menu items resolve their URL from `cms_slugs`
(falling back to a stored URL or `#`), so they automatically follow permalink
base and locale settings.

---

## 8. Language support expectations

- The default language renders at unprefixed URLs; other active languages render
  under `/{locale}/...`. Build links with `content_url()` / `term_url()` /
  `localized_url()` â€” never hardcode `/en/...`.
- Render a language switcher from `language_switcher()` (see the default theme's
  `partials/header.blade.php`).
- Respect `direction` (`ltr`/`rtl`) from the language data where relevant.

---

## 9. Active theme rules

- TN CMS keeps **exactly one effective active theme** whenever a valid theme
  folder exists. If `theme.active` is unset, it falls back to `default`, then to
  the first discovered theme.
- There is **no Deactivate action**. The active theme shows an *Active* badge
  only; you change themes by clicking **Activate** on another theme, which
  *switches* the active theme.
- **Activation is transactional.** Before switching, TN CMS validates the target
  manifest, verifies the required views resolve (`layouts/master`, `pages/page`,
  `posts/post`, `archives/index` â€” in your theme or via the `default` fallback),
  and publishes assets. If any step fails, the **previously active theme stays
  active** and an error is shown. So a half-built or broken theme cannot take the
  frontend down.
- If **no** valid theme exists at all, the frontend returns a friendly **503**
  ("No active theme found"), never a raw exception.

---

## 10. Theme Options (v0.9.9)

A theme can declare **options** that the CMS renders as an admin form
(Appearance â†’ Theme Options) and stores in `cms_settings`. Your views read the
saved values with `theme_option()`. This is a framework, **not** a live
customizer.

### Declaring options in `functions.php`

`functions.php` is loaded **safely** as a *config-returning* file: it must
`return` an array and must have **no side effects**. A missing file, a non-array
return, or a thrown error is caught and ignored (your theme still works; the
options page shows an empty state). Declare options under `options.sections`:

```php
<?php

return [
    'options' => [
        'sections' => [
            [
                'key' => 'identity',          // required, slug-like
                'label' => 'Site Identity',   // required
                'description' => 'Logo and brand display.', // optional
                'fields' => [
                    [
                        'key' => 'logo',       // required, slug-like (used as the option key)
                        'label' => 'Logo',     // required
                        'type' => 'image',     // required
                        'default' => null,     // optional
                        'helper' => 'Recommended transparent PNG or SVG.', // optional
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

### Supported field types

`text`, `textarea`, `boolean`, `number`, `select`, `image`, `color`.

| Type | Optional keys | Admin component |
| --- | --- | --- |
| `text` | `placeholder` | text input |
| `textarea` | `placeholder` | textarea |
| `boolean` | â€” | toggle |
| `number` | `min`, `max`, `placeholder` | numeric input |
| `select` | `options` (`value => Label` map) | select |
| `image` | â€” | media picker (stores a URL string) |
| `color` | â€” | color picker (stores a hex string) |

All fields also accept `default` and `helper`. **Validation:** section/field
`key`s must be slug-like; invalid sections/fields are skipped; unsupported field
types are skipped (logged); **duplicate field keys keep the first definition**.

### Reading option values in views

```blade
@php($logo = theme_option('logo'))
@if (is_string($logo) && $logo !== '')
    <img src="{{ $logo }}" alt="{{ settings('general.site_name') }}">
@else
    {{ settings('general.site_name') }}
@endif

@if (theme_option('show_tagline', true))
    <p class="tagline">{{ settings('general.site_tagline') }}</p>
@endif
```

`theme_option('key', $default)` returns the saved value, falling back to the
field's schema `default`, then to `$default`. Always **escape/validate** before
using a value in a sensitive sink â€” e.g. validate a color before emitting it
into CSS:

```blade
@php($c = theme_option('primary_color', '#2563eb'))
@php($c = is_string($c) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : '#2563eb')
<style>:root { --tncms-primary: {{ $c }}; }</style>
```

Set `supports.theme_options: true` in `theme.json` when your theme declares
options.

### Limitations (v0.9.9)

- No live customizer / preview â€” values apply on save + reload.
- No repeater or nested option fields.
- Field types are limited to the seven above.

---

## 11. What NOT to do

- **Do not modify `vendor/`** â€” it is Composer-managed.
- **Do not hardcode admin paths** (`/admin`, Filament/Livewire URLs) in a theme.
- **Do not query the database directly** when a helper/service exists â€” go
  through `settings()`, `frontend_menu()`, `content_url()`, `seo()`, etc.
- **Do not emit `<title>` in the layout** â€” let `partials/seo.blade.php` own it.
- **Do not echo unsanitized HTML** â€” render bodies with `{!! cms_html($body) !!}`.
- **Do not reference another theme's views directly** â€” use the `theme::`
  namespace and rely on the default fallback.

---

## 12. Installing a theme from a ZIP (v1.0.0-beta.2)

**Appearance â†’ Install Theme** (`/admin/themes/install`) installs a theme from a
local `.zip` upload. Package the archive with either a single wrapping folder
**or** the files at the root:

```
my-theme.zip
â””â”€â”€ my-theme/                         # (or no wrapping folder â€” files at root)
    â”œâ”€â”€ theme.json                    # REQUIRED â€” name, slug, version, author
    â”œâ”€â”€ screenshot.png                # optional preview
    â”œâ”€â”€ functions.php                 # optional (theme options)
    â””â”€â”€ views/
        â””â”€â”€ layouts/master.blade.php  # required, unless the default theme exists
```

The destination folder is taken from the **manifest `slug`**. The installer (core
`ExtensionInstaller`, helper `extension_installer()`):

1. accepts only readable `.zip` files;
2. validates **every entry before extracting** â€” rejects path traversal,
   absolute/drive-letter paths, empty names, symlinks, and too-many/too-large
   archives;
3. extracts to a throwaway `storage/app/tncms-installer/{random}` (never directly
   into `themes/`), then locates a **single** `theme.json` (multiple â†’ ambiguous â†’
   rejected);
4. validates the manifest (`name`, `slug`, `version`, `author`; slug lowercase
   slug-like and not reserved) and requires `views/layouts/master.blade.php`
   unless a valid `default` theme exists to inherit from;
5. moves the validated files to `themes/{slug}` and **always** cleans up temp.

**Overwrite is off by default** (duplicate slug fails); the **active** theme can
never be overwritten (activate another theme first). The theme is installed
**inactive** â€” activate it from **Appearance â†’ Themes** when ready.

Programmatic equivalent:

```php
$result = extension_installer()->installThemeFromZip($absoluteZipPath, overwrite: false);
// $result->success, $result->message, $result->slug, $result->errors, $result->warnings
```

**Deleting a theme.** A **non-active** theme can be removed from **Appearance â†’
Themes** via the **Delete** button on its card (confirmation required; shown only
when more than one valid theme exists). The active theme has no Delete button â€”
activate another theme first, and the **last remaining theme can never be
deleted**. Programmatic equivalent:

```php
$result = extension_installer()->deleteTheme($slug); // InstallResult
```

The slug is validated and the path is resolved internally with a `realpath`
containment check, so only `themes/{slug}` can ever be removed.

ðŸ”µ **Not implemented:** remote-URL install, CLI install, marketplace, theme editor.

---

## 13. Theme translations (v1.0.0-beta.5)

Translate your theme's **interface** strings (button labels, empty states, etc.) with
JSON files â€” separate from page/post content. Add one file per locale:

```
themes/{your-theme}/lang/en.json
themes/{your-theme}/lang/vi.json
```

Each is a flat map of source string â†’ translation:

```json
{ "Read more": "Äá»c thÃªm", "No posts yet.": "ChÆ°a cÃ³ bÃ i viáº¿t nÃ o." }
```

In your Blade views, wrap static labels with `theme_trans()`:

```blade
<a href="{{ content_url($post) }}">{{ theme_trans('Read more') }}</a>
```

`theme_trans($key, $replace = [], $locale = null)` resolves: your theme's `{locale}`
file â†’ your theme's default-locale file â†’ the CMS core file â†’ the key itself. The
locale defaults to `current_locale()`. Replacements use Laravel-style `:name` /
`:Name` / `:NAME`. Missing files or invalid JSON never error â€” you get the fallback.

You don't have to pre-create every locale: an admin can run **Languages â†’ Sync
Translation Files** to generate empty `{}` files for your theme across all active
languages (existing files are never overwritten). The default theme ships
`lang/en.json` + `lang/vi.json` as a working example.

> **Admin vs theme strings (v1.0.0-beta.5.1).** The TN CMS **admin UI** is now fully
> translated through the **core** `lang/{locale}.json` files (and Filament's own
> built-ins follow the CMS locale automatically). Your **theme** only owns its own
> front-end interface strings via `theme_trans()` â€” you never need to translate admin
> chrome. Theme strings resolve in the locale returned by `current_locale()` (the
> front-end request locale).

---

## Widgets (1.0.0-beta.7)

Widget *areas* (sidebars/slots) are named regions a theme exposes; the admin
assigns widget *instances* to them under **Appearance â†’ Widgets**. Core already
registers `sidebar-blog`, `sidebar-page`, `footer-1`, `footer-2`, `footer-3`,
`before-footer`, and `after-post`.

### Rendering an area

Render an area anywhere in a theme view. Output is pre-sanitized; echo with raw
tags. An empty/inactive area renders nothing, so the markup stays intact:

```blade
{!! widget_area('footer-1') !!}
{!! widget_area('sidebar-blog', current_locale()) !!}
```

The default theme renders `footer-1/2/3` (falling back to the menu-driven footer
when all three are empty) and `sidebar-blog` (in the content sidebar partial).

### Registering theme widgets + areas

A theme's `functions.php` may return `widgets` (widget class names) and
`widget_areas` (`slug` + `name`, optional `description`):

```php
return [
    'widgets' => [
        \MyTheme\Widgets\PromoWidget::class,
    ],
    'widget_areas' => [
        ['slug' => 'homepage-after-hero', 'name' => 'Homepage After Hero'],
    ],
];
```

A widget class extends `TheNguyen\CMS\Widgets\Widget` and implements `type()`,
`name()`, `schema()`, and `render(array $settings, ?string $locale): string|View`
(plus optional `description()`, `icon()`, `group()`). `render()` must return safe
output (escape text, or run HTML through `app('cms.html')->sanitize()`); a thrown
exception is caught and reported â€” it never 500s the page.

Declare fields with the fluent **Field API** (1.0.0-beta.7.1) in
`TheNguyen\CMS\Widgets\Fields` â€” `TextField`, `TextareaField`, `ToggleField`,
`SelectField`, `NumberField`, `MediaField`, `RichEditorField`, `RepeaterField`:

```php
use TheNguyen\CMS\Widgets\Fields\{TextField, ToggleField};

public static function schema(): array
{
    return [
        TextField::make('heading')->localized(),
        ToggleField::make('boxed')->default(false),
    ];
}
```

`->localized()` fields are stored per-locale; the rest are global. Plain beta.7
arrays still work. See `PLUGIN_DEVELOPMENT.md` for the full field list, presets,
and JSON export/import.

## 13. Theme hook points & shortcodes (v1.0.0-beta.7.1.11)

The default theme exposes four **action hook points** so plugins (or a theme's
`functions.php`) can inject markup without editing templates. Echo them in your
own templates with `render_hook`, which buffers everything the registered
callbacks print:

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

A plugin then writes into a region:

```php
add_action('cms.theme.header', function (): void {
    echo '<link rel="stylesheet" href="/plugins/announce/bar.css">';
});
```

`render_hook` always returns a string (empty when nothing is registered) and is
exception-safe. Themes may also register **shortcodes** in `functions.php`
(`add_shortcode(...)`) â€” see the Hooks & Shortcodes section of
`PLUGIN_DEVELOPMENT.md` for the full API, attribute parsing, and the built-in
`[button]` / `[year]` / `[site_name]` shortcodes.

**Hook context (v1.0.0-beta.7.1.11.1).** The render-time content filters
(`cms.content.title|excerpt|body`, `cms.shortcode.output`, â€¦) pass a
`HookContext` as their final argument; a theme callback can request it by
raising `acceptedArgs`, and can identify itself with registration metadata:

```php
use TheNguyen\CMS\Support\Hooks\HookContext;

add_filter('cms.content.body', function (?string $body, $content, HookContext $context) {
    return $context->frontendLocale() === 'ja' ? my_ruby_annotate($body) : $body;
}, 10, 3, ['source' => 'theme', 'source_slug' => 'aurora']);
```

The visual `cms.theme.*` placeholders take no context. Existing callbacks that
request fewer arguments keep working unchanged.


## Global Script Manager (v1.0.0-beta.7.1.13)

Themes must **not** hand-manage custom analytics, verification, or embed markup.
The Core Global Script Manager owns that. A theme layout only needs two calls:

Inside `<head>`, after your core meta/title and before `</head>`:

```blade
{!! render_head_assets() !!}
```

Before `</body>` (place it after your own theme JS so registered tag/analytics
scripts load last):

```blade
{!! render_footer_assets() !!}
```

Both helpers return a string, are safe to echo with `{!! !!}`, and **never throw**
— they render nothing when no assets are registered. `tn_render_head_assets()` /
`tn_render_footer_assets()` are aliases.

Head assets render in this order: meta tags → verification meta → external head
scripts → inline head scripts → JSON-LD → head embeds. Footer assets render:
external footer scripts → inline footer scripts → footer embeds. Order within a
group follows `priority` (default `10`, lower first), then registration order.

The default theme already wires both helpers in
`themes/default/views/layouts/master.blade.php` — use it as the reference.

## Asset Registry (v1.0.0-beta.7.1.13.1)

For **stylesheets and script files** (as opposed to meta/JSON-LD/embeds, which
belong to the Script Manager above), a theme should let the Core **Asset
Registry** load them so plugins can add or depend on assets without editing your
layout. A theme layout adds two calls:

Inside `<head>`, **after** your core theme stylesheets:

```blade
{!! render_frontend_styles() !!}
```

Before `</body>`, **after** your core theme JS:

```blade
{!! render_frontend_scripts() !!}
```

Chosen order in the default layout:

- `<head>`: core/theme `<link>` styles → `render_frontend_styles()` →
  `render_head_assets()` (Script Manager).
- before `</body>`: theme `<script>` → `render_frontend_scripts()` →
  `render_footer_assets()` (Script Manager).

`render_frontend_styles()` renders the **head bucket** (enqueued styles plus any
head-positioned scripts and head inline assets), in dependency order.
`render_frontend_scripts()` renders the **footer bucket** (footer scripts/modules
plus footer inline assets). Both are safe to echo with `{!! !!}` and **never
throw** — empty when nothing is enqueued.

A theme can register its own assets too (e.g. in the theme's service provider):

```php
use TheNguyen\CMS\Facades\Asset;

Asset::style('theme.app', theme_asset('css/app.css'), version: '1.0.0');
Asset::script('theme.app', theme_asset('js/app.js'), attributes: ['defer' => true]);
```

`Asset::style()` / `Asset::script()` register **and** enqueue in one call. See
`PLUGIN_DEVELOPMENT.md` for the full register/enqueue/dependency API and the
security rules on sources, attributes, and inline content.

## Theme Custom CSS (v1.0.0-beta.7.1.13.4)

Site owners can inject Custom CSS from **Appearance → Theme Options → Custom
CSS** without touching theme files. Two multiline editors are provided:

- **Frontend CSS** — rendered only on the public site.
- **Admin CSS** — rendered only inside the Filament admin panel.

There is **nothing to wire in a theme**: Custom CSS renders through the same
Asset Registry your layout already calls. `render_frontend_styles()` in your
`<head>` emits the Frontend CSS (as a scoped inline `<style>` in the head
bucket); the admin panel emits the Admin CSS. Frontend and admin CSS never leak
into each other because the Asset Registry filters by scope.

Storage travels with Theme Options under the canonical namespace, keyed by the
active theme slug: `theme_options.{slug}.custom_css_frontend` and
`theme_options.{slug}.custom_css_admin`. Because it lives in the `theme_options`
namespace, Custom CSS is carried by future Theme Export/Import, child themes, and
preset sync.

Values are **CSS only** and validated before they are stored or rendered:
`</style`, `<script`, `javascript:`, `vbscript:`, `expression(`, `behavior:`,
dangerous `@import` (remote/protocol-relative or `javascript:`/`vbscript:`/`data:`),
NUL/control characters, and payloads over **256 KB** per field are rejected, with
the reason shown in the Theme Options UI. Invalid CSS never renders.

Every generated inline style is tagged `source_type=settings` /
`source_name=theme-options`, so it appears automatically in **Asset
Diagnostics**. Plugins may hook the optional `cms.theme.custom_css.loading`
action (passed the `AssetRegistry`) to register assets just before Custom CSS.

### Tagging theme assets for diagnostics (v1.0.0-beta.7.1.13.3)

Pass an optional trailing `source` array so the admin **Settings → Scripts →
Diagnostics** panel and `/cms-health` attribute your assets to the theme. It is
optional and purely observational — omit it and the source defaults to `custom`:

```php
Asset::style('theme.app', theme_asset('css/app.css'), version: '1.0.0',
    source: ['source_type' => 'theme', 'source_name' => 'theme:default']);
```

The same optional `source` argument is available on `Script::head()`,
`Script::footer()`, and every other registration method. Diagnostics are passive:
they never change what renders.

---

_TN CMS Â· <https://tncms.org> Â· support@tncms.org Â· The Nguyen Media._

---

## 14. Frontend Search (CORE-FRONTEND-1)

TN CMS Core owns the frontend **search contract** — the route, query parsing,
content scope, publication/locale filtering, ordering, pagination, and SEO
policy. A theme only styles the result page; it never queries the database or
reimplements routing.

### Route and query

- Canonical route: `cms.search` at `/search`, query parameter **`q`**
  (`/search?q=tncms`). Always use `route('cms.search')` — never hard-code `/search`.
- Localized route: generated from the Route Segment Dictionary, so the non-default
  locale URL is `/{locale}/{segment}` (e.g. `/vi/tim-kiem`), named
  `cms.search.localized.{locale}`. `route('cms.search')` resolves to the correct
  localized URL for the active locale.
- Optional scope selector: `?scope={type}` (defaults to all registered types);
  pagination: `?page=N`. The current `q`/`scope` persist across pages.
- The empty query (`/search` or `/search?q=`) renders a valid HTTP **200** search
  page with the form and prompt — never a 404, never a full-corpus scan.
- No results is still HTTP **200** — never a 404.

### SEO

The search page is a utility page: Core sets `robots=noindex,follow` (the
site-wide "discourage search engines" toggle still wins). Do **not** override the
robots meta in your search view; keep `@include('theme::partials.seo')` in your
`layouts/master`.

### Overriding the search page

Ship `search/index.blade.php` to override `theme::search.index`; otherwise the
Core-owned page renders inside your `layouts/master`. The view receives a single
`$page` view model — expose only presentation data from it, never new queries:

| `$page` property | Meaning |
| --- | --- |
| `keyword` | the sanitized, escaped query string |
| `scopes` | selectable scopes (`key`, `label`) from the registry |
| `selectedScope` | the active scope key |
| `results` | result rows (`title`, `url`, `excerpt`, `typeLabel`) |
| `total`, `page`, `totalPages` | pagination state |
| `searched`, `tooShort`, `warnings` | render-state flags |

Use semantic HTML5 and escape everything: a `<form role="search">` with a named
input, a results `<section>`/`<ul>`, and a `<nav aria-label="...">` for
pagination. Do not add a second `<main>` — `layouts/master` owns it.

---

## 15. Error pages (CORE-FRONTEND-1)

Core brands public frontend HTML error responses (404 / 403 / 419 / 429 / 500 /
503) through the active theme, with a recursion-safe Core fallback. A missing
content URL stays a real HTTP **404** (never "404 content with status 200", never
a homepage redirect); the correct status is always preserved.

### Template hierarchy

For each branded status the responder resolves, in order:

```
theme::errors.{status}   (theme specialized, e.g. errors/404.blade.php)
        ↓
theme::errors.error      (theme generic)
        ↓
Core safe fallback       (self-contained, no theme dependency)
```

A theme 404/error template is **RECOMMENDED, not required** — the Core fallback
keeps the site functional if a theme ships neither, so existing themes remain
valid. If a theme error view throws while rendering (e.g. a broken layout), the
responder falls through to the Core fallback rather than looping.

### Error view data

Error views receive only sanitized, localized fields — never the exception,
stack trace, paths, SQL, or env detail:

| Variable | Meaning |
| --- | --- |
| `$status` | HTTP status code (int) |
| `$title` | localized status title |
| `$message` | localized, safe message |
| `$homeUrl`, `$homeLabel` | canonical home link |
| `$searchUrl`, `$searchLabel` | canonical search action (null if unavailable) |

A theme error view typically `@extends('theme::layouts.master')` and sets a
`@section('content')` with an `<h1>`, the message, an optional search form, and a
home link. Core sets `robots=noindex,follow` on themed error pages.

### Boundaries (never themed by a theme)

The responder deliberately does **not** theme — and a theme must never try to
hijack — these responses, so their contracts stay intact:

- `/admin/**` (Filament admin) — admin owns its own errors.
- `/install/**`, `/upgrade/**` — installer/upgrade own their flow.
- API / JSON / AJAX (`expectsJson`) — the framework returns a JSON error body.
- Livewire and other protocol responses.
- Authentication (login redirect / 401) and validation (422) flows.
- In `APP_DEBUG`, server errors (>= 500) show the framework's diagnostics.

### Maintenance mode

Frontend **Maintenance Mode** (a separate CMS feature) still uses
`theme::maintenance` — see section 3. It is unrelated to this error hierarchy.

## 16. Declarative asset manifest, parent/child themes & activation (EG-6, v1.0.0-beta.7.1.24)

The active theme is the single presentation authority. A frontend response never
mixes views or assets from unrelated themes: the resolved Blade hierarchy, the
resolved asset hierarchy, and the committed active-theme pointer are always the
same theme (standalone) or the same child→parent chain.

### Declarative asset manifest

Declare a theme's CSS/JS in `theme.json` under `assets`. Core resolves the
manifest into an owner-aware, dependency-ordered plan, registers it in the Asset
Registry at boot, and your layout renders it with `render_frontend_styles()` /
`render_frontend_scripts()` — no hard-coded `<link>`/`<script>` tags needed.

```json
{
  "assets": [
    { "handle": "tokens",  "src": "css/tokens.css" },
    { "handle": "app",     "src": "css/app.css", "deps": ["tokens"], "primary": true },
    { "handle": "app-js",  "src": "js/app.js",  "defer": true }
  ]
}
```

Per entry: `handle` (unique, required), `src` (required, theme-relative under
`assets/`), optional `type` (`style`/`script`/`module`; inferred from extension),
`position` (`head`/`footer`; styles default head, scripts default footer),
`deps` (handles), `primary` (at most one stylesheet), `defer`/`async` (scripts),
`media` (styles), `version` (cache-bust), and `replaces` (child themes only).

Validation is strict — activation **fails closed** (previous theme preserved) on:
a missing/duplicate handle, an unsafe `src` (path traversal, absolute, Windows
drive, UNC, URL scheme, protocol-relative, null byte), a missing source file, a
missing dependency, a dependency cycle, more than one primary, or an impossible
ordering (a `head` asset depending on a `footer` asset). The imperative
`theme_asset()` path remains fully supported for themes that do not adopt the
manifest.

### Owner-aware URLs

Each resolved asset carries its **owner** theme, and its public URL is built from
the owner's slug (`/themes/{owner}/{src}`) — never blindly from the active child
slug. A child inheriting `parent.css` links to `/themes/{parent}/...`; its own
`child.css` links to `/themes/{child}/...`.

### Parent / child themes

A child declares its parent **explicitly** in `theme.json` (never inferred from
the directory name):

```json
{ "slug": "acme-child", "parent": "acme" }
```

Resolution is deterministic and bounded: `child → declared parent → … → Core
fallback where allowed`; there is **no implicit Default-theme parent**. Views
resolve child-first then up the parent chain (`theme::` hints = `[child, parent,
…]`); a required view may live in the child or any ancestor. Assets are inherited
(a child may `deps` on a parent handle) and a child may **replace** a parent asset
by declaring `"replaces": "parent-handle"` — the parent asset is dropped and
dependents are rewired to the replacement. Self-parent, cycles, a missing parent,
and over-deep chains are rejected. A parent that the active child depends on
cannot be deleted while the child is active.

### Activation & rollback

Activation is atomic and commits the active-theme pointer as late as safely
possible:

```
validate manifest exists → required views resolvable across the chain (no Default
fallback) → asset manifest resolves valid → atomically publish the whole chain
(validate→stage→verify→snapshot→promote→verify) → commit pointer → invalidate
scoped caches + re-register views
```

Any failure leaves the previous theme fully in authority — views, assets and the
pointer unchanged. Asset publication is staged into a temporary directory and
promoted with a Windows/shared-hosting-safe swap; the previous live assets are
restored on any promotion failure (never a partial or mixed asset authority).

### HTML-template conversion contract

The active theme is the presentation authority. Converting a static HTML template
to a theme preserves its source layout and identity — Semantic HTML5/accessibility
is a technical conversion, not a redesign mandate. Core owns content, routing,
SEO, sections, menus, localization and the asset lifecycle; it never substitutes
the Default layout for an incomplete standalone theme (activation is rejected
instead). A child inherits only its explicitly declared parent.

## 17. Active-theme page templates (CORE-THEME-2, v1.0.0-beta.7.1.25)

A theme may declare a finite set of **page templates** — alternative page
compositions (CV, Contact, Portfolio, Landing, …) an editor can pick per Page.
This is how a converted HTML template preserves its real page layouts.

### Declaration (`theme.json`)

```json
"page_templates": [
    {
        "id": "landing",
        "label": "Landing",
        "view": "pages/templates/landing",
        "description": "Full-width landing layout."
    }
]
```

- `id` — stable machine identifier (`^[a-z0-9][a-z0-9_-]{0,63}$`), unique per
  theme. This is what a Page stores (`cms_contents.template`); it survives
  theme switches.
- `label` — human label; run through `theme_trans()` in the admin, so add it to
  the theme's `lang/{locale}.json` for every supported admin locale.
- `view` — theme-relative view identifier (`/` or `.` separated) that must
  exist inside the theme's own `views/` (or a declared parent's). Absolute
  paths, traversal (`..`), backslashes, namespaces (`::`) and undeclared views
  are rejected — **activation fails closed** on any invalid declaration.
- `description` — optional, shown as help text.

Parent/child: a child inherits the parent's declarations and deterministically
overrides an `id` it re-declares (same rule as views and assets).

### Behaviour

- The Page editor shows a validated **Template** select: the Default choice
  (the theme's canonical `pages/page`) plus the active theme's declarations —
  never filesystem paths, never other themes' templates. With no declarations
  the selector is hidden.
- At render time Core resolves the stored id through the active theme's
  validated allowlist to the declared view; the template renders inside the
  normal shell (master layout, ViewModel data, SEO, hooks, assets).
- An empty, undeclared or no-longer-available id renders the canonical
  `pages/page` view (safe policy) and logs a `cms.page_template.unavailable`
  diagnostic. Switching themes never destroys the stored id — the editor shows
  it as *unavailable* so a valid replacement can be chosen.
- Templates are presentation only: no database queries, no routes, no
  persistence. Escape everything (`{{ }}`, `cms_html()` for stored HTML).

See `examples/themes/example-theme` for a complete working declaration.

## 18. Theme-scoped demo presets (CORE-THEME-2, v1.0.0-beta.7.1.25)

Demo presets create optional starter content; they are separate from page
templates (which only choose presentation). A theme ships zero or more presets
under `demo/{slug}/` in the generic declarative format (see the `DemoPackage`
manifest). Core owns every database write — a theme never imports directly.

- **Active-theme scoping.** Appearance → Import Demo lists only the *active*
  theme's presets (plus active-plugin packages). An inactive theme's already-
  imported preset stays listed for **reset only**. A theme with no presets
  exposes nothing.
- **`pages` file (new).** A preset may declare Pages with localized
  translations, a page-template id (validated against the owning theme's
  declarations — undeclared ids import without a template and warn), a status,
  `show_page_title`, and an optional `homepage: true` marker that assigns the
  static homepage (snapshot-captured, restored on reset).
- **Symbolic keys.** Records reference each other through stable keys
  (`"key": "example.landing"`), never database ids; the import provenance maps
  keys to created ids so re-import updates the same rows (idempotent).
- **Preview.** The admin Preview action is a read-only dry-run listing what an
  import would create/update/set/skip; custom handler files are listed as
  opaque.
- **Rollback.** Reset removes only importer-created pages/menus and restores
  the captured pre-import settings/layout. Owner content is never touched;
  nothing is imported automatically on activation, and slugs conflict-resolve
  through the normal uniqueness authority instead of overwriting.

Legacy note: the bundled Default theme's demo packages already use this
generic format; Core contains only the generic importer. Existing
installations keep their imported content — nothing is re-imported or
duplicated on upgrade.
