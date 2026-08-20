# TN CMS â€” Plugin Development Guide

> How to build a plugin for **TN CMS** using the **Extension Framework Core**
> (v0.9.8). Code-verified against the framework. For the authoritative
> architecture see [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md) Â§14.1; for themes
> see [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md).

- **CMS:** TN CMS Â· <https://tncms.org> Â· support@tncms.org Â· The Nguyen Media

> **Scope.** The plugin *foundation* (v0.9.8) ships discovery, manifest
> validation, an active-plugin registry, and safe booting (providers, routes,
> views, migrations). The **Plugin Manager UI** (v1.0.0-beta.1) adds an admin
> **Installed Plugins** page (`/admin/plugins`) to activate/deactivate plugins and
> view metadata + invalid-plugin warnings â€” see Â§11. The **ZIP Installer**
> (v1.0.0-beta.2) adds **Plugins â†’ Install Plugin** (`/admin/plugins/install`) for
> safe local `.zip` upload installation â€” see Â§12. There is still **no** plugin
> file editor, plugin settings UI, marketplace, or remote/CLI installer. You can
> also activate/deactivate programmatically via the `extension()` API (see Â§7).

---

## 1. Directory structure

```
plugins/{plugin-slug}/
â”œâ”€â”€ plugin.json                 # required manifest (see Â§2)
â”œâ”€â”€ src/                        # PSR-4 root â†’ namespace Plugins\{StudlySlug}\
â”‚   â””â”€â”€ {Name}ServiceProvider.php
â”œâ”€â”€ routes/
â”‚   â””â”€â”€ web.php                 # optional â€” auto-loaded (web middleware) when active
â”œâ”€â”€ resources/
â”‚   â””â”€â”€ views/                  # optional â€” registered as the "{slug}::" namespace
â”‚       â””â”€â”€ welcome.blade.php
â””â”€â”€ database/
    â””â”€â”€ migrations/             # optional â€” discovered for `php artisan migrate`
```

Plugins live in the project's `plugins/` directory (created automatically on
boot). Each plugin is a self-contained folder with a `plugin.json`.

---

## 2. `plugin.json` schema

```json
{
    "name": "Hello World",
    "slug": "hello-world",
    "version": "1.0.0",
    "author": "The Nguyen Media",
    "description": "Example TN CMS plugin.",
    "requires": {
        "tncms": "^1.0"
    },
    "providers": [
        "Plugins\\HelloWorld\\HelloWorldServiceProvider"
    ]
}
```

| Key | Required | Notes |
| --- | --- | --- |
| `name` | âœ… | Human-readable plugin name. |
| `slug` | âœ… | Folder-safe identifier; should match the directory name. |
| `version` | âœ… | SemVer string. |
| `author` | âœ… | Author / vendor name. |
| `description` | optional | Shown in listings. |
| `providers` | optional | FQCNs of Laravel service providers to register when active. |
| `requires` | optional | Version constraints, e.g. `{"tncms": "^1.0"}` â€” **descriptive only**, not enforced yet. |

**Validation.** A plugin is only discovered when `plugin.json` is valid JSON
**and** has non-empty `name`, `slug`, `version`, `author`. Invalid plugin
folders are skipped (never loaded) and reported by
`extension()->invalidPlugins()` â€” an invalid or broken plugin never crashes the
CMS.

---

## 3. Namespace convention (PSR-4)

A plugin's PHP classes live under `Plugins\{StudlySlug}\`, mapped to the
plugin's `src/` directory. The framework registers this PSR-4 autoloader at
runtime when the plugin is active â€” **no `composer dump-autoload` is required**
for plugin classes.

| Slug | Namespace prefix | Maps to |
| --- | --- | --- |
| `hello-world` | `Plugins\HelloWorld\` | `plugins/hello-world/src/` |
| `my-shop` | `Plugins\MyShop\` | `plugins/my-shop/src/` |

So `Plugins\HelloWorld\HelloWorldServiceProvider` â†’ `src/HelloWorldServiceProvider.php`,
and `Plugins\HelloWorld\Http\FooController` â†’ `src/Http/FooController.php`.

---

## 4. Service providers

List provider FQCNs in `plugin.json` â†’ `providers`. When the plugin is active,
each is registered via the Laravel container (`register()` then `boot()`). A
provider is where you bind services, register event listeners, publish config,
etc. â€” exactly like a normal Laravel package provider.

```php
<?php

