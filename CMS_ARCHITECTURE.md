# TN CMS — Architecture (Single Source of Truth)

> This document is the **authoritative, code-verified** description of the
> TN CMS architecture. It is derived by reading the actual codebase,
> not the roadmap. Where something is only planned, it is listed under
> **PLANNED** and must **not** be treated as if it exists.

- **Brand:** **TN CMS** · <https://tncms.org> · support@tncms.org · author
  **The Nguyen Media** (`TheNguyen\CMS\Support\CmsInfo::BRAND` / `WEBSITE` /
  `SUPPORT_EMAIL` / `AUTHOR`). The PHP namespace remains `TheNguyen\CMS\`.
- **CMS version:** `1.0.0-beta.7.1.5` — CategoriesWidget Strict Per-Locale Display
  (`TheNguyen\CMS\Support\CmsInfo::VERSION`)
- **Verified against the codebase on:** 2026-06-21
- **Core package:** `packages/thenguyen/cms-core` (namespace `TheNguyen\CMS\`)
- **Companion docs:** [`CMS_STRUCTURE.md`](CMS_STRUCTURE.md) (deeper design
  notes + roadmap), [`CMS_CHANGELOG.md`](CMS_CHANGELOG.md) (versioned
  history), [`CMS_GUIDE.md`](CMS_GUIDE.md) (operational how-to),
  [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md) (theme developer guide),
  [`PLUGIN_DEVELOPMENT.md`](PLUGIN_DEVELOPMENT.md) (plugin developer guide).

## How to read this document

Every capability is tagged:

- ✅ **IMPLEMENTED** — exists in code today and is wired up.
- 🟡 **PARTIAL** — code exists but is not fully wired / not enforced.
- 🔵 **PLANNED** — intended later; **no working code yet**. Do not assume it.

---

## 1. Tech stack & top-level facts

| Item | Value |
| --- | --- |
| Framework | Laravel 12 |
| Admin UI | Filament v5 (single panel, id `admin`, path `/admin`) |
| PHP namespace (core) | `TheNguyen\CMS\` → `packages/thenguyen/cms-core/src/` |
| Seeder namespace (core) | `TheNguyen\CMS\Database\Seeders\` → `packages/thenguyen/cms-core/database/seeders/` |
| Global helpers | autoloaded via `composer.json` → `files: packages/thenguyen/cms-core/src/helpers.php` |
| Default locale | `vi` (the seeded default language; managed via the Language Manager, §6.5) |
| Languages | `vi` (default) + `en`, managed in Admin → CMS → Languages (`cms_languages`) |
| Service provider | `TheNguyen\CMS\Providers\CmsServiceProvider` (in `bootstrap/providers.php`) |
| Health endpoints | `/cms-health` (CMS JSON), `/up` (Laravel default) |

Registered application providers (`bootstrap/providers.php`):

```
App\Providers\AppServiceProvider
App\Providers\Filament\AdminPanelProvider
TheNguyen\CMS\Providers\CmsServiceProvider
```

---

## 2. Folder structure (as it exists today)

```
laravel-cms/
├── app/
│   ├── Filament/Admin/                 # App-level admin UI (Filament resources/pages/widgets)
│   │   ├── Components/                  # MediaPicker, MediaLibrarySelect, RichEditor (TinyMCE field)
│   │   ├── Pages/                       # SettingsPage, MediaUpload, ThemesPage
│   │   ├── Resources/                   # Page, Post, Category, Tag, Taxonomy, Media, Menu
│   │   └── Widgets/CmsInfoWidget.php
│   ├── Providers/Filament/AdminPanelProvider.php
│   └── (resources/views/filament/admin/components/rich-editor.blade.php) # TinyMCE view
├── packages/thenguyen/cms-core/        # CMS CORE (all reusable CMS logic lives here)
│   ├── config/cms.php
│   ├── database/
│   │   ├── migrations/                  # 15 cms_* migrations
│   │   └── seeders/                     # CmsSettingsSeeder, CmsContentSeeder, CmsMenuSeeder, CmsLanguageSeeder
│   ├── routes/
│   │   ├── web.php                      # /cms-health, /robots.txt, /sitemap.xml
│   │   └── frontend.php                 # public site routes
│   └── src/
│       ├── Console/Commands/ClearSettingsCacheCommand.php
│       ├── Facades/                     # Content, Html, Language, Media, Menu, Seo, Settings, Slug, Taxonomy, Theme
│       ├── Http/Controllers/            # FrontendController, SeoController
│       ├── Models/                      # 14 Eloquent models (see §5)
│       ├── Providers/CmsServiceProvider.php
│       ├── Services/                    # 12 manager services (incl. HtmlSanitizer, LanguageManager, PermalinkManager, ExtensionManager; see §4)
│       ├── Support/                     # CmsInfo, CmsPath, Theme (value object)
│       └── helpers.php                  # 12 global helper functions
├── themes/
│   └── default/                         # The one shipped theme (active)
│       ├── theme.json
│       ├── screenshot.png
│       ├── functions.php                # placeholder — NOT loaded by code yet (🔵)
│       ├── assets/{css,js,images}/
│       └── views/{layouts,pages,posts,partials,archives}/
├── public/
│   ├── themes/default/{css,js,images}/  # published theme assets (copied, not symlinked)
│   ├── uploads/YYYY/MM/                  # uploaded media
│   ├── vendor/cms/                       # reserved CMS asset dir (created on boot)
│   └── vendor/tinymce/                   # self-hosted TinyMCE assets (no CDN)
└── CMS_ARCHITECTURE.md (this file), CMS_STRUCTURE.md, CMS_CHANGELOG.md, CMS_GUIDE.md
```

> ⚠️ `app/Filament/Admin/Resources.rar` is a stray archive committed by
> accident. It is **not** part of the codebase and can be ignored/removed.

`CmsServiceProvider::boot()` ensures these directories exist on boot:
`base_path('modules')`, `base_path('themes')`, `public_path('uploads')`,
`public_path('themes')`, `public_path('vendor/cms')`.

---

## 3. Service container bindings & facades — ✅ IMPLEMENTED

Registered in `CmsServiceProvider::register()` as **singletons** (each also
aliased to its class FQCN). Each has a matching Facade.

| Binding | Service class | Facade (`getFacadeAccessor`) |
| --- | --- | --- |
| `cms.settings` | `Services\SettingsManager` | `Facades\Settings` → `cms.settings` |
| `cms.slug` | `Services\SlugManager` | `Facades\Slug` → `cms.slug` |
| `cms.content` | `Services\ContentManager` (deps `cms.slug`) | `Facades\Content` → `cms.content` |
| `cms.taxonomy` | `Services\TaxonomyManager` (deps `cms.slug`) | `Facades\Taxonomy` → `cms.taxonomy` |
| `cms.media` | `Services\MediaManager` | `Facades\Media` → `cms.media` |
| `cms.menu` | `Services\MenuManager` (deps `cms.slug`) | `Facades\Menu` → `cms.menu` |
| `cms.theme` | `Services\ThemeManager` | `Facades\Theme` → `cms.theme` |
| `cms.seo` | `Services\SeoManager` | `Facades\Seo` → `cms.seo` |
| `cms.html` | `Services\HtmlSanitizer` | `Facades\Html` → `cms.html` |
| `cms.language` | `Services\LanguageManager` | `Facades\Language` → `cms.language` |
| `cms.permalink` | `Services\PermalinkManager` | `Facades\Permalink` → `cms.permalink` |
| `cms.extension` | `Services\ExtensionManager` (deps `cms.theme`) | `Facades\Extension` → `cms.extension` |
| `cms.theme_option` | `Services\ThemeOptionManager` (deps `cms.theme`) | `Facades\ThemeOption` → `cms.theme_option` |
| `cms.permission` | `Services\PermissionManager` | `Facades\Permission` → `cms.permission` |
| `cms.maintenance` | `Services\MaintenanceManager` | `Facades\Maintenance` → `cms.maintenance` |
| `cms.extension_translation` | `Services\ExtensionTranslationManager` (deps `cms.language`, `cms.theme`, `cms.extension`) | `Facades\ExtensionTranslation` → `cms.extension_translation` |
| `cms.public_cache` | `Services\PublicContentCacheManager` | `Facades\PublicContentCache` → `cms.public_cache` (helper `public_content_cache()`) |

`ContentManager` depends on both `cms.slug` and `cms.html` (it sanitizes
the content body on every write).

### Public content resolution cache (1.0.0-beta.6.3)

`PublicContentCacheManager` (`cms.public_cache`) caches the resolved `cms_slugs`
reference (`reference_type` + `reference_id`) for a public path, so repeated
guest GETs skip the slug lookup. It caches **metadata, not HTML**:
`FrontendController` still loads and renders the referenced Content/Term
normally. Authenticated, non-GET, and preview/draft requests bypass the cache
entirely.

Invalidation is **versioned, not tag-based**. Every key embeds a monotonic
version read from `tncms.public.version`; `public_content_cache()->flush()`
increments it, orphaning the whole namespace in a single write (so it works on
file/database/array drivers with no tag support). The CMS service provider hooks
Eloquent `saved`/`deleted` on Content, Term, their translations, Slug,
Menu/MenuItem (+ translations), and Setting/SettingTranslation to flush
automatically — theme activation and permalink/language changes persist through
Setting, so they invalidate too. TTL is `config('cms.cache.public_ttl')`
(`CMS_PUBLIC_CACHE_TTL`, default 3600; `0` disables the cache).

`SettingsManager::get()` no longer runs `Schema::hasTable('cms_settings')` per
call: table availability is resolved once (trusting the install lock after
install, guarded schema check before) and memoized on the singleton, removing an
`information_schema` query from the hot frontend path. Term archives paginate via
`reading.posts_per_page` (floored at 1, capped at 100) instead of an unbounded
`->get()`, and `withQueryString()` preserves filters across pages. The default
theme renders these pages through `theme::partials.pagination`
(1.0.0-beta.7.1.8); the homepage "latest posts" path stays a bounded Collection
and the theme's pagination block is guarded to paginators only.

The admin **MediaPicker** grid (logo/favicon/featured-image; `MediaLibrarySelect`
+ `MediaItems`) searches the full `cms_media` table server-side via the
authenticated `filament.admin.cms-media-search` route (registered on the admin
panel's `authenticatedRoutes`), instead of filtering a fixed newest-50 snapshot
client-side — so large libraries (e.g. WordPress imports) stay searchable across
filename/url/path/alt/title/caption/description.

`CmsServiceProvider::boot()` also: publishes `config/cms.php` (tag
`cms-config`); registers the `theme::` view namespace
(`ThemeManager::registerViews()`); loads `routes/web.php`, then **boots active
plugins** (`ExtensionManager::bootActivePlugins()`), then `routes/frontend.php`
(so plugin routes register before the frontend catch-all); loads migrations from
the package; registers `ClearSettingsCacheCommand` (console only). It also
ensures `plugins/` exists (alongside `modules/`, `themes/`, `public/uploads`,
`public/themes`, `public/vendor/cms`).

---

## 4. Global helper functions — ✅ IMPLEMENTED

Defined in `packages/thenguyen/cms-core/src/helpers.php` (each guarded with
`function_exists`):

| Helper | Signature | Returns |
| --- | --- | --- |
| `settings()` | `settings(?string $key = null, mixed $default = null)` | manager when `$key` null, else the value |
| `cms_slug()` | `cms_slug(string $text, ?string $locale = null)` | a generated slug string (locale defaults to `current_locale()` → CMS default) |
| `menu()` | `menu(?string $locationOrSlug = null, ?string $locale = null)` | manager when null, else `Menu` by location-then-slug (locale defaults to `current_locale()`) |
| `theme()` | `theme(?string $slug = null)` | manager when null, else `Support\Theme` by slug |
| `extension()` | `extension()` | the `ExtensionManager` (extension framework orchestration) |
| `maintenance()` | `maintenance()` | the `MaintenanceManager` (maintenance mode core) |
| `extension_translation()` | `extension_translation()` | the `ExtensionTranslationManager` (interface translation framework) |
| `core_trans()` | `core_trans(string $key, array $replace = [], ?string $locale = null)` | translated core string (or the key) |
| `theme_trans()` | `theme_trans(string $key, array $replace = [], ?string $locale = null)` | translated active-theme string (theme → core → key) |
| `plugin_trans()` | `plugin_trans(string $plugin, string $key, array $replace = [], ?string $locale = null)` | translated plugin string (plugin → core → key) |
| `tn_trans()` | `tn_trans(string $key, array $replace = [], ?string $locale = null)` | alias of `core_trans()` |
| `theme_option()` | `theme_option(string $key, mixed $default = null, ?string $theme = null)` | resolved theme option value (stored → schema default → `$default`) |
| `theme_asset()` | `theme_asset(string $path)` | `/themes/{active}/{path}` |
| `theme_view()` | `theme_view(string $view)` | `theme::{view}` |
| `frontend_menu()` | `frontend_menu(string $location, ?string $locale = null)` | menu tree array (locale defaults to `current_locale()`), or `[]` |
| `content_url()` | `content_url(Content $content, ?string $locale = null)` | locale-aware `/{slug}` (page) or `/blog/{slug}` (post); `'#'` if no translation. Default locale → current. |
| `term_url()` | `term_url(Term $term, ?string $locale = null)` | locale-aware `/category/{slug}` or `/tag/{slug}`; `'#'` if no translation |
| `seo()` | `seo()` | the `SeoManager` for the current page context |
| `cms_html()` | `cms_html(?string $html)` | sanitized HTML for output (legacy plain text is escaped + line-broken) |
| `language()` | `language(?string $code = null)` | `LanguageManager` when null, else `Language` by code |
| `current_locale()` | `current_locale()` | admin **UI** locale code (`?lang` → falls back to default) |
| `editing_locale()` | `editing_locale()` | content **editing** locale code (`?locale` → `user.editing_locale` → session → default) |
| `localized_url()` | `localized_url(string $code, ?string $path = null)` | locale-aware path (honours `prefix_default`) |
| `language_switcher()` | `language_switcher()` | array of active languages with localized `url`/`active`/`direction`/`flag` for the current page |
| `do_action()` / `add_action()` | `do_action(string $hook, ...$args)` | run / register action callbacks (hooks; v1.0.0-beta.7.1.11) |
| `apply_filters()` / `add_filter()` | `apply_filters(string $hook, $value, ...$args)` | thread a value through filter callbacks |
| `render_hook()` | `render_hook(string $hook, ...$args)` | run an action hook and return its echoed output (theme-safe) |
| `do_shortcode()` / `add_shortcode()` | `do_shortcode(?string $content)` | expand / register content shortcodes |
| `strip_shortcodes()` | `strip_shortcodes(?string $content)` | remove shortcode tokens |

> `tn_*` aliases exist for every hook/shortcode helper; all are `function_exists`-guarded.

---

## 4a. Hooks & Shortcodes Foundation (v1.0.0-beta.7.1.11) — ✅ IMPLEMENTED

A WordPress-inspired, purely **in-process** extension layer — **not** a webhook
system. Two singletons resolved from the container:

- **`cms.hooks` (`HookManager`, `Hook` facade)** — actions (side effects via
  `do_action`) and filters (value transforms via `apply_filters`). Callbacks run
  in ascending priority, ties keep registration order, and each receives at most
  `acceptedArgs` arguments. A throwing callback is caught and logged so a broken
  extension never crashes the admin/frontend; filters pass the value through
  unchanged. `captureAction()`/`render_hook()` buffer echoed output for themes.
- **`cms.shortcodes` (`ShortcodeManager`, `Shortcode` facade)** — `[tag attr]…`
  tokens expand at render time. Only PHP-registered callbacks run; unknown tags
  are left untouched; enclosing tags render their inner content one level deeper
  (depth-bounded); callback exceptions preserve the original token. Output passes
  through the `cms.shortcode.output` filter.

**Content pipeline** (`FrontendController::renderContent`): the write-time
sanitized body is passed through `cms.content.body` → `cms.content.before_shortcode`
→ `do_shortcode()` → `cms.content.after_shortcode`, then re-sanitized by the
theme. Title/excerpt run through `cms.content.title`/`cms.content.excerpt`.
`cms.post.rendered`/`cms.page.rendered` fire after view render.

**Save & theme hooks:** `cms.content.saving|saved`, `cms.term.saving|saved`,
`cms.media.uploaded`; theme `cms.theme.header|before_content|after_content|footer`
rendered via `render_hook` in the default `layouts/master.blade.php`.

**Built-in shortcodes:** `[button]` (URL/target/text sanitized), `[year]`,
`[site_name]`. **Security:** no `eval`, no PHP-from-database, no class
instantiation from content; attribute parsing is pure string work.
`/cms-health` exposes `hooks_ready`, `shortcodes_ready`,
`registered_shortcode_count` (no callback names).

**Hook Context & Extensibility API (v1.0.0-beta.7.1.11.1):** core hook points
pass an immutable **`HookContext`** (`Support\Hooks\HookContext`) as their final
argument — null-safe accessors for request/user/locale(s)/theme/route/panel,
delivered without breaking existing callbacks (`acceptedArgs` slicing). A
**`HookDefinition`** registry on `HookManager` (`defineAction`/`defineFilter`/
`definitions`/`definition`/`definedActions`/`definedFilters`) documents hook
points for discovery; definitions are optional and never required for a hook to
run. `add_action`/`add_filter` accept optional `source`/`source_slug`/`label`
metadata, surfaced via `actionSummary()`/`filterSummary()` (counts/priorities/
source counts only — never callbacks). All 11 core actions and 6 core filters
are pre-defined. `/cms-health` adds `hook_definitions_count`,
`defined_action_count`, `defined_filter_count`, `hook_callback_count`. Still
in-process only — no webhooks/external HTTP.

---

## 5. Database schema — ✅ IMPLEMENTED

15 tables, all prefixed `cms_`, created by migrations in
`packages/thenguyen/cms-core/database/migrations`. The schema is
multi-language by design: translatable entities have a separate
`*_translations` table keyed by `(parent_id, locale)`.

### Settings

**`cms_settings`** — key/value store. `group` (nullable, indexed), `key`
(indexed), `value` (longText, nullable), `type` (default `string`),
`is_public` (bool), `autoload` (bool, default true), `description`,
timestamps. Unique `(group, key)`.

**`cms_settings_translate` (beta.6.2)** — per-language values for translatable
settings: `key` (indexed), `locale` (indexed), `value` (longText, nullable),
`type`, timestamps. Unique `(key, locale)`. `cms_settings` stays the permanent
global fallback. `SettingsManager::getLocalized()` / `setting_localized()`
resolve requested locale → CMS default locale → global → default and never
throw. Localized keys: `general.site_name`, `general.site_tagline`,
`general.site_description`, `seo.default_meta_title`,
`seo.default_meta_description`, `maintenance.title`, `maintenance.message`.
Permalink bases are intentionally global. SEO, maintenance, and the default
theme header/footer read these via `setting_localized()` so text follows the
active locale.

### Content (pages & posts)

**`cms_contents`** — `type` (e.g. `page`/`post`), `status`
(default `draft`), `author_id`, `parent_id`, `template`, `featured_image`
(string URL), `sort_order`, `comment_status` (default `closed`),
`published_at`, timestamps, **soft deletes**.

**`cms_content_translations`** — `content_id` (FK cascade), `locale`
(default `vi`), `title`, `slug`, `excerpt`, `content` (longText),
`meta_title`, `meta_description`, `meta_keywords`. Unique `(locale, slug)`
and `(content_id, locale)`.

### Taxonomy (categories & tags)

**`cms_taxonomies`** — `type` (e.g. `category`/`tag`), `content_type`
(default `post`), `slug`, `hierarchical`, `is_core`, `sort_order`. Unique
`(content_type, slug)`.

**`cms_terms`** — `taxonomy_id` (FK cascade), `parent_id` (self FK null on
delete), `featured_image` (global URL string, nullable), `sort_order`,
`count`, soft deletes.

**`cms_term_translations`** — `term_id` (FK cascade), `locale`, `name`,
`slug`, `description` (sanitized rich HTML), `meta_title`,
`meta_description`. Unique `(locale, slug)` and `(term_id, locale)`.

**`cms_content_terms`** — pivot. `content_id` + `term_id` (both FK
cascade). Unique `(content_id, term_id)`.

#### Why hierarchy lives at the taxonomy level (not on Category)

Hierarchy is a property of the **taxonomy**, switched by the single
`cms_taxonomies.hierarchical` flag and surfaced as `Taxonomy::isHierarchical()`
— it is **never** hardcoded for `category`. The category taxonomy is hierarchical
only because its row says so; the tag taxonomy is flat because its row says so.
Everything downstream keys off that one flag: the parent selector, the indented
tree list, the loop/integrity guards in `TaxonomyManager`, and the (global)
featured image. The admin side is built once in `AbstractTaxonomyResource`, which
`CategoryResource` and `TagResource` both extend.

The payoff is extensibility without further core change: a future Product,
Documentation, Forum, Literary, or plugin taxonomy becomes a full hierarchical
taxonomy by registering a row with `hierarchical = true` (and, for the admin, a
thin resource declaring its slug + content type). `parent_id` was always present
and is reused — there is no per-taxonomy schema branching.

**Global vs per-locale.** Hierarchy (`parent_id`) and `featured_image` live on
`cms_terms` and are **global** — shared by every locale. Only `name`, `slug`, and
`description` are per-locale (`cms_term_translations`). Adding a translation
localizes the label only; it never duplicates or forks the tree. Integrity guards
reject self-parenting, ancestor loops, missing/soft-deleted parents, and
cross-taxonomy parents at the service layer (so importers and plugins are
protected too), while the parent selector additionally hides the term's own
subtree so an invalid choice can't be made in the UI.

### URLs

**`cms_slugs`** — canonical URL index and **public-slug source of truth**.
`reference_type` (`content` | `term`), `reference_id`, `locale`, `slug`,
`prefix` (nullable, e.g. `blog`/`category`/`tag`; **null when base-less**),
`full_path`, `is_primary`. Unique `(locale, full_path)`. Written by
`ContentManager` / `TaxonomyManager`; read by `MenuItem::resolvedUrl()`, by
`FrontendController@resolveSlug` (the generic `/{slug}` dispatcher, via
`SlugManager::findPublic()` matching `full_path`), and by
`SlugManager::uniquePublicSlug()` to enforce **global per-locale slug
uniqueness** across pages/posts/categories/tags. The bare `slug` column is the
WordPress-style unique public slug; `full_path` adds the base prefix (if any).

**Localized slugs (beta.6.1).** Slugs live entirely in the translation layer:
`cms_content_translations` / `cms_term_translations` own a per-locale `slug`
(unique `(locale, slug)`); the parent `cms_contents` / `cms_terms` carry **no**
slug column. Each locale therefore has an independent slug and its own
`cms_slugs` row (`vi: trang-chu` and `en: home` for the same record), so editing
one locale's slug never affects another's, and `findPublic($fullPath, $locale)`
404s a wrong-locale path. `cms_slugs` is written on every save; the
**`tncms:slugs:rebuild`** command (`SlugManager::rebuildAllPublicSlugs()`)
repairs/backfills every public-URL row from the translation slugs, preserving
the bare slug verbatim. (`rebuildPublicSlugs()` remains the lighter
prefix/full_path recompute used on a permalink-base change.)

### Media

**`cms_media`** — `disk` (default `public`), `folder`, `filename`,
`original_filename`, `extension`, `mime_type`, `size`, `width`, `height`,
`path`, `url`, `alt`, `title`, `caption`, `description` (text, nullable; added
in 0.9.3), `uploaded_by`, timestamps, soft deletes.

**`cms_mediables`** — 🟡 **PARTIAL / reserved.** Polymorphic pivot
(`media_id`, `mediable_type`, `mediable_id`, `collection`, `sort_order`)
exists as a migration **but is not referenced by any model or service**.
There is no `Mediable` model. Reserved for future media attachments;
featured images are currently stored as a plain URL string on
`cms_contents.featured_image`.

### Menus

**`cms_menus`** — `slug` (unique), `location` (nullable; e.g.
`header`/`footer`/`sidebar`), `status` (default `active`), `is_system`,
`sort_order`, soft deletes.

**`cms_menu_translations`** — `menu_id` (FK cascade), `locale`, `name`,
`description`. Unique `(menu_id, locale)`.

**`cms_menu_items`** — `menu_id` (FK cascade), `parent_id` (self FK null on
delete), `type` (default `custom`; also `page`/`post`/`category`/`tag`),
`reference_type` (`content`/`term`/null), `reference_id`, `url`, `target`
(default `_self`), `css_class`, `icon`, `sort_order`, `is_active`, soft
deletes.

**`cms_menu_item_translations`** — `menu_item_id` (FK cascade), `locale`,
`title`. Unique `(menu_item_id, locale)`.

### Languages (Phase 9)

**`cms_languages`** — language registry. `code` (string 20, unique; e.g.
`vi`/`en`/`ja`), `locale` (nullable; e.g. `vi_VN`), `name`, `native_name`
(nullable), `flag` (nullable), `direction` (default `ltr`; `ltr`/`rtl`),
`is_default` (bool, indexed), `is_active` (bool, indexed), `sort_order`
(indexed), timestamps. The single-default rule is enforced at the **service
layer** (`LanguageManager`), not by a DB constraint. Seeded with `vi`
(default) + `en` by `CmsLanguageSeeder`.

### Models (`src/Models`) — ✅ IMPLEMENTED

`Content`, `ContentTranslation`, `ContentTerm`, `Taxonomy`, `Term`,
`TermTranslation`, `Slug`, `Setting`, `Media`, `Menu`, `MenuTranslation`,
`MenuItem`, `MenuItemTranslation`, `Language`. (No `Mediable` model — see
above.) `Content` and `Term` expose `localeSlug(locale)` (exact-locale slug,
no fallback) and `hasTranslation(locale)`.

### Seeders — ✅ IMPLEMENTED

`CmsSettingsSeeder` (general/seo/admin/system + `theme.active`),
`CmsContentSeeder` (sample page `trang-chu`, sample post
`bai-viet-dau-tien`, `category`/`tag` core taxonomies),
`CmsMenuSeeder` (header & footer menus, idempotent), `CmsLanguageSeeder`
(`vi` default + `en`, plus `language.default` / `language.prefix_default`
settings, idempotent).

---

## 6. Settings system — ✅ IMPLEMENTED

`SettingsManager` (`cms.settings`). Methods: `get`, `set`, `has`, `forget`,
`all`, `group`, `clearCache`, `refresh`, `isCached`. Autoloaded settings
are cached (`cms.settings.autoload`); `set`/`forget` clear the cache. All
methods are table-existence guarded (safe before migration). Console
command `cms:settings:clear-cache` (`ClearSettingsCacheCommand`) flushes
the cache.

**Settings Polish (0.9.6).** The admin **Settings** page (`/admin/settings`,
`App\Filament\Admin\Pages\SettingsPage`) is a tabbed form — **General,
Reading, Writing, Media, SEO, Permalinks** — over the existing `cms_settings`
table (no new table). It uses a flat state array mapped to dotted keys on save
(see `SettingsPage::editable()`), then clears the autoload cache. Seeded /
known keys by group:

- **general:** `site_name`, `site_tagline`, `site_description`, `admin_email`,
  `timezone`, `date_format`, `time_format`, `favicon`
- **reading:** `homepage_display` (`latest_posts`|`static_page`, **not seeded**
  → legacy home), `homepage_page_id`, `posts_page_id`, `posts_per_page`,
  `feed_items_count`, `feed_content_mode`
- **writing:** `default_post_status`, `default_comment_status`,
  `default_category_id`, `default_language`, `default_post_format`
- **media:** `organize_uploads_by_date`, `max_upload_size_mb`,
  `auto_alt_from_filename`, `auto_title_from_filename`,
  `sizes.thumbnail.{width,height,crop}`, `sizes.medium.{width,height}`,
  `sizes.large.{width,height}`, `custom_sizes` (array of
  `{name,width,height,crop}`)
- **seo:** `noindex_site`, `title_separator`, `default_meta_title`,
  `default_meta_description`, `default_og_image`, `robots_default`
  (+ legacy `default_title`, `default_description`)
- **permalink:** `post_base` (`blog`), `category_base` (`category`),
  `tag_base` (`tag`) — each may be left **empty** for base-less URLs (see §12)
- **admin:** `brand_name`; **system:** `deployment_mode`; `theme.active`

> 🔵 **Settings Polish — not implemented:** resized image generation,
> WebP/AVIF conversion, Theme Options, Widgets.
>
> **Base-less permalinks (done in 0.9.6):** an empty `post_base` /
> `category_base` / `tag_base` is fully supported — the record resolves at
> `/{slug}` via `cms_slugs` (see §7, §12). Saving a changed base rebuilds
> `cms_slugs` (`SlugManager::rebuildPublicSlugs()`); the route table reads the
> bases at registration, so a base change needs `php artisan route:clear`.

---

## 6.5 Language system (Phase 9) — ✅ IMPLEMENTED

**`LanguageManager` (`cms.language`)** owns the language registry and the
default/current locale resolution. The "current" locale is **per-request
state** on the singleton (set by `FrontendController`), never persisted.

Methods: `all($activeOnly=true)`, `active()`, `default()`, `defaultCode()`,
`current()`, `currentCode()`, `find($code)`, `setCurrent($code)`,
`setDefault($code)`, `create()`, `update()`, `delete()`,
`deletionBlockReason($language)`, `hasTranslatedData($code)`, `isActive($code)`,
`normalizeCode()`, `shouldPrefixDefaultLocale()`, `localizedUrl($code,$path)`,
`getPublicLocales()`, `optionList()`, and static `routeLocalePattern()`. All
DB access is table-guarded; before the table exists everything falls back to
`vi`. **CMS content/term translation fallback resolves through `defaultCode()`,
never `config('app.locale')` or a hardcoded `'vi'`** (beta.6 / B1).

**Settings used:** `language.default` (default code, fallback `vi`) and
`language.prefix_default` (bool, default `false` — when `false` the default
language renders at unprefixed URLs).

**Service-layer rules:** exactly one default language (`setDefault` /
`create` / `update` unset the others); the default language is always kept
active and **cannot be deleted**.

**Safe deletion (beta.6 / B3):** `delete()` is non-destructive — it refuses
(returning `false`) when `deletionBlockReason()` reports the language is the
default, the last remaining language, or still owns rows in any per-locale
table (`cms_content_translations`, `cms_term_translations`,
`cms_menu_translations`, `cms_menu_item_translations`, `cms_slugs`). No cascade
delete; remove/reassign that locale's data first. The admin surfaces the reason
as a notification.

**Code format & permission (beta.6 / B2, B4):** the Languages form normalizes
`code` (lowercase/trim) before validation and constrains it to
`^[a-z]{2}(-[a-z]{2})?$` (e.g. `vi`, `en`, `pt-br`), so a case/whitespace
variant of an existing code fails with a friendly error instead of a duplicate-
key exception. `LanguageResource` is gated by **`languages.manage`**.

**Route cache (beta.6 / B5):** `routeLocalePattern()` (the `vi|en|…` constraint
on the `{locale}` route group) is built at route-registration time, so creating,
editing, or deleting a language clears the route cache (`route:clear`) and
notifies the admin; a failure warns to run `php artisan route:clear` manually.

**URL prefixing** (`localizedUrl`): default locale + `prefix_default=false`
→ unprefixed (`/blog/x`); any other case → `/{code}/blog/x` (or `/{code}` for
the home path). `content_url()` / `term_url()` delegate here and return `'#'`
when the requested locale has no translation.

**Admin:** `LanguageResource` (Admin → CMS → Languages). Per-translation
**locale selectors** on Page/Post/Category/Tag/Menu forms. As of **0.9.1**
the selector **live-loads** the chosen locale: changing it redirects to the
same edit page with `?locale=<code>` and re-mounts the form (so the TinyMCE
editor re-initialises with the right content); a missing translation loads
empty fields. Non-translatable fields are preserved; saving writes only the
selected locale's translation. Shared via the `App\Filament\Admin\Concerns\
HasLocaleSelect` and `EditsTranslationLocale` traits. Switching discards
unsaved edits in the current form (intentional, prevents cross-locale
overwrite).

As of **0.9.2** the **menu item** titles in `MenuItemsRelationManager` also
follow the Menu edit locale: the relation manager renders non-lazily and
captures the parent page's `?locale=` value in `mount()` (persisted as
`editLocale`), then reads/writes each item title for that locale only. The
item table shows the selected locale's title (falling back to the default
title flagged `(default)`), and the parent-item selector uses the selected
locale title with a default fallback.

---

## 7. Content & taxonomy systems — ✅ IMPLEMENTED

**`ContentManager` (`cms.content`)** — `create(array)`,
`update(Content, array)`, `delete(Content)`, `findBySlug(slug, locale,
type=null)`, `getTranslation(Content, locale)`. All writes run in DB
transactions and upsert the translation + the `cms_slugs` row. Post slugs
get the `blog` prefix; pages get no prefix.

**`SlugManager` (`cms.slug`)** — `generate(text, locale)` (Vietnamese-aware
+ unicode-safe), `uniqueContentSlug(...)`, `uniqueTermSlug(...)`,
`makeFullPath(slug, prefix)`, and (0.9.6) the public-slug methods:
`uniquePublicSlug(slug, locale, referenceType=null, referenceId=null)` —
globally-unique-per-locale slug across pages/posts/categories/tags (checks
`cms_slugs`, ignores the owning record, skips reserved prefixes,
auto-increments `slug-2`); `findPublic(slug, locale)` — resolve a path to its
`cms_slugs` row (matches `full_path`); `rebuildPublicSlugs()` — recompute every
`cms_slugs` prefix/full_path from the current permalink bases. All are
table-guarded. `ContentManager` / `TaxonomyManager` now reserve slugs via
`uniquePublicSlug()`.

**`TaxonomyManager` (`cms.taxonomy`)** — `ensureCoreTaxonomies()`,
`createTerm(taxonomySlug, array)`, `updateTerm(Term, array)`,
`deleteTerm(Term)`, `findTermBySlug(slug, locale, taxonomySlug=null)`,
`treeOrderedTerms(taxonomyId, excludeId=null)` (depth-first, depth-tagged tree
for the admin list + parent selector). Writes persist the global `parent_id` /
`featured_image`, sanitize the rich description, and enforce the parent
integrity guards (self / loop / missing / soft-deleted / cross-taxonomy). Core
taxonomies: `category` (hierarchical) and `tag` (flat), both for `post`.

---

## 8. Media system — ✅ IMPLEMENTED

**`MediaManager` (`cms.media`)** — `upload(UploadedFile, meta=[])`,
`delete(Media)`, `find(int)`, `all()`. Uploads are stored under
`public/uploads/YYYY/MM`, filenames are SEO-slugified, the `url` is a
public path string. Image dimensions are read for
`jpg,jpeg,png,gif,webp,bmp,svg`. As of **0.9.3** `upload()` defaults `alt`
to the humanized original filename when none is supplied (user-provided
`alt`/`title`/`caption`/`description` are never overwritten) and accepts
`description` in `$meta`.

**`Media` SEO helpers (0.9.3)** — `seoAlt(): string` (alt → title →
humanized original/stored filename), `seoTitle(): ?string` (title → alt →
null), `seoCaption(): ?string`, `seoDescription(): ?string`
(description → caption → null), and the static `humanizeFilename()`. All are
null-safe. These resolve the defaults used by the Rich Editor's Insert Media
modal and the featured-image preview.

Admin: `MediaResource` (Library, `/admin/media`), `MediaUpload` page
(`/admin/media/upload`), and the reusable `MediaPicker` form component used
by Page/Post `featured_image`. Featured image is persisted as a **URL
string** on `cms_contents.featured_image` (no `cms_mediables` relation
yet). The Media **edit form** (0.9.3) is a metadata column (alt, title,
caption, description, each with help text) plus a sidebar with preview, a
copyable URL, and read-only file info; the file itself cannot be replaced.
The Media **table** exposes alt/title/caption columns, searches all metadata
fields, and offers Images / Documents / Missing-alt / Missing-title /
Missing-caption filters.

**Featured-image picker (0.9.4)** — `MediaPicker::make('featured_image',
'Featured image')` returns a Filament `Group`: a reactive **preview**
(empty "Add image" state, or the selected image with its resolved metadata +
**Replace**/**Remove**), an `Actions` row, and a `Hidden` field holding the
URL. **Add/Replace** opens a native Filament action modal ("Select featured
image") with two tabs — **Media Library** (the `MediaLibrarySelect` field: an
Alpine thumbnail grid + search + details panel, snapshot of image media,
newest-first, max 50; selecting writes the URL) and **Upload files** (a
`FileUpload`, image-only, drag & drop, routed through `MediaManager::upload()`;
the uploaded image wins on submit). The stored value is still the URL string;
a URL not found in `cms_media` previews and is labelled "External or missing
media record". No DB change.

**Shared media support — `App\Filament\Admin\Support\MediaItems` (0.9.5)** —
the single source of truth for the image list/shape used by every media modal
and preview. `imageQuery()` (base image query, newest first), `imageItems(int)`
(normalised array, max N), `normalize(Media): array` (one canonical shape:
`id,url,name,filename,alt,title,caption,description,width,height,size,
created_at,seo_alt,seo_title`), `findByUrl(string): ?array` (preview lookup),
and `searchImages(string,int): array` (server-side LIKE over
filename/alt/title/caption/description). All table/column-guarded. Consumed by
`RichEditor::getMediaItems()`, `MediaLibrarySelect::getMediaItems()`, and
`MediaPicker` (preview).

**Media UX Polish (0.9.5)** — table **bulk actions** added: *Generate alt from
filename* (fills alt only where empty) and *Clear metadata* (alt/title/caption/
description → null, confirm). The Media **edit page** gained the same two as
header actions (in-place form refresh). The Featured Image **Upload tab** shows
the note *"Uploaded image will be used as the featured image."* and a preview
before submit. The Rich Editor **Insert Media** search now also matches caption
and description (parity with the Featured Image modal). No DB change.

> 🔵 **Not in this phase:** gallery / multi-select, folder manager, image
> crop/editor, image replacement, WebP/AVIF conversion, S3/CDN, and a
> `featured_image_media_id` relation.

---

## 8b. Security architecture (1.0.0-beta.6.4) — ✅ IMPLEMENTED

Security follows fail-safe defaults: a single server-side enforcement point per
concern, allow-lists over deny-lists for data, and an always-on hard deny-list
for the few things that can never be safe.

### Storage & public file serving

- There is **no route that serves raw `storage/{path}`**. Uploads are plain
  static files under `public/uploads/YYYY/MM/`. Filenames are slugified by
  `MediaManager` (`Str::slug` on the base name, lowercased extension), so `../`
  traversal and embedded dots (`evil.php.jpg` → `evil-php.jpg`) cannot produce a
  dangerous path.
- The default filesystem disk is **private** (`local` → `storage/app/private`);
  the public disk is separate and contains only intentionally public assets.
- `public/uploads/.htaccess` (Apache 2.2 + 2.4) and `public/uploads/web.config`
  (IIS) disable script execution (`RemoveHandler`/`engine off`/denied handlers),
  directory listing, and access to dot/sensitive files, recursively.

### Upload security

`MediaManager::upload()` is the **only** path uploads take from the media
library, media picker, and rich editor, and it validates every file before any
bytes are written, in this order:

1. **Hard deny-list** (`cms.media.denied_extensions`) — `php*`, `phtml`, `phar`,
   `exe`, `bat`, `sh`, `js`, `html`, … rejected even if mis-whitelisted.
2. **Extension allow-list** (`cms.media.allowed_extensions`, default
   `jpg, jpeg, png, gif, webp, avif, pdf`).
3. **Real-MIME allow-list** (`cms.media.allowed_mimes`) from `finfo` — defeats a
   payload disguised behind a permitted extension (PHP renamed to `.jpg`).
4. **Size ceiling** — the smaller of `cms.media.max_upload_size` and the
   `media.max_upload_size_mb` admin setting.
5. **SVG** is opt-in (`cms.media.allow_svg`, default off). When enabled, every
   SVG is run through `SvgSanitizer` (strips `<script>`, `on*` handlers,
   `<foreignObject>`/animation elements, `javascript:`/`data:` URLs, neutralizes
   DOCTYPE/ENTITY) before storage; unparseable SVG is rejected.

Bundled demo assets imported via `MediaManager::importFile()` are trusted,
server-side, caller-validated paths and are not subject to the upload gate.

### HTML sanitization (audited, unchanged)

`HtmlSanitizer` (native `DOMDocument`, attribute allow-list) sanitizes rich-text
content on save in `ContentManager` and again on render via `cms_html()`. It
removes dangerous elements (`script`, `style`, `iframe`, `svg`, `math`,
`foreignObject`, form controls), strips all `on*` handlers, and validates `href`/
`src` schemes (blocks `javascript:`, `vbscript:`, and non-image `data:` URIs).

### Installer lock

`InstallerManager::isInstalled()` returns true when **any** of: the marker file
`storage/app/tncms-installed` exists, `TN_CMS_INSTALLED` is truthy, **or** the
database is reachable and already populated (users table has rows). The
`RedirectIfInstalled` middleware wraps all wizard routes (GET *and* POST), so the
installer cannot be re-run by deleting the marker, forging session state, or
POSTing directly. DB errors during a real fresh install are `report()`ed and
treated as not-installed so the wizard can still run.

### Public content cache (audited, unchanged)

Cache keys are namespaced `tncms.public.*.{version}.{locale}.{sha1(path)}`.
Authenticated, non-GET, and draft/preview (`status !== 'published'`) responses
are never cached; query strings are excluded from keys (no query-string
poisoning or unbounded key growth).

### Health posture

`/cms-health` exposes booleans only — `storage_secure`, `media_upload_secure`,
`installer_secure`, `cache_secure`, `sanitizer_secure`, `public_file_secure` —
and never leaks paths, extension lists, MIME lists, or other server internals.

---

## 8c. Boot safety & query insight (1.0.0-beta.6.4.2) — ✅ IMPLEMENTED

### Boot safety

`CmsServiceProvider::boot()` resolves install state once via
`InstallerManager::isInstalled()`. The check short-circuits on the DB-free fast
path — a marker file (`storage/app/tncms-installed`) or `TN_CMS_INSTALLED` — and
only falls back to a single guarded DB existence check when both are absent
(supporting sites installed without the web wizard). Until the CMS is installed,
all database-touching boot steps are skipped: theme view registration, active
plugin booting, and extension translations. Installer and core (`web.php`)
routes always register. Result: a fresh, pre-install boot performs no feature
queries and never fails on an unmigrated or unreachable database.

### Hot-path memoization

Per-request affirmative memoization removes repeated schema introspection and
default-language lookups on the public hot path:

- `SettingsManager` memoizes `cms_settings` and `cms_settings_translate`
  existence; autoload and translation maps are cached (`Cache::rememberForever`,
  invalidated by `clearCache()`).
- `LanguageManager` memoizes table existence, the active-language collection,
  and the resolved default language (reset by `flush()` on any language change).
- `ThemeManager` and `ExtensionManager` memoize their directory scans (valid +
  invalid), invalidated by `flushRegistry()` on install/delete/activate.

### Query budget & profiler

The `QueryInsights` middleware wraps public routes:

- **Budget:** counts queries per request and `report()`s when the page type's
  budget (`cms.performance.query_budget.budgets`) is exceeded. It never alters
  or fails the response — the page renders and the overage is logged. Page type
  comes from a request attribute set by `FrontendController`
  (`homepage`/`content`/`archive`/`category`/`tag`), falling back to route name.
- **Profiler:** off unless `CMS_DEBUG_QUERIES=true`; then it adds
  `X-TNCMS-Queries`, `X-TNCMS-Time`, and `X-TNCMS-Cache`
  (`HIT`/`MISS`/`BYPASS`/`NONE`, from `PublicContentCacheManager::lastCacheState()`).

`/cms-health` exposes booleans only: `boot_safe`, `settings_hot_path`,
`language_hot_path`, `extension_registry_cache`, `query_budget_ready`,
`query_profiler_ready`.

---

## 9. Menu system — ✅ IMPLEMENTED

**`MenuManager` (`cms.menu`)** — `createMenu`, `updateMenu`, `deleteMenu`,
`createItem`, `updateItem`, `deleteItem`, `getMenuBySlug`,
`getMenuByLocation`, `tree(Menu, locale)`, `reorderItems`. Menus carry a
`location` slot; items support nesting via `parent_id` and link to
custom URLs or to page/post/category/tag references (`reference_type` is
derived from `type`).

`tree()` returns a nested array of nodes shaped:
`['item' => MenuItem, 'title' => string, 'url' => string, 'children' => array]`,
where `url` comes from `MenuItem::resolvedUrl()` (reads `cms_slugs`, falls
back to the stored URL or `#`).

