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