namespace Plugins\HelloWorld;

use Illuminate\Support\ServiceProvider;

class HelloWorldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('hello-world.greeting', fn () => 'Hello World from TN CMS Plugin');
    }

    public function boot(): void
    {
        // Routes, the {slug}:: view namespace, and migrations are wired
        // automatically by the TN CMS ExtensionManager â€” no need to repeat them.
    }
}
```

> If a provider throws, the framework **catches the error, logs it, and skips
> that plugin** â€” the rest of the CMS keeps booting.

---

## 5. Routes

If a plugin has `routes/web.php`, it is loaded automatically when the plugin is
active, wrapped in the **`web`** middleware group, and registered **before** the
frontend catch-all (`/{slug}`) so plugin paths resolve correctly.

```php
use Illuminate\Support\Facades\Route;

Route::get('/hello-world', fn () => view('hello-world::welcome'))
    ->name('plugin.hello-world.index');
```

> Only `routes/web.php` is loaded in v0.9.8. API route files are out of scope
> for this phase.

---

## 6. Views

Put Blade files in `resources/views/`. When active, they are registered under
the plugin **slug** namespace, so:

```php
view('hello-world::welcome', ['greeting' => 'Hi']);
```

resolves `plugins/hello-world/resources/views/welcome.blade.php`.

---

## 7. Activation lifecycle

The active-plugin set is stored in `cms_settings` under
`extensions.active_plugins` (a JSON array of slugs). Use the `extension()`
helper / `Extension` facade:

```php
extension()->plugins();              // all valid discovered plugins
extension()->invalidPlugins();       // [{slug, reason}, ...]
extension()->findPlugin('hello-world');
extension()->isPluginActive('hello-world');
extension()->activatePlugin('hello-world');   // validates manifest, adds to registry
extension()->deactivatePlugin('hello-world'); // removes from registry
extension()->activePlugins();        // valid + active plugins
extension()->activePluginSlugs();    // raw active slugs
```

Lifecycle: **discover â†’ activate (registry updated) â†’ boot on the next
request** (providers, routes, views, migrations). `activatePlugin()` validates
the manifest and is idempotent; it does not hot-boot the plugin in the current
request.

---

## 8. Migrations & the activation lifecycle

Put migrations in `database/migrations/` (and optional seeders in
`database/seeders/`). **Activation installs your database automatically**: when a
plugin is activated (admin UI or `php artisan plugin:activate <slug>`) the
lifecycle runs your pending migrations and seeders, verifies them, and only then
marks the plugin active. So your dashboard never 500s on a missing table â€” no
manual `php artisan migrate` needed.

The flow is `validate â†’ migrate â†’ seed â†’ verify â†’ mark active`. If a migration
fails, the plugin **stays inactive** (never half-installed) and the error is
surfaced in the admin UI. Activation is idempotent â€” already-applied migrations
are not rerun. **Deactivation never rolls back migrations** (your data survives a
disable/enable cycle).

Custom paths â€” declare them in `plugin.json` (each is relative to the plugin
root; a string or a list). Omit the block to use the conventional defaults:

```json
{
    "database": {
        "migrations": "database/migrations",
        "seeders": "database/seeders"
    }
}
```

If a plugin's install ever fails, it stays inactive and its pages are never
registered, so it cannot be reached to error. For the legacy edge (a plugin
activated before this system), core exposes a `PluginInstallationGuard`
(`cms.plugin_installation_guard`): use `->safe(fn () => â€¦, $fallback)` around a
DB read, or check `->isInstalled($slug)` to render an "installation incomplete"
panel instead of throwing.

---

## 9. Version compatibility

`requires.tncms` (e.g. `"^1.0"`) documents the TN CMS version your plugin
targets. It is **descriptive only** in v0.9.8 â€” not enforced â€” but you should
keep it accurate for the future Plugin Manager.

---

## 10. Best practices / what NOT to do

- **Do not modify `vendor/`** â€” it is Composer-managed.
- **Do not create Filament panels directly** â€” there is exactly one admin panel
  (`admin`); extend it via app-level resources/pages when that surface lands.
- **Use TN CMS APIs** â€” `extension()`, `settings()`, `theme()`, `menu()`,
  `content_url()`, `seo()`, etc. â€” instead of querying the database directly.
- **Keep providers resilient** â€” throwing from a provider gets your plugin
  skipped; fail soft where you can.
- **Namespace correctly** â€” `Plugins\{StudlySlug}\` mapped to `src/`.
- **Don't hardcode admin/Livewire/Filament paths.**

---

## 11. Plugin Manager UI (v1.0.0-beta.1)

Plugins can now be managed from the admin under the **Plugins** navigation group:

- **Plugins â†’ Installed Plugins** (`/admin/plugins`) â€” lists every valid plugin
  as a card (name, description, slug, version, author, `requires.tncms`, provider
  list + count) with an **Active**/**Inactive** badge. Click **Activate** /
  **Deactivate** to toggle the active registry (these call
  `extension()->activatePlugin()` / `deactivatePlugin()`). Invalid plugin folders
  are listed separately with their reason and have no Activate button. An active
  plugin whose declared provider class is missing shows a warning.
- **Plugins â†’ Install Plugin** (`/admin/plugins/install`) â€” âœ… functional ZIP
  installer as of v1.0.0-beta.2 (see Â§12).
- **Plugins â†’ Plugin Editor** (`/admin/plugins/editor`) â€” placeholder; plugin
  file editing is **not implemented yet** (will be limited to safe file types).

> **Route/cache note.** Plugin routes register at application **boot**, so after
> activating/deactivating a plugin its routes (e.g. `/hello-world`) only change on
> the **next** request. The admin clears caches for you (best-effort
> `optimize:clear`); in some environments you may need to run
> `php artisan optimize:clear` or reload.

---

## 12. Installing a plugin from a ZIP (v1.0.0-beta.2)

**Plugins â†’ Install Plugin** (`/admin/plugins/install`) installs a plugin from a
local `.zip` upload. Package your plugin so the archive contains either a single
wrapping folder **or** the files at the root â€” both work:

```
my-plugin.zip
â””â”€â”€ my-plugin/            # (or no wrapping folder â€” files at root)
    â”œâ”€â”€ plugin.json       # REQUIRED â€” name, slug, version, author
    â”œâ”€â”€ src/
    â””â”€â”€ routes/web.php