Admin: `MenuResource` (`/admin/menus`) with the
`MenuItemsRelationManager` for item CRUD on the edit page. Item titles are
edited per the Menu edit locale (`?locale=`); see §6.5. `frontend_menu()` and
`MenuManager::tree()` render item titles in the requested/current locale, with
`MenuItem::displayTitle()` falling back to the first available translation.

---

## 10. Theme system — ✅ IMPLEMENTED (hardened in 0.9.7)

**`ThemeManager` (`cms.theme`)** — `all()`, `invalidThemes()`, `find(slug)`,
`active()`, `activeSlug()`, `activate(slug)`, `requiredViewsResolvable(slug)`,
`deactivate()`, `canDeactivate()`, `themeSystemReady()`, `registerViews()`,
`viewNamespacePaths()`, `themePath(slug)`, `themeAssetsPath(slug)`,
`publishAssets(slug)`.

- **Discovery + manifest validation (0.9.7):** scans `themes/` for folders with
  a `theme.json` that is valid JSON **and** has non-empty `name`, `slug`,
  `version`, `author`. Valid themes build a `Support\Theme` value object
  (`name, slug, version, author, description, path, screenshot, supports,
  authorUri, supportEmail`). Folders with a missing/invalid manifest are
  **skipped** from `all()`/`find()` and reported by `invalidThemes()`
  (`['slug','reason']`) — they never break the admin.
- **Active theme (stored vs effective, 0.9.7):** the raw selection lives in
  `cms_settings` under `theme.active` (`activeSlug()`; may be `null`). `active()`
  returns the **effective** theme: the stored slug if it resolves, else the
  `default` theme, else the first discovered theme, else `null` only when no
  valid theme exists. So the frontend never silently renders with no theme just
  because `theme.active` was cleared. `themeSystemReady()` = `active() !== null`.
- **Switch-only activation, transactional (0.9.7):** there is **no Deactivate
  action** — the admin only ever *switches* the active theme via `activate()`.
  `activate(slug)` is safe/transactional: it (1) remembers the current active
  slug, (2) validates the target manifest, (3) verifies the required views
  resolve via `requiredViewsResolvable()` (target → default fallback for
  `layouts/master`, `pages/page`, `posts/post`, `archives/index`), (4) publishes
  the target assets, (5) sets `theme.active`, then (6) clears the settings cache
  and re-registers views. **Nothing mutates until step 5**, so on any failure
  the previously active theme is unchanged and the method returns `false`.
  `canDeactivate()` always returns `false` (no UI deactivation). `deactivate()`
  is kept for programmatic callers only: it switches to another valid theme or
  returns `false`, and **never** sets `theme.active` to `null`.
- **Assets:** `publishAssets()` **copies** `themes/{slug}/assets` →
  `public/themes/{slug}` (no symlinks — shared-hosting friendly).
- **View namespace:** `registerViews()` registers `theme::` pointing at
  `[themes/{active}/views, themes/default/views]`, so a missing view in the
  active theme falls back to `default`. Safe during boot (never throws).

Admin: `ThemesPage` (`/admin/themes`) — card grid with an **Active** badge.
The active theme shows the badge only (no Activate/Deactivate buttons); inactive
valid themes show an **Activate** button that *switches* the active theme. If
activation fails (invalid manifest, missing required views, asset publish
error), the current theme stays active and a danger notification explains why.
Invalid themes are listed in a warning section. Screenshots are embedded as data
URIs (the `themes/` dir is not web-served).