```

The destination folder is taken from the **manifest `slug`**, not the archive
name. The installer (core `ExtensionInstaller`, helper `extension_installer()`):

1. accepts only readable `.zip` files;
2. validates **every entry before extracting** â€” path traversal (`..`),
   absolute/drive-letter paths, empty names, symlinks, and too-many/too-large
   archives are rejected;
3. extracts to a throwaway `storage/app/tncms-installer/{random}` (never directly
   into `plugins/`), then locates a **single** `plugin.json` (multiple â†’ rejected
   as ambiguous);
4. validates the manifest (`name`, `slug`, `version`, `author`; slug must be
   lowercase slug-like and not a reserved name);
5. moves the validated files to `plugins/{slug}` and **always** cleans up temp.

**Overwrite is off by default** â€” a duplicate slug fails ("already installed").
Tick *Overwrite existing files* to replace one, but an **active** plugin can never
be overwritten (deactivate it first). The plugin is installed **inactive** and
never executed during install (a declared provider with no `src/` is a non-fatal
warning); activate it from **Installed Plugins** when ready.

Programmatic equivalent:

```php
$result = extension_installer()->installPluginFromZip($absoluteZipPath, overwrite: false);
// $result->success, $result->message, $result->slug, $result->errors, $result->warnings
```

**Deleting a plugin.** An **inactive** plugin can be removed from **Installed
Plugins** via the danger **Delete** action (confirmation required). Active plugins
have no Delete action â€” deactivate first. Programmatic equivalent:

```php
$result = extension_installer()->deletePlugin($slug); // InstallResult
```

The slug is validated (lowercase slug-like; no `/`, `\`, `..`), the path is
resolved internally from `plugins/`, and a `realpath` containment check ensures
only `plugins/{slug}` is removed. An **active** plugin is refused.

ðŸ”µ **Not implemented:** remote-URL install, CLI install, marketplace, file editor.

---

## 13. Registering permissions (v1.0.0-beta.3)

TN CMS has an RBAC layer (roles â†’ string permissions). A plugin can register its
own permissions at **runtime** from its service provider's `boot()`, using the
`Permission` facade (or the `cms.permission` service). Registration is in-memory;
call `syncDefaults()` (or re-run the role/permission seeder) to mirror them into
the `cms_permissions` table so they appear in the role editor.

```php
use TheNguyen\CMS\Facades\Permission;

public function boot(): void
{
    // slug, human name, group (role-editor section), optional description
    Permission::register('hello-world.manage', 'Manage Hello World', 'Hello World');
}
```

Then guard your plugin's pages/actions with the helper:

```php
if (! cms_can('hello-world.manage')) {
    abort(403);
}
```

Notes:

- Use a **namespaced slug** (`{slug}.{action}`) so it never clashes with core
  permissions.
- Super admins bypass all checks; before the RBAC system is seeded every check
  passes (fail-open), so a plugin still works on a fresh install.
- ðŸ”µ **Not implemented:** automatic permission discovery from `plugin.json`. For
  now, register in code as shown above.

---

## 14. Translations (v1.0.0-beta.5)

Translate your plugin's **interface** strings with JSON files (separate from content
translations). Add one file per locale â€” the bare `lang/` path is preferred, with
`resources/lang/` supported for compatibility:

```
plugins/{your-plugin}/lang/en.json
plugins/{your-plugin}/lang/vi.json
```

Each is a flat map of source string â†’ translation:

```json
{
    "Hello World from TN CMS Plugin": "Xin chÃ o tá»« plugin TN CMS",
    "Hello :name": "Xin chÃ o :name"
}
```

In your routes/views, use `plugin_trans()` with your plugin slug:

```blade
<h1>{{ plugin_trans('hello-world', 'Hello World from TN CMS Plugin') }}</h1>
{{ plugin_trans('hello-world', 'Hello :name', ['name' => 'TN CMS']) }}  {{-- Hello TN CMS --}}
```

`plugin_trans($slug, $key, $replace = [], $locale = null)` resolves in this order:
your plugin `lang/{locale}` â†’ `resources/lang/{locale}` â†’ your plugin
`lang/{default}` â†’ `resources/lang/{default}` â†’ the CMS core `{locale}`/`{default}`
files â†’ the key itself. The locale defaults to `current_locale()`. Replacements use
Laravel-style `:name` / `:Name` / `:NAME`. Missing files or invalid JSON never error.

When the plugin is **active**, its `lang/` and `resources/lang/` dirs are also
registered on Laravel's translator at boot, so `__()` sees them too. An admin can run
**Languages â†’ Sync Translation Files** to generate empty `{}` files for **active**
plugins across all active languages (existing files are never overwritten). The
bundled `hello-world` plugin ships `lang/en.json` + `lang/vi.json` as a working
example.

> **Admin vs plugin strings (v1.0.0-beta.5.1).** The TN CMS **admin UI** is fully
> translated through the **core** `lang/{locale}.json` files, and Filament's own
> built-in strings follow the CMS locale automatically (no vendor edits). Your plugin
> only owns its own interface strings via `plugin_trans()`. If your plugin registers a
> Filament resource/page with hardcoded labels, wrap them in `tn_trans()` (admin locale
> = the CMS **default language**) or `plugin_trans('your-slug', â€¦)` and ship the
> matching keys in your plugin `lang/` files so the admin stays single-language.

---

## Widgets (1.0.0-beta.7)

A plugin can ship its own widgets. Register them from your plugin's service
provider `boot()` (the runtime autoloads active plugins, so no `composer dump`
is needed):

```php
public function boot(): void
{
    widget()->register(\MyPlugin\Widgets\LatestTweetsWidget::class);

    // Optional: register a plugin-owned area for themes to render.
    widget()->registerArea('plugin-promo', 'Plugin Promo', [
        'source' => 'plugin',
        'source_slug' => 'my-plugin',
    ]);
}
```

A widget class extends `TheNguyen\CMS\Widgets\Widget` and implements `type()`,
`name()`, `schema()`, and `render(array $settings, ?string $locale): string|View`,
plus optional `description()`, `icon()`, and `group()` (the admin picker bucket).

### Field API (1.0.0-beta.7.1)

Declare `schema()` with the fluent field objects in
`TheNguyen\CMS\Widgets\Fields` â€” `TextField`, `TextareaField`, `ToggleField`,
`SelectField`, `NumberField`, `MediaField`, `RichEditorField`, `RepeaterField`:

```php
use TheNguyen\CMS\Widgets\Fields\{NumberField, ToggleField};