See [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md) for the theme author guide.

**Theme `functions.php` (v0.9.9):** `themes/{slug}/functions.php` is now loaded
safely by `ThemeManager::themeConfig()` as a *config-returning* file (see §10.6).
`theme.json`'s `requires.cms` remains descriptive only — **not enforced**;
`supports.theme_options` is descriptive (the actual option **schema** decides
availability, not the flag).

---

## 10.5 Extension Framework Core (v0.9.8) — ✅ IMPLEMENTED

**`ExtensionManager` (`cms.extension`)** is the orchestration layer over
extensions — **themes** (delegated to `ThemeManager`) and **plugins**
(discovered here). Facade `Extension`, helper `extension()`. This is the
foundation for the future Plugin Manager, Theme Options framework, marketplace,
and ZIP installer — **none of which exist yet** (no UI, no installer).

- **Themes:** `themes()` and `invalidThemes()` delegate to `ThemeManager`.
- **Plugin discovery + validation:** plugins live in `plugins/{slug}/` with a
  `plugin.json` (required `name`, `slug`, `version`, `author`; optional
  `description`, `providers`, `requires`). `plugins()` returns valid
  `Support\Plugin` value objects; `findPlugin(slug)`; `invalidPlugins()` returns
  `[{slug, reason}]`. Invalid/missing manifests are skipped and never crash.
- **Active registry:** stored in `cms_settings` under
  `extensions.active_plugins` (JSON array of slugs — **no new table**). APIs:
  `isPluginActive(slug)`, `activatePlugin(slug)` (validates manifest;
  idempotent), `deactivatePlugin(slug)`, `activePlugins()`, `activePluginSlugs()`.
- **Safe boot (`bootActivePlugins()`):** for each active, valid plugin it
  registers a runtime PSR-4 autoloader (`Plugins\{StudlySlug}\` →
  `plugins/{slug}/src/`, so no `composer dump-autoload` is needed), registers the
  declared service `providers`, registers the `{slug}::` view namespace
  (`view('hello-world::welcome')`), makes `database/migrations` discoverable to
  the migrator, and loads `routes/web.php` in the `web` group. **Each plugin is
  isolated in try/catch** — a broken plugin is logged and skipped, never breaking
  boot. Called from `CmsServiceProvider::boot()` **before** the frontend
  catch-all, so a plugin route (e.g. `/hello-world`) wins over `/{slug}`.
- **Health:** `extensionFrameworkReady()` (bound + discovery runs without error).

`plugins/hello-world` is a working sample (route `/hello-world`, view
`hello-world::welcome`, a provider binding). See
[`PLUGIN_DEVELOPMENT.md`](PLUGIN_DEVELOPMENT.md) for the plugin author guide.

The **Plugin Manager UI** (Installed Plugins page + activate/deactivate) shipped
in `1.0.0-beta.1` — see §10.7. 🔵 **Still not built:** plugin installer / ZIP
upload, plugin file editor, plugin settings UI, widgets, marketplace. Only
`routes/web.php` is loaded (no API route files yet); `requires.tncms` is
descriptive only.

---

## 10.5.1 Plugin Manager UI (v1.0.0-beta.1) — ✅ IMPLEMENTED

The admin surface over the Extension Framework (§10.5). **No new table, no new
panel** — it only toggles the active-plugin registry in `cms_settings`; it never
installs, edits, or deletes plugin files.

A **Plugins** navigation group (ordered after *Appearance* via the panel's
`navigationGroups()`) with three pages:

- **`InstalledPluginsPage`** (`/admin/plugins`, group *Plugins*, icon
  `heroicon-o-puzzle-piece`, sort 10) — ✅ fully implemented. A card grid of valid
  plugins from `extension()->plugins()` showing name, description, slug, version,
  author, `requires.tncms`, the provider FQCN list + count, and an
  **Active**/**Inactive** badge. **Activate** (inactive) / **Deactivate** (active)
  buttons call `extension()->activatePlugin()` / `deactivatePlugin()` inside
  try/catch and emit success/danger notifications. An **active** plugin whose
  declared provider class is missing is flagged with a warning on its card
  (`class_exists` is reliable for active plugins — their PSR-4 autoloader is
  registered at boot; inactive plugins are not checked, to avoid false
  positives). A separate **Invalid plugins** section lists
  `extension()->invalidPlugins()` (`{slug, reason}`) with no Activate button. The
  admin never crashes on a broken plugin.
- **`InstallPluginPage`** (`/admin/plugins/install`, sort 20) — ✅ functional ZIP
  installer as of `1.0.0-beta.2` (see §10.5.2).
- **`PluginEditorPage`** (`/admin/plugins/editor`, sort 30) — 🔵 placeholder
  ("Coming in a future beta." + "Plugin file editing is security-sensitive and
  will be limited to safe file types.").

**Route/cache note.** Plugin routes register at application **boot** from the
active set, so a registry change only affects `/{plugin-route}` resolution on the
**next** request. After activate/deactivate the page clears the settings cache
and best-effort runs `Artisan::call('optimize:clear')` (non-destructive, in
try/catch), and the view shows a note that some environments need
`php artisan optimize:clear` or a reload. No destructive commands are run.

`/cms-health` adds `plugin_manager_ui_ready` (`InstalledPluginsPage` exists &&
extension framework ready).

🔵 **Not in this phase:** plugin file editor, plugin settings UI, marketplace,
remote/CLI installer, license manager. (The plugin **ZIP installer** ships in
`1.0.0-beta.2` — see §10.5.2.)

---

## 10.5.2 Theme/Plugin ZIP Installer (v1.0.0-beta.2) — ✅ IMPLEMENTED

Safe **local ZIP upload** installation for both extension types. **No new table,
no new panel.** Reusable logic lives in core; the admin pages are thin wrappers.

**`ExtensionInstaller` (`cms.extension_installer`)** — facade `ExtensionInstaller`,
helper `extension_installer()`. API:

- `installPluginFromZip(string $zipPath, bool $overwrite = false): InstallResult`
- `installThemeFromZip(string $zipPath, bool $overwrite = false): InstallResult`
- `deletePlugin(string $slug): InstallResult`
- `deleteTheme(string $slug): InstallResult`
- `isReady(): bool` — true when the PHP `zip` extension is loaded.

It **never throws to the UI** — every outcome is an immutable
`TheNguyen\CMS\Support\InstallResult` (`success`, `message`, `type`, `slug`,
`installedPath`, `errors[]`, `warnings[]`).

**Pipeline (shared by both types).** 1) validate the file (readable, `.zip`,
openable); 2) validate **every entry name before extracting** — reject path
traversal (`..`), absolute/drive-letter paths, empty names, and
too-many/too-large archives; 3) extract into a throwaway
`storage/app/tncms-installer/{random}` (**never** straight into `themes/` or
`plugins/`); 4) post-extraction **symlink** guard; 5) locate the single manifest
(`plugin.json`/`theme.json`) — supports a wrapping root folder **or** a flat
archive; **multiple manifests → ambiguous → rejected**; 6) validate the manifest
(`name`, `slug`, `version`, `author`; slug lowercase slug-like and not reserved);
7) type-specific structure check; 8) apply the overwrite policy; 9) copy the
validated source into `{type}/{slug}`. The temp dir is **always** removed in a
`finally`, on success or failure.

**Slug safety.** Lowercase `^[a-z0-9][a-z0-9_-]*$`; reserved names rejected:
`admin, themes, plugins, vendor, storage, public, core, app, routes, config`. The
destination folder is derived from the **manifest slug**, never from the archive's
folder name.

**Theme structure.** Requires `views/layouts/master.blade.php` unless a valid
`default` theme exists to inherit from (mirrors the ThemeManager view fallback,
§10/§THEME). **Plugin providers are never executed or required at install**; a
declared provider with no `src/` directory is a non-fatal **warning** only.

**Overwrite policy.** Default `false`: a duplicate slug fails with a clear
message. With `overwrite = true` only `{type}/{slug}` is replaced — and an
**active** theme/plugin can **never** be overwritten (the installer refuses with
"Deactivate … first"). The installer **never activates** anything: a freshly
installed extension appears in its manager page as **inactive**.

**Delete (inactive only).** `deletePlugin($slug)` / `deleteTheme($slug)` remove an
installed extension's directory. The slug is validated strictly (lowercase
slug-like; no `/`, `\`, `..`), the path is resolved **internally** from the
configured root, and a `realpath` containment check guarantees the resolved
directory is strictly inside `plugins/` / `themes/` (never the root, never
outside) before anything is removed. **Active** extensions are refused
("Deactivate the plugin before deleting it." / "Activate another theme before
deleting this one."), and the **last remaining theme** can never be deleted.
UI: a danger **Delete** action on **inactive** plugin rows (confirmation modal,
§10.5.1) and a **Delete** button on **inactive** theme cards shown only when more
than one valid theme exists (§THEME). Like install, it never throws to the UI.

**Admin pages** (auto-discovered, no panel change):

- **`InstallPluginPage`** (`/admin/plugins/install`, group *Plugins*, sort 20) —
  now functional: a `FileUpload` (stored privately under `storage/app/private`)
  + an "Overwrite existing files" toggle + **Install Plugin**. On submit it hands
  the stored path to `extension_installer()->installPluginFromZip()`, deletes the
  upload, and shows a success/danger notification.
- **`InstallThemePage`** (`/admin/themes/install`, group *Appearance*, sort 40) —
  the theme equivalent.
- **Header actions**: "Install Plugin" on Installed Plugins (§10.5.1), "Install
  Theme" on Themes (§THEME).

`/cms-health` adds `extension_installer_ready` (service bound && zip ext present),
`plugin_installer_ready`, `theme_installer_ready` (installer ready && the
respective page class exists). Never 500s.

🔵 **Not in this phase:** remote-URL installer, CLI installer, marketplace,
license manager, plugin/theme **file editor**, auto-update, Composer installer.

---

## 10.6 Theme Options Framework (v0.9.9) — ✅ IMPLEMENTED

A **dynamic** theme options engine. The active theme declares an option schema;
the core renders an admin form from it, stores values in `cms_settings`, and
theme views read them via a helper. **No new table, no new panel.** This is a
framework — **not** a live customizer (still planned).

**Safe `functions.php` loading.** `ThemeManager::themeConfig(?string $slug)`
loads `themes/{slug}/functions.php` as a *config-returning* file: it must
`return` an array and is included in an isolated scope (no access to `$this`).
Missing file → `[]`; non-array return → `[]`; thrown exception → caught + logged
→ `[]`. Memoised per request. It never crashes admin or frontend.

**Schema.** `ThemeManager::themeOptionsSchema(?slug)` validates + normalises the
`options.sections` array; `hasThemeOptions(?slug)` reports whether any valid
section exists. Shape:

```php
return ['options' => ['sections' => [[
    'key' => 'identity', 'label' => 'Site Identity', 'description' => '…',
    'fields' => [[
        'key' => 'logo', 'label' => 'Logo', 'type' => 'image',
        'default' => null, 'helper' => '…',
    ]],
]]]];
```

- Required section keys: `key` (slug-like), `label`, `fields`.
- Required field keys: `key` (slug-like), `label`, `type`.
- Supported field types: `text`, `textarea`, `boolean`, `number`, `select`,
  `image`, `color`.
- Optional field keys: `default`, `helper`, `placeholder`, `options` (select),
  `min`/`max` (number).
- Validation: invalid sections/fields are skipped; unsupported field types are
  skipped with a logged warning; **duplicate field keys keep the first
  definition** (later duplicates ignored + logged) so form state keys stay
  unique.

**`ThemeOptionManager` (`cms.theme_option`)** — facade `ThemeOption`, helper
`theme_option()`. API: `get($key, $default = null, ?$theme = null)`,
`set($key, $value, ?$theme = null)`, `all(?$theme = null)`,
`schema(?$theme = null)`, `hasOptions(?$theme = null)`. The `$theme` argument
defaults to the **effective active theme**.

**Storage.** Values live in `cms_settings` under the full key
`theme_options.{theme_slug}.{option_key}`. Because `SettingsManager` splits a key
on its **first** dot only, this is stored as group `theme_options`, key
`{slug}.{option_key}` — unique per `(group, key)`. `get()` falls back to the
schema `default` (then the caller default) when no value is stored; values are
autoloaded (so the frontend `theme_option()` reads are cached).

**Admin:** `ThemeOptionsPage` (`/admin/theme-options`, group *Appearance*, sorted
after Themes). It renders a dynamic Filament form — schema sections → Filament
`Section`s; field types → `TextInput` / `Textarea` / `Toggle` / numeric
`TextInput` / `Select` / `MediaPicker` (image) / `ColorPicker` (color). On save
it persists each field via `ThemeOptionManager::set()` and clears the settings
cache. If the active theme declares no options it shows a friendly empty state;
with no active theme it shows a warning. **It never 500s** (broken/empty schema →
empty state). Availability is driven by the **schema**, not the manifest flag.

**Default theme options** (`themes/default/functions.php`): *Site Identity*
(`logo` image, `show_tagline` boolean=true), *Layout* (`container_width`
select), *Colors* (`primary_color` color=`#2563eb`), *Footer* (`footer_text`
textarea). `theme.json` declares `supports.theme_options: true`. The default
theme views use them: header logo (with site-name fallback), tagline gated on
`show_tagline`, footer `footer_text`, and a hex-validated
`:root { --tncms-primary }` wired to `--color-accent`.

**Security.** `functions.php` is config-only (no side effects executed). The
`image` field stores a URL string via the existing `MediaPicker`;
`primary_color` is hex-validated (`/^#[0-9a-fA-F]{3,8}$/`) before being emitted
into the layout `<style>` (invalid → default), preventing CSS/markup injection.

🔵 **Not in this phase:** live customizer, theme installer/ZIP, theme editor,
widgets, repeater / nested option fields. `requires.cms` is still not enforced.

See [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md) for the author guide.

---

## 11. Frontend rendering — ✅ IMPLEMENTED

**`FrontendController`** (`TheNguyen\CMS\Http\Controllers`) renders the
public site using the active theme's `theme::` views. Default locale `vi`.
Only **published** content renders; missing records `abort(404)`; a missing
theme view degrades to a plain fallback (never a 500).