public static function group(): string { return 'Marketing'; }

public static function schema(): array
{
    return [
        NumberField::make('limit')->label('Tweets')->default(5)->min(1)->max(20),
        ToggleField::make('show_avatars')->default(true),
        // TextField::make('heading')->localized(),  // per-locale field
    ];
}
```

Fields marked `->localized()` are stored per-locale in `cms_widget_translations`;
everything else is global in `cms_widgets.settings`. Plain beta.7 arrays
(`['key' => â€¦, 'type' => â€¦, 'localized' => true]`) still work â€” fields normalise
to the same shape. `render()` must return safe output (escape text, or sanitize
HTML via `app('cms.html')->sanitize()`). Any exception thrown while rendering is
caught and `report()`ed, so a broken plugin widget never 500s the site â€” at most
a debug-only HTML comment is emitted. An invalid or duplicate widget class is
skipped/last-wins and reported; it never breaks boot.

### Presets (1.0.0-beta.7.1)

Register reusable widget bundles an admin can stamp into an area in one click:

```php
widget()->registerPreset('my-cta-footer', [
    'name' => 'CTA Footer',
    'widgets' => [
        ['type' => 'text', 'title' => 'Get in touch'],
        ['type' => 'html'],
    ],
]);
```

Applying a preset appends its widgets (never overwrites). Widget configurations
are also portable between sites via the admin's JSON **Export / Import** â€”
`widget()->export()` / `widget()->import($data, 'append'|'replace')`, with
translations preserved â€” and a single instance can be cloned with
`widget()->duplicate($widgetId)` (v1.0.0-beta.7.1.1).

## Hooks & Shortcodes (v1.0.0-beta.7.1.11)

A WordPress-inspired, **in-process** extension system (not webhooks). Register
hooks and shortcodes from your plugin's service provider `boot()`.

### Actions & filters

```php
// React to an event (side effects only).
add_action('cms.content.saved', function ($content, $data) {
    // e.g. bust a cache, dispatch a jobâ€¦
}, priority: 10, acceptedArgs: 2);

// Transform a value and return it.
add_filter('cms.content.title', function (string $title, $content): string {
    return $title.' â€¢ '.config('app.name');
}, 10, 2);
```

Callbacks run in ascending `priority`; ties keep registration order. A callback
that throws is caught and logged â€” it never crashes the page, and a filter's
value passes through unchanged. Remove with `Hook::removeAction($hook, $cb)` /
`removeFilter()`. Namespaced aliases (`tn_add_action`, â€¦) are always available,
as are WordPress-style `cms_*` aliases (`cms_add_action`, `cms_do_action`,
`cms_add_filter`, `cms_apply_filters`) since `1.0.0-beta.7.1.13`.

**Stable hook points:** `cms.content.title|excerpt|body`,
`cms.content.before_shortcode|after_shortcode`, `cms.post.rendered`,
`cms.page.rendered`, `cms.content.saving|saved`, `cms.term.saving|saved`,
`cms.media.uploaded`, `cms.theme.header|before_content|after_content|footer`,
`cms.shortcode.output`, `cms.menu.resolve`.

The **`cms.menu.resolve`** filter (`1.0.0-beta.7.1.13`) fires from
`frontend_menu($location)` **only when the location has no local menu**. Return a
render-ready tree (`array<int, array{item, title, url, children}>`) to inject one
(e.g. a remote menu), or `null` to skip â€” a local menu always wins:

```php
cms_add_filter('cms.menu.resolve', function ($resolved, string $location) {
    return $resolved ?? my_remote_menu_tree_for($location); // array or null
}, 10, 2);
```

### Hook context (v1.0.0-beta.7.1.11.1)

Core hook points pass a **`HookContext`** as the final argument. Request it by
raising `acceptedArgs` â€” existing callbacks that don't are unaffected:

```php
use TheNguyen\CMS\Support\Hooks\HookContext;

add_action('cms.content.saved', function ($content, array $data, HookContext $context) {
    $user   = $context->user();          // ?Authenticatable
    $locale = $context->editingLocale(); // string
    $panel  = $context->panel();         // ?string e.g. "admin"
}, 10, 3, [
    'source'      => 'plugin',           // optional registration metadata
    'source_slug' => 'ai-writer',
]);
```

`HookContext` is immutable (`with()` returns a clone) and every accessor
(`request()`, `user()`, `locale()`, `editingLocale()`, `frontendLocale()`,
`theme()`, `routeName()`, `panel()`) is null-safe and never throws. Build one
yourself with `hook_context([...])`.

### Documenting your own hook points

Declare hooks so other extensions (and future tooling) can discover them. A
definition is optional â€” hooks fire with or without one:

```php
define_action('myplugin.invoice.paid', [
    'description' => 'Fires after an invoice is marked paid.',
    'arguments'   => ['invoice', 'context'],
    'group'       => 'Billing',
]);

define_filter('myplugin.invoice.total', [
    'description' => 'Filters the computed invoice total.',
    'return_type' => 'float',
]);

// Discovery (counts/metadata only â€” never the callbacks):
hook_definitions();              // array<string, HookDefinition>
hooks()->actionSummary();        // per-hook count / priorities / source counts
```

### Shortcodes

```php
add_shortcode('box', function (array $attrs, ?string $content, array $context): string {
    $title = e($attrs['title'] ?? '');
    return '<div class="box"><h3>'.$title.'</h3>'.e((string) $content).'</div>';
});
```

`[box title="Hello"]Body[/box]` in post/page content expands at render time.
The callback receives parsed attributes, the inner content (for enclosing
tags), the render context, and the tag name. Output passes through the
`cms.shortcode.output` filter and the body sanitizer, so emit allow-listed HTML.

**Security:** shortcodes only call PHP-registered callbacks; unknown tags do
nothing; there is no `eval`, no PHP-from-database, and no class instantiation
from content. Built-in shortcodes: `[button]`, `[year]`, `[site_name]`.


## Global Script Manager (v1.0.0-beta.7.1.13)

Plugins register frontend head/footer scripts, verification meta, JSON-LD, and
trusted iframe embeds through the Core `Script` facade (or the `register_*`
helpers). They never touch theme Blade — the active theme already renders
whatever is registered via `render_head_assets()` / `render_footer_assets()`.

Register from your plugin service provider's `boot()`:

```php
use TheNguyen\CMS\Facades\Script;

Script::externalHead('gtm', 'https://www.googletagmanager.com/gtag/js?id=G-XXXX');
Script::head('gtag-inline', 'window.dataLayer=window.dataLayer||[];');
Script::footer('clarity', 'console.log("footer");');

Script::meta('robots', 'index,follow');
Script::verification('google', 'your-verification-token');

Script::jsonLd('organization', [
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => 'TN CMS',
]);