**No-theme state (0.9.7):** every public action first calls a `noThemeResponse()`
guard. When `ThemeManager::active()` resolves no effective theme (no valid theme
folder at all), it returns a friendly **503** rendered from
`resources/views/errors/no-theme.blade.php` ("No active theme found. Please
activate a theme in admin.") — never a raw exception, never a silent mis-render.
A missing *view* within a present theme is still handled by the plain
`fallback()` (degraded HTML), distinct from the no-theme 503.

| Method | Behaviour |
| --- | --- |
| `home()` | obeys `reading.homepage_display`: `static_page` → `reading.homepage_page_id` page; `latest_posts` → `theme::pages.home` else `theme::archives.index` (latest `reading.posts_per_page` posts); otherwise legacy: published page `trang-chu` → `theme::pages.home` → fallback |
| `page($slug)` | published page → `theme::pages.page`, else 404 (legacy; the `/{slug}` route now uses `resolveSlug`) |
| `post($slug)` | published post → `theme::posts.post`, else 404 (only when `post_base` is set) |
| `category($slug)` | term + its published posts → `theme::archives.index`, else 404 (only when `category_base` is set) |
| `tag($slug)` | term + its published posts → `theme::archives.index`, else 404 (only when `tag_base` is set) |
| `resolveSlug()` | generic `/{slug}` resolver: `SlugManager::findPublic()` → dispatch to page / post / term archive; falls back to a published page by slug; else 404. Handles base-less posts/categories/tags. |

Default theme views (`themes/default/views`): `layouts/master`,
`partials/header`, `partials/footer`, `pages/page`, `posts/post`,
`archives/index`. They must reference siblings through the namespace
(`@extends('theme::layouts.master')`, `@include('theme::partials.header')`).

---

## 11.5 SEO Core (Phase 7.5) — ✅ IMPLEMENTED

A **lightweight** SEO foundation. No SEO plugin, no analyzer, no AI. It
resolves per-page meta from the existing content/taxonomy fields and
serves `robots.txt` + `sitemap.xml`.

**`SeoManager` (`cms.seo`)** — holds the SEO context for the current
request and resolves the meta values. The `FrontendController` sets the
context before rendering:

- `forHome()` — site-level SEO (used for `/`, even when the `trang-chu`
  page template renders it).
- `forContent(Content, locale)` — page/post SEO (og:type `article` for
  posts, `website` for pages).
- `forArchive(Term, type, locale)` — category/tag archive SEO.

Resolver methods (read by the theme SEO partial): `title()`,
`description()`, `keywords()`, `canonical()`, `robots()`, `ogTitle()`,
`ogDescription()`, `ogType()`, `ogImage()`, `twitterCard()`,
`twitterTitle()`, `twitterDescription()`, `twitterImage()`, and
`current()` (all of the above as an array). Plus `robotsTxt()`,
`sitemap()` (array of entries) and `sitemapXml()` for the infra routes.

**Resolution rules**

| Field | Order of preference |
| --- | --- |
| Title (page/post) | `meta_title` → content title → default title |
| Title (home) | default title |
| Title (default) | `seo.default_meta_title` → `general.site_name` → `TheNguyen CMS` |
| Title (archive) | `Category: {name}` / `Tag: {name}` |
| Description (page/post) | `meta_description` → `excerpt` → first 160 chars of content → default description |
| Description (home) | default description |
| Description (default) | `seo.default_meta_description` → `general.site_description` → `TheNguyen CMS website` |
| Description (archive) | taxonomy term `description` → default description |
| Keywords | `meta_keywords` only (else empty) |
| Robots | `seo.noindex_site` → `noindex,nofollow`; else `seo.robots_default` → legacy `seo.robots` → `index,follow` |
| OG/Twitter image | content `featured_image` → `seo.default_og_image` (absolutised) → none |

`seo()->titleSeparator()` (`seo.title_separator`, default `|`) is exposed for
themes to build a `{title} {sep} {site}` document title (no view uses it yet).
`robots.txt` returns `Disallow: /` when `seo.noindex_site` is on.

Empty-setting fallbacks: title `TheNguyen CMS`, description
`TheNguyen CMS website`, robots `index,follow`.

**Theme rendering flow**

`theme::layouts.master` `<head>` includes `theme::partials.seo`, which
calls `seo()` and emits `<title>`, `<meta name="description|keywords|
robots">`, `<link rel="canonical">`, Open Graph (`og:title`,
`og:description`, `og:type`, `og:url`, `og:image`) and Twitter
(`twitter:card|title|description|image`) tags. The master layout no
longer emits its own `<title>` — the partial owns it.

**Infra endpoints** (see §12): `GET /robots.txt` (text) and
`GET /sitemap.xml` (XML urlset: homepage, published pages/posts,
categories, tags) via `SeoController`, delegating to `SeoManager`. Both
register in `routes/web.php` **before** the frontend catch-all so
`/{slug}` never swallows them. The stock Laravel `public/robots.txt` was
removed so the dynamic route is authoritative.

> 🔵 **Not in this phase:** advanced SEO plugin/analyzer, AI SEO, sitemap
> index/pagination, per-record robots overrides, structured data/JSON-LD.

> **Updated in 0.9.0 (§6.5):** the SEO partial now also emits `hreflang`
> alternates (incl. `x-default`) for active languages with a valid URL, the
> canonical uses the current localized URL, and `sitemap.xml` emits localized
> URLs for every published translation.

---

## 11.6 Rich Editor + Media Insert (Phase 8) — ✅ IMPLEMENTED

The page/post **content body** is now edited with a **TinyMCE** rich
editor and stored as **sanitized HTML** (previously plain text rendered
with `nl2br(e())`).

**Editor field — `App\Filament\Admin\Components\RichEditor`** — a Filament
`Field` (`RichEditor::make('content')`) backed by
`resources/views/filament/admin/components/rich-editor.blade.php`. TinyMCE
(v8, community/GPL) is **self-hosted** under `public/vendor/tinymce` and
loaded from `/vendor/tinymce/tinymce.min.js` — **no Tiny Cloud, no API
key**. It initialises with `base_url: '/vendor/tinymce'`, `suffix: '.min'`
and `license_key: 'gpl'` (so skins/plugins/themes/models/icons resolve
locally), inside a `wire:ignore` wrapper; content syncs to Livewire via
`$wire.set(statePath, html, false)` (deferred) and is read back with
`$wire.get`. Toolbar: headings, bold/italic/underline/strike, link,
lists, table, image (URL), code view, plus three custom buttons —
**Insert Media** (opens the image media modal, see §11.7), **Insert media
by URL** (prompts for a URL → inserts `<img src="…" alt="" loading="lazy">`)
and **Media** (opens `/admin/media` in a new tab). Skin follows the admin
light/dark class. Used by `PageResource` and `PostResource` in place of the
content `Textarea`. ~500px tall. The `tinymce` npm package is a dev
dependency; its runtime assets are copied into `public/vendor/tinymce`.

**Sanitizer — `HtmlSanitizer` (`cms.html`)** — `sanitize(?string): string`.
DOMDocument-based, no new Composer dependency. Keeps an allow-list of tags
(`p, br, strong/b, em/i, u, s, h1–h6, ul/ol/li, a, blockquote, pre/code,
table/thead/tbody/tr/th/td, img, figure/figcaption, hr, span, div`) and
attributes (global `class`; `a`: href/title/target/rel; `img`:
src/alt/title/width/height/loading; `th/td`: colspan/rowspan). Dangerous
elements (`script`, `style`, `iframe`, …) are dropped with their subtree;
unknown wrappers are unwrapped (text kept); `on*`/`style` and other
attributes are stripped. URLs are scheme-checked — `javascript:`,
`vbscript:` and non-image `data:` are removed (including control-char
obfuscation); relative/anchor/`http(s)`/`mailto`/`tel` are allowed.
External `target="_blank"` links get `rel="noopener noreferrer"`.

**Where sanitization happens** — `ContentManager` sanitizes the content
body on **every write** (create/update), so the stored body is always
safe. Only the body is sanitized (title/excerpt/meta are plain text).

**Frontend rendering** — `theme::pages.page` and `theme::posts.post`
render the body with `{!! cms_html($body) !!}`. `cms_html()` sanitizes
HTML and, for **legacy tag-less plain text**, escapes and line-breaks it
(`nl2br(e())`) so old records still display without migration.

> 🔵 **Not in this phase:** media modal/grid picker, image crop/editor,
> drag-drop upload in the editor, AI writer. (A simple **image media
> modal** was added in `0.8.1` — see §11.7.)

---

## 11.7 Media Modal (v0.8.1) — ✅ IMPLEMENTED

An **image-only** picker inside the Rich Editor — no documents, no
galleries, no multi-select, no in-editor upload.

**Where it lives** — entirely inside the existing `RichEditor` field
(`resources/views/filament/admin/components/rich-editor.blade.php` +
`RichEditor::getMediaItems()`). No new Filament page/panel, no new table,
no new route, no new Livewire component.

**Data** — `RichEditor::getMediaItems()` queries `cms_media`
(`mime_type LIKE 'image/%'`, newest first, **max 50**) returning
`id, url, name (original_filename), filename, alt, title, width, height`.
Table-guarded (`Schema::hasTable`) so it never errors before migration.
The list is a server-rendered snapshot embedded via `@js(...)` into the
field's Alpine component; **search is client-side** over
`name/filename/alt/title`.

**Flow** — the TinyMCE **Insert Media** toolbar button calls the field's
Alpine `openModal()`. The modal (teleported to `<body>`, above the editor)
shows a thumbnail grid (filename, dimensions, alt) with a search box, a
**Cancel** and an **Insert** button, and an empty state linking to
`/admin/media/upload`. Selecting an image and pressing Insert (or
double-clicking) inserts into **that editor instance** (`self.editor`):

```html
<img src="{url}" alt="{alt|name}" title="{title}" width="{w}" height="{h}" loading="lazy">
```

`width`/`height` are emitted only when known; attribute values are
escaped. After insert it closes the modal, refocuses the editor, and
syncs Livewire (`$wire.set(statePath, html, false)`).

**Multiple editors** — modal state (`modalOpen`, `search`, `selectedId`,
`media`) is **per Alpine component instance**, and each TinyMCE instance
targets its own textarea, so insert always hits the correct editor.

**Security** — the modal only inserts `<img>` with the stored `cms_media`
URL; the result still passes through `ContentManager` → `HtmlSanitizer` on
save (img `src/alt/title/width/height/loading` are allow-listed; anything
unsafe is stripped). No bypass.

---

## 11.8 Editor Polish (v0.8.2) — ✅ IMPLEMENTED

A small authoring-experience patch on top of the Rich Editor + Media Modal.
**No new table, route, page, panel, or migration.** All changes live in the
existing `RichEditor` view, the default theme CSS, and `/cms-health`.

**Media insert options** — the Insert Media modal's details panel now
exposes **Alt text**, **Title**, an **Add caption** checkbox, and a
**Caption** input (revealed when checked). Selecting an image seeds Alt
from `media.alt` (else filename) and Title from `media.title`. Insert
output:

- **Add caption unchecked** → plain
  `<img src alt [title] [width] [height] loading="lazy">` (unchanged).
- **Add caption checked** →
  `<figure class="cms-image"><img …><figcaption>…</figcaption></figure>`.

`title` is emitted only when non-empty; `width`/`height` only when known;
all attribute values and the caption text are escaped client-side. After
insert the modal closes, the editor refocuses, and Livewire is synced.

**TinyMCE config** — paste cleanup (`paste_as_text:false`,
`paste_data_images:false`, `paste_merge_formats:true`, `smart_paste:true`,
and a `paste_postprocess` that strips inline `style` attributes — the
legacy webkit paste options were removed in TinyMCE 6+); link defaults
(`link_default_target:'_self'`, `link_assume_external_targets:'https'`,
`rel_list` = None / nofollow / sponsored / noopener noreferrer); and a
skin-aware `content_style` that approximates the frontend (font size,
h2/h3 spacing, `img` max-width, `figure.cms-image`, table borders,
blockquote, pre/code).

**Theme CSS** — `themes/default/assets/css/app.css` gains `.cms-image` +
`figcaption` styles and `.entry-content` styles for editor HTML
(`img`/`table`/`blockquote`/`pre`/`code`), republished to
`public/themes/default`. No build step.

**Sanitizer** — **unchanged.** `HtmlSanitizer` already allow-lists
`figure`, `figcaption`, and the global `class` attribute, and still drops
unsafe `img src`, `on*` handlers, `style`, `<script>`, `<iframe>`. The
`<figure class="cms-image">…</figure>` insert round-trips safely; a
malicious `javascript:` image with `onerror` is stripped on save.

`/cms-health` reports `editor_polish_ready` = `rich_editor_ready &&
html_sanitizer_ready` (never 500s).

> 🔵 **Not in this patch:** gallery, multi-select, in-editor upload, image
> crop/editor, S3/CDN, AI writer, SEO analyzer.

---

## 12. Routes — ✅ IMPLEMENTED

**Frontend** (`routes/frontend.php`, inside the `web` middleware group;
loaded after `web.php` so the catch-all is registered last):

| Method | URI | Action | Name |
| --- | --- | --- | --- |
| GET | `/` | `FrontendController@home` | `cms.home` |
| GET | `/{post_base}/{slug}` | `@post` (only if base set) | `cms.post` |
| GET | `/{category_base}/{slug}` | `@category` (only if base set) | `cms.category` |
| GET | `/{tag_base}/{slug}` | `@tag` (only if base set) | `cms.tag` |
| GET | `/{locale}` | `@home` (localized) | `cms.home.localized` |
| GET | `/{locale}/{post_base}/{slug}` | `@post` (localized, only if base set) | `cms.post.localized` |
| GET | `/{locale}/{category_base}/{slug}` | `@category` (localized, only if base set) | `cms.category.localized` |
| GET | `/{locale}/{tag_base}/{slug}` | `@tag` (localized, only if base set) | `cms.tag.localized` |
| GET | `/{locale}/{slug}` | `@resolveSlug` (localized) | `cms.resolve.localized` |
| GET | `/{slug}` | `@resolveSlug` (catch-all, **last**) | `cms.resolve` |

> **Permalink bases (0.9.6):** the post / category / tag base segments come from
> `PermalinkManager` (`cms.permalink`) reading `permalink.post_base` /
> `category_base` / `tag_base` at route-registration time (guarded; defaults
> `blog`/`category`/`tag` before the table exists). A base may be **empty**:
> the dedicated `/{base}/{slug}` route is then **not registered** and the record
> resolves through the generic `/{slug}` → `@resolveSlug` route (via
> `cms_slugs`). `content_url()` / `term_url()` / `language_switcher()`, the SEO
> `hreflang` alternates, the sitemap, and the `cms_slugs` prefixes written by
> `ContentManager` / `TaxonomyManager` all use the same bases (a null prefix
> when base-less). **Changing a base needs `php artisan route:clear`** (and
> `route:cache` must not be built against a stale base); saving the Permalinks
> tab also rebuilds `cms_slugs` so existing URLs update.

The locale-prefixed group constrains `{locale}` to **active language codes
only** (e.g. `vi|en`, via `LanguageManager::routeLocalePattern()`, evaluated
at route-registration time and fully guarded). The default language still
resolves at the unprefixed paths. The catch-all `/{slug}` has a
negative-lookahead `where` constraint excluding reserved single-segment
prefixes: `admin`, `cms-health`, `livewire`, `filament`, `storage`,
`uploads`, `themes`, `vendor`, `up`, `robots.txt`, `sitemap.xml`, and remains
**last**. (`SlugManager::uniquePublicSlug()` likewise refuses these as a public
slug, so a saved page/post/term can never shadow them.)

> **Param binding note:** because Laravel binds route parameters to action
> arguments **positionally** (by URI order), and these actions serve both the
> prefixed and unprefixed routes, `FrontendController` reads `slug`/`locale`
> from the matched route **by name** rather than via method arguments. A
> missing translation 404s (no implicit fallback to the default language).

The app's default welcome `/` route was removed so the theme owns the
homepage.

**Health / admin:**

| Method | URI | Source |
| --- | --- | --- |
| GET | `/cms-health` | `routes/web.php` (JSON; never 500s; reports versions, table readiness, counts, `active_theme` (raw stored slug, may be null), `active_theme_effective`, `active_theme_required` (always true), `theme_system_ready`, `themes_count`, `invalid_themes_count`, `extension_framework_ready`, `plugins_count`, `active_plugins_count`, `invalid_plugins_count`, `plugin_manager_ui_ready`, `theme_options_ready`, `active_theme_has_options`, `theme_options_count`, `active_theme_views_ready`, `frontend_ready`, `seo_ready`, `sitemap_ready`, `robots_ready`, `rich_editor_ready`, `html_sanitizer_ready`, `media_modal_ready`, `editor_polish_ready`, `media_metadata_ready`, `featured_image_modal_ready`, `media_ux_ready`, `settings_polish_ready`, `settings_sections` (general/reading/writing/media/seo/permalinks), `languages_ready`, `languages_count`, `active_languages_count`, `default_language`, `current_language`, `roles_permissions_ready`, `roles_count`, `permissions_count`, `super_admins_count`, `maintenance_ready`, `maintenance_enabled`, `maintenance_mode`, `extension_translation_ready`, `core_translation_files_count`, `theme_translation_files_count`, `plugin_translation_files_count`) |
| GET | `/robots.txt` | `routes/web.php` → `SeoController@robots` (`cms.robots`; text/plain) |
| GET | `/sitemap.xml` | `routes/web.php` → `SeoController@sitemap` (`cms.sitemap`; application/xml) |
| GET | `/up` | Laravel default health (`bootstrap/app.php`) |
| GET | `/admin/*` | Filament panel `admin` |

`/robots.txt` and `/sitemap.xml` are registered in `routes/web.php`, which
loads **before** `routes/frontend.php`, so the catch-all `/{slug}` never
captures them.

---

## 13. Admin (Filament) — ✅ IMPLEMENTED (app-level)

Single panel: id `admin`, path `/admin`, `->default()->login()`, discovers
resources/pages/widgets under `app/Filament/Admin/*`
(`App\Providers\Filament\AdminPanelProvider`). **Do not add another panel.**

App-level UI (lives in `app/`, glue to the CMS core services):

- **Resources:** `PageResource`, `PostResource`, `CategoryResource`,
  `TagResource`, `TaxonomyResource`, `MediaResource`, `MenuResource`
  (+ `MenuItemsRelationManager`), `LanguageResource`.
- **Pages:** `SettingsPage`, `MediaUpload`, `ThemesPage`, `ThemeOptionsPage`,
  `InstalledPluginsPage`, `InstallPluginPage` (placeholder), `PluginEditorPage`
  (placeholder).
- **Widgets:** `CmsInfoWidget`. **Components:** `MediaPicker` (featured-image
  modal picker), `MediaLibrarySelect` (its library-grid tab), `RichEditor`
  (TinyMCE content field, used by Page/Post).
- **Navigation groups:** Content, Media, Appearance, Plugins, CMS (order set via
  the panel's `navigationGroups()`).

Resources are thin: they delegate persistence to the core managers
(`cms.content`, `cms.taxonomy`, `cms.media`, `cms.menu`, `cms.settings`,
`cms.theme`).

---

## 13.5 Users / Roles / Permissions Core (v1.0.0-beta.3) — ✅ IMPLEMENTED

A practical RBAC layer over admin operations. **No Laravel `users` table change**
— RBAC adds four tables and a trait.

**Tables (core package migrations):**

- `cms_roles` — `id, name, slug (unique), description, is_system, timestamps`.
- `cms_permissions` — `id, name, slug (unique), group, description, timestamps`.
- `cms_role_permissions` — pivot `role_id × permission_id` (unique).
- `cms_role_user` — pivot `role_id × user_id` (unique). No FK on `user_id` (the
  users table belongs to the host app).

**Models & trait:** `Models\Role`, `Models\Permission`, and `Traits\HasCmsRoles`
(applied to `App\Models\User`): `roles()`, `hasRole()`, `hasAnyRole()`,
`hasPermission()`, `isSuperAdmin()`.

**Service / facade / helper:** `PermissionManager` (`cms.permission`), the
`Permission` facade, and the `cms_can(string $permission, ?Authenticatable $user = null)`
helper. The manager owns:

- the in-code **permission registry** (constructor-registered core set; plugins
  may `Permission::register(...)` at runtime), and `syncDefaults()` which mirrors
  it into `cms_permissions`;
- **authorization** — `userCan()` / `isSuperAdmin()` / `roleHas()`, memoised per
  user per request, every DB touch guarded so a missing table never 500s.

**Authorization rules (in one place):**

1. **Fail-open until initialised** — while no roles exist, every check passes, so
   the existing login/admin keeps working pre-seed and nobody is locked out.
2. **Super-admin bypass** — the `super-admin` role, or an email in
   `config('cms.super_admin_emails')` (env `CMS_SUPER_ADMIN_EMAILS`), grants all.
3. Otherwise a permission is granted only if one of the user's roles has it.

**Panel access:** `App\Models\User implements FilamentUser`; `canAccessPanel()` =
super-admin OR `admin.access`. This also makes the panel reachable in non-local
environments (Filament denies access by default there without the contract).

**Admin UI:** `UserResource` (`/admin/users`) and `RoleResource` (`/admin/roles`)
under the **Users** navigation group. Pages/resources are guarded via Filament's
`canViewAny/canCreate/canEdit/canDelete` and page `canAccess()`; sensitive page
actions (theme activate/delete/install, plugin activate/delete/install) are hidden
when unauthorized and re-checked server-side.

**Safety rails:** no self-delete, the last super admin cannot be deleted or have
its role removed, system roles cannot be deleted, and the super-admin role always
keeps every permission.

**Seeder:** `Database\Seeders\CmsRolePermissionSeeder` — idempotent; seeds
`super-admin` (system), `admin` (system), `editor`, `author`, and assigns
`super-admin` to the first user on a fresh install only.

**Health:** `/cms-health` adds `roles_permissions_ready`, `roles_count`,
`permissions_count`, `super_admins_count`.

**Known limitation:** no post-ownership column yet, so the `author` role is not
scoped to its own posts (planned hardening).

---

## 13.6 Maintenance Mode Core (v1.0.0-beta.4) — ✅ IMPLEMENTED

Built-in maintenance mode — a basic feature that belongs in CMS core, so site
owners do not need a plugin. **No new table, no new panel.** All state lives in
`cms_settings` under the `maintenance.*` group.

**`MaintenanceManager` (`cms.maintenance`)** — facade `Maintenance`, helper
`maintenance()`. API: `isEnabled()`, `mode()` (`theme`|`page`), `statusCode()`
(`503`|`200`), `retryAfterMinutes()` (or `null`), `excludePaths()`, `allowedIps()`,
`settings()` (snapshot; never leaks anything response-private), `shouldBypass(Request,
?User)`, `isExcludedPath(string)` (delegates to the **pure static**
`pathIsExcluded($path, $excluded)`), `response(Request): Response`, and
`render(): string`. Every read goes through the table-guarded settings manager, so
it never 500s when settings are missing.

**Settings keys** (seeded by `CmsSettingsSeeder`, autoloaded, `is_public = false`):
`maintenance.enabled` (bool, false), `mode` (`theme`), `page_id` (int, 0), `title`,
`message`, `status_code` (503), `retry_after_minutes` (30), `allow_admin_bypass`
(true), `allow_logged_in_bypass` (false), `allowed_ips` (`[]`), `exclude_paths`
(`[admin, login, livewire, robots.txt, sitemap.xml, cms-health]`).

**Middleware — `Http\Middleware\CheckMaintenanceMode`.** Applied to the **frontend
route group only** (`routes/frontend.php`: `middleware(['web', CheckMaintenanceMode::
class])`). Because admin/Livewire/`cms-health`/`robots.txt`/`sitemap.xml` are
registered **outside** that group, they are never blocked. Flow: disabled → continue;
excluded path → continue; `shouldBypass()` → continue; else return the maintenance
response. The whole handler is wrapped in try/catch so the gate can never break the
site.

**Bypass rules.** (1) excluded paths (prefix match for bare segments like `admin`;
exact match for filename entries like `robots.txt`); (2) `allow_admin_bypass` +
`system.maintenance.bypass` (super admins included); (3) `allow_logged_in_bypass` +
any authenticated user; (4) an `allowed_ips` match.

**Rendering priority** (`render()`): (1) `mode = page` + a valid **published** page →
render it through `theme::pages.page` (the gate is **not** re-run, so no infinite
loop); (2) the active theme's `theme::maintenance`
(`themes/{slug}/views/maintenance.blade.php`); (3) the core fallback `cms.maintenance`
(`resources/views/cms/maintenance.blade.php`); (4) a last-resort inline HTML document.
View data: `$title`, `$message`, `$statusCode`, `$retryAfterMinutes`, `$siteName`,
`$homeUrl`.

**SEO / headers.** `response()` sets the configured status (503 by default), an
`X-Robots-Tag: noindex, nofollow` header, and — on 503 with a configured window — a
`Retry-After` header (minutes × 60). The shipped views also emit
`<meta name="robots" content="noindex,nofollow">`.

**Permissions.** `system.maintenance.manage` (gates the Settings → Maintenance tab,
additional to `settings.manage`) and `system.maintenance.bypass` (grants bypass).
The seeder grants both to `super-admin` and `admin`; `editor`/`author` get neither.
The Maintenance tab and its setting keys are only loaded/persisted when the user may
manage them, so a hidden field is never written back as null.

**Admin UI.** A **Maintenance** tab on the existing `SettingsPage` (no new
page/panel): enable toggle, display-mode select, maintenance-page select (mode=page),
title, message, status-code select, retry-after (status=503), the two bypass toggles,
and TagsInputs for allowed IPs and excluded paths.

**Health:** `/cms-health` adds `maintenance_ready` (service bound + manager/middleware
classes exist), `maintenance_enabled`, and `maintenance_mode`. Allowed IPs are never
exposed.

🔵 **Not in this phase:** scheduled/auto maintenance windows, per-path maintenance,
maintenance for the admin panel itself.

---

## 13.7 Extension Translation Framework (v1.0.0-beta.5) — ✅ IMPLEMENTED

A JSON translation framework for **interface** strings of the CMS core, the active
theme, and active plugins — **separate from content translations** (pages/posts/
categories/tags, which live in the `*_translations` tables). Laravel-style JSON,
**no `.po`/`.mo`, no new table, no new panel**.

**File locations** (keyed by source string):

- core: `lang/{locale}.json`
- theme: `themes/{slug}/lang/{locale}.json`
- plugin: `plugins/{slug}/lang/{locale}.json` (preferred) or
  `plugins/{slug}/resources/lang/{locale}.json` (compatibility)

**`ExtensionTranslationManager` (`cms.extension_translation`)** — facade
`ExtensionTranslation`, helper `extension_translation()`. API: `core($key,$replace,
$locale)`, `theme(...)`, `plugin($plugin,$key,$replace,$locale)`,
`registerActiveTranslationPaths()`, `syncTranslationFiles(?array $locales)`,
`fileCounts()`. Every file read is guarded — a missing folder/file or invalid JSON
never throws; the lookup falls through and finally returns the key.

**Helpers:** `core_trans()`, `theme_trans()`, `plugin_trans()`, and `tn_trans()`
(alias of `core_trans()`). The locale defaults to `current_locale()` (per-request,
from `LanguageManager`); pass an explicit locale to override. Replacement supports
Laravel-style `:key` / `:Key` / `:KEY` casing.

**Lookup order (fallback chains):**

- `core_trans`: `lang/{locale}` → `lang/{default}` → key.
- `theme_trans`: theme `{locale}` → theme `{default}` → core `{locale}` → core
  `{default}` → key.
- `plugin_trans`: plugin `lang/{locale}` → plugin `resources/lang/{locale}` → plugin
  `lang/{default}` → plugin `resources/lang/{default}` → core `{locale}` → core
  `{default}` → key.

**Boot integration.** `CmsServiceProvider::boot()` calls
`registerActiveTranslationPaths()` after routes/plugins boot: it `addJsonPath()`s the
active theme + active-plugin `lang/` dirs onto Laravel's translator (additive — core
`__()` keeps working). The scoped helpers use their own deterministic loader, so the
fallback chains hold regardless of translator path registration.

**Sync.** `syncTranslationFiles(?array $locales)` ensures a `{locale}.json` exists (as
empty `{}`, pretty-printed) for core, the active theme, and each **active** plugin —
across the given locales (or all active languages). It **never overwrites** an existing
file, creates missing parent folders, and returns `['created'=>[], 'skipped'=>[],
'errors'=>[]]` with **base-relative** paths (never absolute). Admin: **Sync Translation
Files** header action + a per-row **Sync files** action on `LanguageResource`
(`ListLanguages`), gated by `languages.manage`; `CreateLanguage::afterCreate()` syncs
the new locale (best-effort, never breaks the create).

**Permission.** `languages.manage` added to the registry (group *Settings*); seeded to
`super-admin` and `admin`.

**Shipped files.** Core `lang/en.json` + `lang/vi.json`; default theme
`themes/default/lang/{en,vi}.json`; `plugins/hello-world/lang/{en,vi}.json`. The
default theme archive uses `theme_trans('Read more')` / `theme_trans('No posts yet.')`;
the hello-world view uses `plugin_trans('hello-world', 'Hello World from TN CMS Plugin')`.

**Health:** `/cms-health` adds `extension_translation_ready`,
`core_translation_files_count`, `theme_translation_files_count`,
`plugin_translation_files_count` (file counts only — never absolute paths).

🔵 **Not in this phase:** auto/AI translation, a translation string editor UI,
`.po`/`.mo` import/export, marketplace language packs, inactive-plugin sync.

### 13.7.1 Admin Translation Completion (v1.0.0-beta.5.1) — ✅ IMPLEMENTED

Completes the **Admin UI** half of the framework: every TN CMS core-controlled admin
string now resolves through `tn_trans()` / `core_trans()`. Covered surfaces — Filament
resources (Pages, Posts, Categories, Tags, Taxonomies, Menus + menu-items relation,
Users, Roles, Media, Languages), admin Pages (Settings, Themes, Theme Options, Installed
Plugins, Install Plugin/Theme, Media Upload, Plugin Editor), shared components
(`MediaPicker`, `MediaLibrarySelect`, `RichEditor`), the CMS info widget, and all admin
Blade views. Wrapped element types: `label/placeholder/helperText`, `Section`/`Tab`
headings, `Select` option **labels** (keys untouched), table column labels, filters,
record/bulk/header actions, modal headings/descriptions, and notification titles/bodies.

**Filament built-ins (`SetCmsAdminLocale`).** TN CMS strings and Filament's own strings
(Create/Save/Delete/Search/pagination/"No records found", the latter via
`app()->getLocale()`) both follow the **admin UI locale**. The
`App\Http\Middleware\SetCmsAdminLocale` middleware — last in the admin panel middleware
stack — resolves it via `LocalePreferenceManager` and calls
`app('cms.language')->setCurrent(...)` each request, aligning the framework locale with
the CMS UI locale. Filament's **shipped** per-locale translations are used as-is; **no
vendor files are modified**.

**Three independent locales (beta.7.1.10 → beta.7.1.10.2).** The admin separates:

- **Admin UI locale** — `app()->getLocale()` / `current_locale()`. Signal `?lang=xx`,
  stored in `users.admin_locale` + session; topbar switcher. Drives all interface chrome.
- **Content editing locale** — `editing_locale()`. Signal `?locale=xx`, stored in
  `users.editing_locale` + `session(cms.editing_locale)`; the content language switcher.
  **Every** localized admin editor (posts, pages, terms, menus, widgets, localized
  settings, homepage layout) reads/writes the edited translation through this one source.
- **Frontend locale** — route `{locale}` → `?locale` → `users.frontend_locale` → session.

The three never bleed into each other; the UI and content switchers preserve each other's
query parameter. `LocalePreferenceManager` is the single resolver/persistence point.

**Validation (Phase: Laravel-native).** `lang/vi/validation.php` provides the Vietnamese
message set (the framework ships English in `Illuminate\Translation\lang/en`). No custom
validation logic — standard `:attribute` lines only.

**Dictionaries.** `lang/en.json` and `lang/vi.json` hold the full core admin set
(≈418 keys), alphabetically sorted, deduplicated, and **key-for-key identical** (every
English key has a non-empty Vietnamese value), so an admin label can never surface a raw
key. Theme/plugin strings stay in their own `lang/` folders.

**Manager additions.** `coreKeyCount(?locale)`, `adminTranslationReady()`,
`untranslatedCoreKeyCount()`, and `coreTranslationStats()` → `{keys_count,
missing_keys_count, untranslated_keys_count}` (all guarded, expose only counts).

**Health.** `/cms-health` adds `admin_translation_ready` (bool),
`filament_translation_ready` (bool), `core_translation_keys_count` (int — keys in the
default core dictionary), `core_translation_missing_keys_count` (int — default keys
absent from non-default active locales), and `core_translation_untranslated_keys_count`
(int — non-default active values blank or equal to the source key; English source locale
exempt). `core_translation_keys` is retained for backward compatibility and mirrors
`core_translation_keys_count`. No paths/secrets; never throws (falls back to `0` and stays
`200`/`ok` on missing or invalid JSON).

🔵 **Still not included:** translation editor UI, AI/auto translation, `.po`/`.mo`,
GitHub/URL installers, marketplace, audit logs, 2FA. No new database tables.

### 13.7.2 Health & Translation Cleanup (v1.0.0-beta.5.2) — ✅ IMPLEMENTED

A hardening pass over `/cms-health` ahead of the Web Installer Core. No new
tables/panels/features; no vendor files modified.

**Canonical metric names.** `/cms-health` previously emitted both plural and singular
aliases of the same value. Each metric now has **one canonical singular `*_count` name**:
`theme_count`, `invalid_theme_count`, `plugin_count`, `active_plugin_count`,
`invalid_plugin_count`, `language_count`, `active_language_count`, `role_count`,
`permission_count`. The removed plural aliases (`themes_count`, `invalid_themes_count`,
`plugins_count`, `invalid_plugins_count`, `active_plugins_count`, `languages_count`,
`active_languages_count`, `roles_count`, `permissions_count`) are **not** retained — TN CMS
is still beta, so clean output is preferred over legacy aliases. The `active_plugins` slug
array is unchanged.

**Paths off by default (opt-in only).** `base_path` and `public_path` are emitted **only
when `config('cms.health.show_paths') === true`** (env `CMS_HEALTH_SHOW_PATHS`, default
`false`). The flag is **not** tied to `APP_DEBUG` — even with `APP_DEBUG=true` the public
endpoint leaks no server paths unless an operator explicitly opts in for local debugging.
No storage/plugin/theme/installer-temp absolute paths are exposed in any mode.

**`health_output_clean` / `health_output_debug_paths_enabled` (bool).** `health_output_clean`
is `true` when the canonical metric set is de-duplicated, **no** absolute filesystem paths are
exposed, and the translation-health metrics resolved to valid integers;
`health_output_debug_paths_enabled` mirrors `config('cms.health.show_paths')`. Enabling path
output sets `health_output_debug_paths_enabled: true` and `health_output_clean: false`. Both
computed defensively — `/cms-health` stays `200`/`ok` and never throws.

**Translation metrics finalised.** The translation-health metrics are unchanged in meaning.
`core_translation_keys` is now a **deprecated alias** of `core_translation_keys_count`
(slated for removal). Missing JSON returns `0`, invalid JSON never 500s, locale fallback
still works, and no paths are exposed.

**Admin translation finalised.** The beta.5.1 audit's only residual hardcoded strings — the
last-super-admin notification in `EditUser::beforeSave()` — now resolve through `tn_trans()`
with matching EN/VI entries. **Vendor limitation:** Filament's own built-in strings rely on
Filament's shipped per-locale translations (aligned by `SetCmsAdminLocale`); TN CMS does not
translate vendor internals.

### 13.8 Web Installer Core (v1.0.0-beta.6) — ✅ IMPLEMENTED

A first-run web installer at `/install`, so a freshly deployed copy is set up from the
browser. Plain Blade UI (no Filament, no admin login).

**Flow.** `welcome → requirements → database/site → super-admin → finish`. The database step
validates input, tests the connection, and writes `.env`; the admin step runs migrations and
seeders, creates the super-admin, clears caches, and locks the installer.

**Service.** `InstallerManager` (`cms.installer`), the `Installer` facade, and the
`installer()` helper own all logic: requirement checks, `.env` writing, a guarded MySQL
connection test (`DB::purge` before each attempt so cached connections never mask bad creds),
`applyRuntimeDatabase()` (re-point the default connection within the request), migration +
seeder execution, super-admin creation, and the lock. The controller
(`TheNguyen\CMS\Http\Controllers\InstallController`) is transport-only.

**Routes & middleware.** `routes/installer.php` registers the seven routes inside
`[InstallerSession, 'web']` under the `install` prefix, loaded **before** the frontend
catch-all and **outside** the maintenance gate. `InstallerSession` forces a **file** session
(the app default driver is `database`, which has no table pre-install), so CSRF works.
`RedirectIfInstalled` guards every step except `/finish`.

**Install lock.** Installed when EITHER `storage/app/tncms-installed` OR `TN_CMS_INSTALLED=true`
is present; `markInstalled()` writes both. The marker file is the durable signal (survives a
cached config).

**Admin path.** `config('cms.admin_path')` (env `ADMIN_PATH`, default `admin`) is read by
`AdminPanelProvider`, so the installer's Admin-path choice is honoured (route cache clear
required to change later).

**Security.** Locked steps redirect and POSTs are CSRF-protected (a locked POST is rejected
before any work). The DB password is verified, written to `.env`, and held only in the
server-side session — never echoed to the UI, logged, or exposed in `/cms-health`; connection
errors are password-scrubbed. `APP_KEY` is generated only when missing. Migrations use
`--force`; no destructive commands.

**Health.** `/cms-health` adds `web_installer_ready`, `cms_installed`, `installer_locked`
(no installer paths or credentials).

🔵 **Not in this phase:** GitHub/URL extension installer, CLI installer, marketplace, auto
updater, license manager, file editor, AI features, new Filament panel. No vendor edits, no
new tables.

---

## 13.8 Widget Foundation (v1.0.0-beta.7) — ✅ IMPLEMENTED

A Laravel-native widget system. **Three new tables**, **no new panel**, **no
vendor edits**.

**Data model.** `cms_widget_areas` (named slots: `slug` unique, `name`,
`description`, `source` core/theme/plugin, `source_slug`, `is_active`,
`sort_order`); `cms_widgets` (instances: `area_id`, `widget_type`, fallback
`title`, global `settings` JSON, `sort_order`, `is_active`);
`cms_widget_translations` (`widget_id`+`locale` unique, per-locale `title` +
localized `settings` JSON). **Rendered HTML is never stored.**