Script::embed('yt-promo', '<iframe src="https://www.youtube.com/embed/abc" title="Promo" allowfullscreen></iframe>', 'footer');
```

Every method returns `bool` — `true` when accepted, `false` when rejected by the
security policy. Registrations are keyed by `{type}:{key}`; re-registering the
same key **replaces** the earlier asset. Pass a `priority` (default `10`, lower
renders first) as the last argument to order within a group.

### Security policy (enforced by Core — plugins cannot weaken it)

- **Inline scripts** are rejected if they contain `eval(`, `new Function`,
  `document.write`, `javascript:`, `vbscript:`, `data:text/html`, `blob:`,
  `file:`, or inline event handlers (`onload=`, `onclick=`, `onerror=`).
- **External scripts** must be same-origin relative (`/js/app.js`) or on the
  trusted host allowlist (Google/GTM/GA, `connect.facebook.net`, Clarity,
  Cloudflare Insights, jsDelivr, cdnjs, unpkg). Protocol-relative `//host` URLs
  are rejected.
- **Verification** accepts only the value; `provider` must be one of `google`,
  `bing`, `yandex`, `facebook`, `pinterest`, `baidu` (others are ignored).
- **JSON-LD** must be valid JSON (array or string); it is re-encoded HTML-safely.
- **Embeds** are iframe-only, host-allowlisted (Google/Maps/GTM, Facebook,
  YouTube, Vimeo, giscus), and stripped to safe attributes (`src`, `width`,
  `height`, `title`, `loading`, `allow`, `allowfullscreen`, `referrerpolicy`,
  `class`). `<script>/<object>/<embed>`, event handlers, `style`, `srcdoc`, and
  `javascript:`/`data:`/`blob:`/`file:` sources are rejected.

### Hooks

Adjust rendered output with the existing `HookManager`:

- Actions: `cms.scripts.rendering_head`, `cms.scripts.rendering_footer`.
- Filters: `cms.scripts.head_html`, `cms.scripts.footer_html` (receive/return the
  final HTML string for the location).

Rendering never throws on the frontend; rejected registrations are reported via
`Script::rejected()` and surfaced only as counts on `/cms-health`.

## Asset Registry (v1.0.0-beta.7.1.13.1)

Use the **Asset Registry** (`cms.assets`, facade `Asset`) to load **CSS/JS files
by handle** with dependency resolution — the modern equivalent of WordPress
`wp_enqueue_*`. Use the Script Manager (above) for meta/JSON-LD/embeds; use the
Asset Registry for stylesheets and script files. No bundling or minification.

### Register, enqueue, render

Registering *defines* an asset; only *enqueued* assets (and their dependencies)
render. Register once, enqueue where the asset is actually needed:

```php
use TheNguyen\CMS\Facades\Asset;

Asset::registerStyle(
    handle: 'ecommerce.product',
    src: plugin_asset('ecommerce', 'css/product.css'),
    deps: ['tncms.frontend'],
    version: '1.0.0',
    scope: 'frontend',            // frontend | admin | both
);
Asset::enqueueStyle('ecommerce.product');

Asset::registerScript(
    handle: 'ecommerce.checkout',
    src: plugin_asset('ecommerce', 'js/checkout.js'),
    deps: ['tncms.frontend'],
    version: '1.0.0',
    position: 'footer',          // head | footer
    attributes: ['defer' => true],
);
Asset::enqueueScript('ecommerce.checkout');

// Inline config attached after a handle (renders adjacent to it)
Asset::inlineScript('ecommerce.checkout.config', 'window.TNCMS_CHECKOUT = {};', after: 'ecommerce.checkout');
```

Modules render as `<script type="module">` via `Asset::registerModule()` /
`enqueueModule()`. `Asset::style()` / `Asset::script()` register **and** enqueue
in one call. Helper functions mirror every method: `register_style()`,
`register_script()`, `register_module()`, `enqueue_style()`, `enqueue_script()`,
`enqueue_module()`, `inline_style()`, `inline_script()`, plus `tn_register_*` /
`tn_enqueue_*` aliases.

### Dependency graph

`deps` lists handles that must render first. Enqueueing an asset automatically
pulls in its dependencies. A **missing** dependency is reported via
`Asset::lastRenderWarnings()` and the dependent still renders; a **circular**
dependency is broken safely (no infinite loop) and reported. Register the same
handle twice and the last registration wins (keeping its sequence). Depend on the
built-in `tncms.frontend` / `tncms.admin` anchors without a missing-dep warning.

### Scopes, positions, rendering

- **Scope:** `frontend` (default), `admin`, or `both`. Admin assets render only
  in the admin (via the Filament head/body hooks); frontend assets only on the
  site.
- **Position:** `head` or `footer` (styles default head, scripts/modules default
  footer). The theme's `render_frontend_styles()` emits the head bucket and
  `render_frontend_scripts()` the footer bucket, so a head-positioned script
  renders in the head.
- **Inline assets** stand alone in a bucket or attach `before` / `after` a target
  handle.

### Security

- **`src`:** relative and same-origin absolute URLs are allowed; external URLs
  must be **HTTPS** on the trusted allowlist (`cdn.jsdelivr.net`,
  `cdnjs.cloudflare.com`, `unpkg.com`, `fonts.googleapis.com`,
  `fonts.gstatic.com`, `esm.sh`). `javascript:`, `vbscript:`, `data:`, `blob:`,
  `file:` and protocol-relative `//` are rejected.
- **Attributes** are allow-listed per type (scripts: `defer`, `async`, `type`,
  `crossorigin`, `integrity`, `referrerpolicy`, `nomodule`, `id`, `nonce`;
  styles: `media`, `crossorigin`, `integrity`, `referrerpolicy`, `id`), escaped,
  and any `on*` handler rejects the registration.
- **Inline JS** rejects `</script`, `eval(`, `new Function(`, `document.write`,
  `javascript:`, `vbscript:`, `data:text/html`, `blob:`, `file:`, and
  event-handler patterns; **inline CSS** rejects `<script`, `javascript:`,
  `expression(`, `behavior:`, and remote `@import`. Trusted-plugin API, not a
  full sanitizer.

Every call returns `true`/`false`; rejected registrations are reported via
`Asset::rejected()`, never render, and rendering never throws. `/cms-health` adds
`asset_registry_ready`, `registered_asset_count`, `enqueued_frontend_asset_count`,
and `enqueued_admin_asset_count` (counts only).

## Script & Asset Diagnostics (v1.0.0-beta.7.1.13.3)

Diagnostics are **passive**: they never change validation or rendering.

### Source metadata

Every registration method on `Script` and `Asset` accepts an **optional trailing
`array $source = []`** with two keys — omit it and the source defaults to
`custom`:

```php
Script::head('gtag', $inline, 10, [
    'source_type' => 'plugin',        // core | settings | theme | plugin | custom
    'source_name' => 'plugin:ecommerce',
]);

Asset::registerScript(
    handle: 'ecommerce.checkout',
    src: plugin_asset('ecommerce', 'js/checkout.js'),
    source: ['source_type' => 'plugin', 'source_name' => 'plugin:ecommerce'],
);
```

Unknown `source_type` values fall back to `custom` and raise a warning; an empty
`source_name` defaults to the type. Names are reduced to short, safe labels.

### Diagnostic snapshots

`Script::diagnostics()` and `Asset::diagnostics()` return **metadata only**:
`registered`, `rendered`, `rejected`, `sources` (counts by type), `plugins`,
`themes`, `warnings`, and duplicates — plus a `settings` breakdown (scripts) or a
`dependencies` breakdown (assets). They never expose script code, JSON-LD,
verification values, embed HTML, or URLs. `Script::warnings()` /
`Asset::warnings()` return short warning strings (duplicate handle/key, missing
dependency, unknown source, rejected reasons).

### Settings loading hook

Inject settings-managed scripts before the stored `scripts.*` settings register:

```php
add_action('cms.scripts.settings.loading', function (\TheNguyen\CMS\Services\ScriptManager $scripts): void {
    $scripts->head('my-plugin-inline', $inline, 10, [
        'source_type' => 'plugin',
        'source_name' => 'plugin:my-plugin',
    ]);
});
```

### Health

`/cms-health` adds counts only: `script_source_count`, `asset_source_count`,
`script_warning_count`, `asset_warning_count`, `plugin_script_sources`,
`theme_script_sources`.

---

_TN CMS Â· <https://tncms.org> Â· support@tncms.org Â· The Nguyen Media._