**`WidgetManager` (`cms.widget`, facade `Widget`, helpers `widget()` /
`widget_area($slug, $locale)`).** Holds the in-memory registry of widget *types*
(classes) and *areas*, mirrors areas into `cms_widget_areas` (`syncAreas()`), and
renders an area's active instances (`renderArea()` → ordered `renderWidget()`).
Safety contract: an invalid class is skipped + `report()`ed; a duplicate type is
last-wins + reported; any per-widget render failure is caught + reported, so the
frontend never 500s (debug-only HTML comment when `APP_DEBUG`, else nothing). An
unknown/removed type renders as a failure (never fatal).

**Widget classes** extend `TheNguyen\CMS\Widgets\Widget` — `type()`, `name()`,
`description()`, `icon()`, `schema()` (fields `text|textarea|number|toggle|select|media|html`,
optional `localized`), `render(array $settings, ?string $locale): string|View`.
Built-ins: `TextWidget`, `HtmlWidget` (sanitized via `HtmlSanitizer`),
`RecentPostsWidget` (limit clamped 1–20, `content_url`), `CategoriesWidget`
(`term_url`, optional count).

**Registration.** Core registers the built-ins + core areas (`sidebar-blog`,
`sidebar-page`, `footer-1/2/3`, `before-footer`, `after-post`) in-memory every
boot (DB-free). Active-theme `functions.php` may return `widgets` /
`widget_areas`; plugins call `widget()->register()` from a provider. Theme
registration + `syncAreas()` run only when installed.

**Settings resolution (per locale):** schema defaults → parent global settings →
requested-locale translation → default-locale translation. Localized fields live
in `cms_widget_translations`; globals in `cms_widgets.settings`; `title` is
localized with parent fallback. A present translation is never bypassed.

**Admin.** `WidgetsPage` (`/admin/widgets`, group *Appearance*, permission
`widgets.manage` — super-admin/admin only). Add/edit/enable-disable/move
up-down/delete; localized editor with a locale switcher. **No drag-and-drop**
(deferred).

**Cache.** Widget/translation/area writes bump the public content cache version
(joins the existing invalidation hooks). **No widget fragment cache yet.**

**Health.** `/cms-health` adds `widgets_ready`, `widget_count`,
`widget_area_count`, `active_widget_count` (counts only).

🔵 **Deliberately deferred:** widget HTML fragment cache, drag-and-drop
reordering, GitHub/URL/marketplace installers.

### Widget Polish (v1.0.0-beta.7.1) — ✅ IMPLEMENTED

UX-first refactor on top of §13.8; schema/manager/registry **unchanged**.

- **Admin moved into cms-core.** `TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage`
  (view `cms::filament.pages.widgets-page`) registered on the panel by
  `PluginResourceRegistrar`; the host needs no page file. A `cms` view namespace
  is registered for package-owned admin views.
- **WordPress-like board.** Two columns — searchable, group-bucketed *Available
  Widgets* (left) ↔ collapsible *Widget Areas* (right). Inline expandable editor
  (no modals) with a per-widget locale switcher.
- **Drag & drop.** Alpine + native HTML5 DnD persists order via Livewire
  `reorderWidgets()` / `moveWidget()` (within and across areas). No JS build/lib.
- **Field API.** `TheNguyen\CMS\Widgets\Fields\WidgetField` + `TextField`,
  `TextareaField`, `ToggleField`, `SelectField`, `NumberField`, `MediaField`,
  `RichEditorField`, `RepeaterField`. Fields normalise to the beta.7 array shape,
  so array- and field-authored `schema()` are interchangeable (backward
  compatible). Reusable later for Theme Options / Localized Settings.
- **Presets.** `registerPreset()/presets()/findPreset()/applyPreset()` — append
  bundles into an area, never overwriting. Core ships `blog-sidebar`,
  `simple-footer`.
- **Export / Import.** `export()/import($data,'merge')` — portable JSON of
  areas + widgets + settings + translations; import is append-only, slug-matched,
  skips unregistered types, preserves translations.

**v1.0.0-beta.7.1.5 (categories strict per-locale):** `CategoriesWidget::render()`
shows only category terms translated (name + slug) in the current locale, using
that locale's name (`Term::localeName()`, strict) and URL — no cross-locale
fallback. This reverses beta.7.1.4, which mixed languages by falling back to the
default locale.
**v1.0.0-beta.7.1.3 (inline editor fix):** the inline widget editor no longer
carries a bare `x-collapse` (which hid it at height:0), the row being edited
renders `draggable="false"` so its inputs are focusable, and switching locale
preserves unsaved global settings (`setEditLocale()` reloads localized fields
only). The editor is schema-driven via `WidgetManager::schemaFor()`.
**v1.0.0-beta.7.1.2 (admin UI rebuild):** the `/admin/widgets` layout is driven
by **scoped CSS** (`tn-widget-*` prefix) rather than arbitrary Tailwind
utilities the Filament panel bundle does not compile — fixing the previously
raw, unstyled page. **v1.0.0-beta.7.1.1 (UI polish):** grouped searchable widget
**cards**; **collapsible areas** persisted per-slug in `localStorage`
(`tncms.widgets.area.{slug}`); **duplicate** (`WidgetManager::duplicate()`);
export by **clipboard copy** or download; import by **paste or `.json` upload**
in **append**/**replace** modes (`import($data, 'replace')`); presets as a card
grid; `aria-expanded`/`aria-controls`/`aria-label` accessibility. UI/UX only —
schema, manager API, and export/import contract unchanged.

🔵 Still deferred: fragment cache, marketplace, GitHub/URL installer, inline
repeater editing, full keyboard drag-reordering.

---

### 13.9 Global Script Manager (v1.0.0-beta.7.1.13)

A Core-level, security-first registry + renderer for frontend head/footer
scripts, verification meta, JSON-LD, and trusted iframe embeds. Bound as
`cms.scripts` (`ScriptManager`) with the `Script` facade; state is held in
immutable `Support\Scripts\ScriptAsset` value objects and rendered through
`Support\Scripts\ScriptRenderResult`.

**Registration → validation → render.** Registration validates up-front and
either stores a `ScriptAsset` or records a rejection (never both). Rendering is
grouped by document location and type order:

- **Head**: meta → verification → external head scripts → inline head scripts →
  JSON-LD → head embeds.
- **Footer**: external footer scripts → inline footer scripts → footer embeds.

Within a group, assets sort by `priority` ascending (default `10`), ties broken
by registration order. Duplicate `{type}:{key}` replaces the earlier asset.

**Security seams** (all Core, no plugin can weaken them):

- Inline content is pattern-scanned (`eval(`, `new Function`, `document.write`,
  `javascript:`/`vbscript:`, `data:text/html`, `blob:`, `file:`, `on*=` handlers).
- External `src` must be same-origin relative or on the trusted host allowlist;
  protocol-relative URLs are rejected.
- JSON-LD is validated and re-encoded with `JSON_HEX_TAG` to prevent
  `</script>` breakout — no double-encoding.
- Embeds are iframe-only, host-allowlisted, and attribute-sanitized via
  `DOMDocument`.

**Extension**: existing `HookManager` only — actions
`cms.scripts.rendering_head` / `cms.scripts.rendering_footer` and filters
`cms.scripts.head_html` / `cms.scripts.footer_html`. Rendering never throws on
the frontend; `/cms-health` reports counts only. The foundation itself has no
asset bundler/dependency graph; the Admin UI is layered on in §13.9.1.

#### 13.9.1 Global Script Settings UI (v1.0.0-beta.7.1.13.2)

Admin UI over §13.9 — no change to the ScriptManager. **Settings → Scripts**
(`ScriptSettingsPage`, a standalone Filament page gated by `settings.manage`)
stores configuration in `cms_settings` under the `scripts.*` keys
(`enabled`, `verifications`, `json_ld`, `head_inline`, `footer_inline`,
`head_external`, `footer_external`, `embeds`; JSON arrays for repeatable groups,
no new tables). Nothing is executable — no PHP, no callbacks.

`ScriptSettingsRegistrar` (`cms.script_settings`) is the render bridge: it reads
the `scripts.*` settings and registers them into the ScriptManager on the
existing `cms.scripts.rendering_head` / `rendering_footer` action hooks, so every
value flows through the ScriptManager's own validation (unsafe inline, untrusted
URLs, invalid JSON, dangerous iframes are rejected there and never render).
Registration is idempotent (the ScriptManager de-duplicates by key), so applying
on both head and footer renders produces no duplicates.

`scripts.enabled` gates **only** settings-managed scripts; scripts a plugin
registers directly through the `Script` API always render. The registrar also
exposes `diagnostics()` (valid/invalid counts + safe skip reasons for the admin
page) and `healthSnapshot()` (count-only `script_settings_*` fields — never any
script value/URL/key). Save-time cleaning strips markup from verification values;
all other validation is deferred to render (invalid entries are skipped, and the
page surfaces the invalid count).

### 13.10 Asset Registry (v1.0.0-beta.7.1.13.1)

A Core-level registry + renderer that lets themes and plugins declare
frontend/admin CSS/JS **by handle**, enqueue them when needed, resolve
dependencies, and render them in order — a cleaner equivalent of the WordPress
enqueue API. Bound as `cms.assets` (`AssetRegistry`) with the `Asset` facade;
state is held in immutable `Support\Assets\Asset` value objects and rendered
through `Support\Assets\AssetRenderResult`. It **complements** the Global Script
Manager (§13.9): the Script Manager owns meta/JSON-LD/embeds, the Asset Registry
owns file-based CSS/JS enqueueing with dependency resolution.

**Register → enqueue → render.** `registerStyle/Script/Module` define an asset;
`enqueueStyle/Script/Module` activate it; `render*` emit it. Only enqueued assets
and their (transitive) dependencies render. Handles form a single namespace; the
last registration for a handle wins (keeping its sequence).

**Dependency graph.** A depth-first post-order walk orders dependencies before
dependents. A missing dependency is recorded as a warning and the dependent still
renders; a circular dependency is broken via a `visiting` marker (never an
infinite loop) and reported. Diagnostics are exposed via `lastRenderWarnings()`.

**Scopes & positions.** `frontend` / `admin` / `both` (default `frontend`), and
`head` / `footer` (styles default head, scripts/modules default footer).
Rendering is position-bucketed (`wp_head`/`wp_footer` model): the head bucket
emits styles then head-positioned scripts; the footer bucket emits footer scripts
then footer styles. Inline assets stand alone in a bucket or attach `before`/
`after` a target handle, rendering adjacent to it.

**Security seams** (all Core):

- `src` must be relative / same-origin, or HTTPS on a small trusted host
  allowlist; `javascript:`/`vbscript:`/`data:`/`blob:`/`file:` and
  protocol-relative `//` are rejected.
- Attributes are allow-listed per type, escaped, and any `on*` handler rejects
  the registration.
- Inline JS/CSS are pattern-scanned (see §13.9-style checks) — a trusted
  theme/plugin API, not a full sanitizer. Rendering never throws.

**Core anchors** `tncms.frontend` / `tncms.admin` are registered as no-output
markers so `deps: ['tncms.frontend']` never warns. `/cms-health` reports counts
only (`asset_registry_ready`, `registered_asset_count`,
`enqueued_frontend_asset_count`, `enqueued_admin_asset_count`). Foundation only:
no Admin UI, no bundling/minification/build pipeline.

### 13.10.1 Script & Asset Diagnostics (v1.0.0-beta.7.1.13.3)

A **passive** observability layer over §13.9 (`ScriptManager`) and §13.10
(`AssetRegistry`). The managers, Global Script Settings, and the render pipeline
are **unchanged** — no rendering logic was rewritten.

- **Source metadata.** Each registration optionally carries
  `source_type` (`core` | `settings` | `theme` | `plugin` | `custom`) and
  `source_name` via an optional trailing `array $source = []`. It is stored in a
  **parallel source map** keyed the same way as the asset registry (`{type}:{key}`
  for scripts, handle for assets), so the immutable `ScriptAsset` / `Asset` value
  objects are untouched. Omitted → `custom`; unknown types fall back to `custom`
  with a warning.
- **`diagnostics()`** on both managers returns metadata only: registered /
  rendered / rejected counts, source/plugin/theme breakdowns, a `settings`
  breakdown (scripts) or `dependencies` breakdown (assets), warnings, and
  duplicates. `warnings()` returns short safe strings. Nothing exposes script
  code, JSON-LD, verification values, embed HTML, URLs, callbacks, or file paths.
- **Warnings** are collected passively for duplicate handles/keys, missing
  dependencies, unknown sources, and rejected entries. Last-wins is unchanged;
  nothing throws.
- **Hook.** `cms.scripts.settings.loading` fires (with the `ScriptManager`)
  before admin-managed `scripts.*` settings register, letting plugins inject
  settings-managed scripts.
- **Admin.** Settings → Scripts gains a read-only **Diagnostics** tab.
- **Health.** `/cms-health` adds `script_source_count`, `asset_source_count`,
  `script_warning_count`, `asset_warning_count`, `plugin_script_sources`,
  `theme_script_sources` (counts only).

### 13.10.2 Theme Custom CSS (v1.0.0-beta.7.1.13.4)

A Core-level feature that lets an admin store Custom CSS as part of Theme Options
and render it through the existing Asset Registry (§13.10). It adds **no** new
renderer, table, or rendering hook, and does not touch the Asset Registry, Theme
rendering, Theme Options architecture, or the Script Manager.

- **Pipeline (single).** Theme Options → `ThemeCustomCssManager::apply()` →
  `AssetRegistry::inlineStyle()` → frontend/admin render. Frontend CSS is
  registered as a **frontend-scoped** inline style in the head bucket, admin CSS
  as an **admin-scoped** inline style; the registry's scope filtering keeps each
  off the other surface. CSS is never echoed directly from Blade or the settings
  page.
- **Namespace.** Values live in the canonical Theme Options namespace, keyed by
  the active theme slug: `theme_options.{slug}.custom_css_frontend` and
  `theme_options.{slug}.custom_css_admin`, persisted via `ThemeOptionManager`.
  No loose `theme.custom_css_*` / `custom_css.*` / `scripts.*` keys, no new table.
  Because it travels with Theme Options, Custom CSS is carried by future Theme
  Export/Import, child themes, and preset sync.
- **Registration point.** Inline styles are active on registration, so
  `apply()` is wired **once at boot** (installed only), covering every render
  without a new rendering hook. The optional `cms.theme.custom_css.loading`
  action fires first (with the `AssetRegistry`) so plugins may inject before it.
- **Source metadata.** Every generated inline style is tagged
  `source_type=settings` / `source_name=theme-options`, so it appears
  automatically in Asset Diagnostics (§13.10.1).
- **Validation** (never throws; invalid CSS is neither stored nor rendered, and
  the UI shows the reason): rejects `</style`, `<script`, `javascript:`,
  `vbscript:`, `expression(`, `behavior:`, dangerous `@import`
  (remote/protocol-relative or `javascript:`/`vbscript:`/`data:`), NUL/control
  characters, and any payload over **256 KB** per field.
- **Admin.** Appearance → Theme Options gains a **Custom CSS** section (always
  present, even when the active theme declares no option schema).
- **Health.** `/cms-health` adds `theme_custom_css_enabled`,
  `theme_custom_css_frontend_size`, `theme_custom_css_admin_size` (sizes only —
  never CSS contents).

### 13.11 Frontend Authentication (v1.0.0-beta.7.1.14)

A secure frontend identity layer on the **existing shared users table** and the
**existing `web` guard** — there is deliberately no separate customers table.
`FrontendAuthManager` (`cms.frontend_auth`) owns the lifecycle; thin controllers
under `Http/Controllers/Auth/` delegate to it. Capability is decided entirely by
roles/permissions, so a frontend login never grants admin access (the panel is
still gated by `canAccessPanel()` / `admin.access`).

**Register → login → session.** Registration honours `auth.registration_enabled`,
assigns the configured `auth.default_role_id` (falling back to a permission-less
`subscriber` role, created on demand), and optionally auto-logs-in unless email
verification is required. Login regenerates the session id + CSRF token and seeds
a namespaced `cms_auth` session block (version, absolute expiry, last activity,
UA hash).

**Stolen-cookie defense (defense in depth):**

- **Session version** — a per-user `frontend_session_version` is stored in both
  the users row and the session. `cms.auth` / `cms.frontend_session` reject any
  request whose stored version no longer matches the user's current version.
- **Password change** bumps the version and rotates the remember token (old
  sessions and old remember cookies both die); the acting session is re-seeded.
- **Device change** — a login from a different SHA-256 User-Agent hash bumps the
  version + rotates the remember token; the same device does not. Only the hash
  is stored, never the raw agent; IP is audit-only (mobile networks roam).
- **Lifetimes** — frontend vs remember (and admin) lifetimes plus an optional
  idle timeout are enforced by the middleware via the absolute expiry in the
  session, independent of Laravel's global cookie lifetime.

**Middleware:** `cms.auth` (login + session policy), `cms.guest`, `cms.role`,
`cms.permission`, `cms.verified` (only when verification is enabled), and
`cms.frontend_session` (standalone policy check). Registered as router aliases in
`CmsServiceProvider::boot()`.

**Settings** live in the Core `auth.*` group (private, autoloaded) with
`config/cms.php` fallbacks so the system works before seeding. **Hooks** cover
the full lifecycle (`cms.auth.*` actions and `cms.auth.*` filters, each passed a
`HookContext`). **Health** exposes readiness/policy flags only — no emails,
session ids, or tokens.

Known limitation: per-guard remember durations are enforced through the session's
absolute expiry rather than distinct cookie lifetimes.

### 13.12 Account Foundation (v1.0.0-beta.7.1.15)

The logged-in frontend **account area**, layered on §13.11. `AccountManager`
(`cms.account`) owns all business logic; thin controllers under
`Http/Controllers/Account/` delegate to it. Routes live in `routes/account.php`,
registered **before** the frontend `{slug}` catch-all and gated by
`web` + `cms.auth` + `cms.frontend_session` (sensitive POSTs additionally
throttled).

**What Core owns:** user identity, profile basics (`name`, `username`, `phone`,
`bio`, `avatar`, `timezone`), email change, password change, locale preferences
(the existing `frontend_locale` / `admin_locale` / `editing_locale`, each set
independently), the account shell, and session/security basics.

**What Core does NOT own:** customer, order, address, invoice, wishlist,
VIP/membership, download, cart, and subscription data. Those are **plugin
domains** — plugins inject their own account sections through the hooks below and
must never be implemented in Core.

**Account shell.** `cms::account.layout` is a self-contained core layout (sidebar
navigation + main + flash + hook areas) that renders without requiring an active
theme. Navigation is built by `AccountManager::navigationItems()` from
`AccountNavItem` value objects (`key/label/url/icon/priority/active/permission/
badge`); the `cms.account.navigation_items` filter lets plugins add/remove/reorder
items (permission-filtered, priority-sorted, active-marked).

**Security.** Email/password changes and "log out other sessions" require the
current password + CSRF. Password change bumps the session version, rotates the
remember token, and regenerates the session id/CSRF; email change resets
verification (when enabled) and rotates other sessions — both via the new
`FrontendAuthManager::rotateSessionsExceptCurrent()` primitive. Old stolen
sessions become invalid on their next request.

**Hooks** (group `Account`): lifecycle actions
(`cms.account.{profile,email,password,preferences}.{updating,updated}`,
`cms.account.sessions.invalidated`), render-area actions (`cms.account.before`,
`…after`, `…sidebar.before/after`, `…navigation`, `…dashboard.before/after`,
`…profile.after`, `…security.after`, `…preferences.after`, echoed via
`render_hook`), and filters (`cms.account.navigation_items`,
`cms.account.profile_data`, `cms.account.preferences_data`,
`cms.account.redirect_after_update`). **Health** exposes readiness booleans only.

Known limitation: the sessions page is foundation-level (current session facts +
"log out others"); there is no full per-device session registry.

---

## 14. Extension points (for future plugins/themes)

These are the supported seams today, plus where future extension is meant
to plug in.

**Themes (✅ available now):**
- Add `themes/{slug}/` with `theme.json` + `views/` + `assets/`.
- Activate via `ThemeManager::activate($slug)` (admin: Appearance → Themes).
- Override any `theme::` view; missing views fall back to `default`.
- Declare theme options in `functions.php` (`return ['options' => ['sections' =>
  …]]`) — loaded safely by `ThemeManager::themeConfig()`; values managed via
  Appearance → Theme Options and read with `theme_option()` (see §10.6).

**Menu locations (✅ available now):**
- A theme declares `supports.menus` in `theme.json` (descriptive).
- Render with `frontend_menu('header')` / `frontend_menu('footer')`.

**Settings (✅ available now):**
- Add config via `SettingsManager::set('group.key', value, type, options)`.

**Content types (✅ available now):**
- `cms_contents.type` is a free string; new types can be introduced by
  app-level resources delegating to `ContentManager`.

**Plugins (✅ available now — Extension Framework Core, 0.9.8):**
- Add `plugins/{slug}/` with a `plugin.json` (+ optional `src/`, `routes/web.php`,
  `resources/views/`, `database/migrations/`).
- Activate via `extension()->activatePlugin($slug)` (registry in
  `cms_settings.extensions.active_plugins`). Active plugins boot their providers,
  routes, views, and migrations. See §10.5 and `PLUGIN_DEVELOPMENT.md`.
- 🔵 A Plugin Manager **UI**, installer/ZIP upload, and plugin settings UI are
  not built yet.

**🔵 Reserved / planned seams (no working code yet):**
- `base_path('modules')` directory is created on boot, but there is **no
  module loader** (distinct from the plugin loader in §10.5, which is live).
- `cms_mediables` polymorphic pivot exists but is **unused** — reserved for
  future media attachments / galleries.
- `public/vendor/cms` asset directory is created but currently unused.
- Reserved namespaces mentioned in `CMS_STRUCTURE.md`
  (`TheNguyen\CMS\Contracts\`, `TheNguyen\CMS\Core\`) contain **no classes**.

---

## 15. PLANNED — explicitly NOT implemented

Do **not** document or rely on these as if present. None of the following
exist in code:

- 🔵 Plugin **file editor**, **GitHub/URL/CLI installer**, and plugin settings UI.
  (The **Extension Framework Core** shipped in `0.9.8` (§10.5); the **Plugin
  Manager UI** in `1.0.0-beta.1` (§10.5.1); the safe **local ZIP installer** +
  hardening in `1.0.0-beta.2` (§10.5.2). The file editor and remote/URL/CLI
  installers have not.) A generic module loader (`base_path('modules')`) is still
  absent.
- 🔵 Theme **Customizer** (live preview). The **Theme Options Framework** —
  dynamic schema from the active theme's `functions.php`, the
  `ThemeOptionManager` storage layer, and the Appearance → Theme Options admin
  page — shipped in `0.9.9`; see §10.6. A live customizer has not.
- 🔵 Widgets system
- 🔵 Page Builder
- 🔵 Advanced SEO plugin / analyzer / AI SEO, structured data (JSON-LD),
  sitemap index/pagination, per-record robots overrides. (A **lightweight
  SEO Core** shipped in `0.7.5` — see §11.5 — covering meta tags,
  Open Graph, Twitter cards, `robots.txt`, and `sitemap.xml`.)
- 🔵 Theme/plugin **remote marketplace** or GitHub/URL installer (the local
  ZIP installer shipped in `1.0.0-beta.2`; see §10.5.2)
- 🔵 Media attachments via `cms_mediables`, galleries, image cropping,
  S3/CDN disks. (A **Rich Editor** shipped in `0.8.0` — see §11.6 — with
  TinyMCE + URL-based media insert; a full media modal/grid picker,
  in-editor upload, and AI writer remain planned.)
- 🔵 `theme.json` `requires`/`supports` enforcement. (Theme `functions.php`
  **loading** + the Theme Options Framework shipped in `0.9.9` — see §10.6.)
- 🔵 REST/JSON API layer, SaaS/workspace features

---

## 16. How AI assistants should work with this codebase

Read this section before changing anything.

### Hard rules (non-negotiable)

1. **Do not modify `vendor/`.** It is third-party, managed by Composer.
   Never edit, patch, or commit changes inside `vendor/`.
2. **Do not create additional Filament panels.** There is exactly one panel
   (`admin`). Extend it via app-level resources/pages/widgets under
   `app/Filament/Admin/`.
3. **Keep core logic in `packages/thenguyen/cms-core`.** Reusable CMS
   behaviour (models, services, facades, helpers, migrations, frontend
   controller, routes) belongs in the core package. App-level
   routes/controllers/Filament classes should stay thin and delegate to the
   core managers.

### Working conventions

- **Verify before claiming.** This file separates ✅ IMPLEMENTED from 🔵
  PLANNED. Do not treat roadmap items as if they exist — confirm against the
  code first. If a fact here ever conflicts with the code, the code wins;
  fix this doc.
- **Persist through the managers, not raw queries.** Use `ContentManager`,
  `TaxonomyManager`, `MediaManager`, `MenuManager`, `SettingsManager`,
  `ThemeManager` (or their facades / helpers). They handle translations,
  slugs, transactions, and cache invalidation.
- **Respect multi-language schema.** Translatable data lives in
  `*_translations` tables keyed by `(parent_id, locale)`; default locale is
  `vi`.
- **Theme views use the `theme::` namespace** and fall back active →
  `default`. Reference siblings as `theme::layouts.master`, etc.
- **Publish theme assets by copying** (`ThemeManager::publishAssets`) — no
  symlinks (shared-hosting friendly).
- **Frontend routes:** the catch-all `/{slug}` must remain **last** and keep
  its reserved-prefix constraint. Never let it capture `admin`,
  `cms-health`, `livewire`, `filament`, `storage`, `uploads`, `themes`,
  `up`.
- **`/cms-health` must never 500.** Keep its checks defensively guarded.
- **Database changes are additive and deliberate.** Add migrations in the
  core package; do not alter existing `cms_*` structure without a clear
  reason.
- **After implementing a phase, update the docs:** this file
  (`CMS_ARCHITECTURE.md`), `CMS_STRUCTURE.md`, `CMS_CHANGELOG.md`, and
  `CMS_GUIDE.md`, and bump `CmsInfo::VERSION`.

### Quick verification commands

```bash
php artisan route:list          # confirm route order / constraints
php artisan about               # framework + package overview
curl -s /cms-health             # JSON readiness snapshot
php artisan tinker               # exercise services: app('cms.theme')->active(), etc.
```
```php
theme()->active();              // active theme
theme_asset('css/app.css');     // /themes/default/css/app.css
frontend_menu('header');        // header menu tree
content_url($page);             // /{slug} or /blog/{slug}
```

---

_Last verified: 2026-06-06 · CMS version 1.0.0-beta.1 (Plugin Manager UI)._

_Companion docs (`README.md`, `CMS_STRUCTURE.md`, `CMS_CHANGELOG.md`,
`CMS_GUIDE.md`, `THEME_DEVELOPMENT.md`, `PLUGIN_DEVELOPMENT.md`) were synchronized
against this document on 2026-06-06; this file remains the single source of
truth._
