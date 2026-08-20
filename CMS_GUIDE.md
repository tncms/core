# TheNguyen CMS Setup Guide

A practical, step-by-step guide to installing, deploying, and operating
**TheNguyen CMS** (`laravel-cms`).

This document is intentionally beginner-friendly. If you have not used
Laravel or Filament before, you should still be able to get a running
admin panel by following along.

> **Companion documents**
>
> - [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md) — **the single source of
>   truth.** Code-verified architecture (schema, services, routes, theme /
>   menu / media / frontend systems) that separates IMPLEMENTED from
>   PLANNED. When any other doc disagrees with it, it wins.
> - [`README.md`](README.md) — short reference.
> - [`CMS_STRUCTURE.md`](CMS_STRUCTURE.md) — deeper design notes and roadmap.
> - [`CMS_CHANGELOG.md`](CMS_CHANGELOG.md) — versioned history.

This guide covers **TN CMS v1.0.0-beta.7.1.6 — Admin Post Taxonomy Strict Locale**.
For building themes, see [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md); for
building plugins, see [`PLUGIN_DEVELOPMENT.md`](PLUGIN_DEVELOPMENT.md).

> **Admin language (1.0.0-beta.5.1; finalized in beta.7.1.10.2).** TN CMS keeps three
> independent locales:
>
> | Locale | What it controls | Signal | Stored as |
> | --- | --- | --- | --- |
> | **Admin UI locale** (`app()->getLocale()`) | menus, buttons, labels, validation, Filament chrome | `?lang=xx` | `users.admin_locale` + session |
> | **Content editing locale** (`editing_locale()`) | post/page/term/menu/widget/settings translations being edited | `?locale=xx` | `users.editing_locale` + session |
> | **Frontend locale** | public site language | route `{locale}` | `users.frontend_locale` + session |
>
> The whole admin UI — TN CMS strings, Filament's built-in buttons
> (Create/Save/Delete/Search/pagination), and validation — follows the **admin UI
> locale**, chosen with the topbar language switcher (`?lang=xx`) and remembered per
> user, falling back to the **CMS default language**. The content you edit follows the
> separate **content editing locale** (`?locale=xx`): opening a Vietnamese post while
> the interface stays English does not switch the menus to Vietnamese (and vice versa).
> The two switchers preserve each other's parameter. Set the default language under
> **CMS → Languages** (mark a language default). Core admin strings ship fully
> translated for `vi`/`en`; use **CMS → Languages → Sync Translation Files** to scaffold
> `{}` files for additional locales, then fill `lang/{locale}.json`.

---

## Requirements

| Component   | Minimum                | Recommended                        |
| ----------- | ---------------------- | ---------------------------------- |
| PHP         | 8.2                    | 8.3+                               |
| Composer    | 2.5                    | 2.9 or newer                       |
| MySQL       | 8.0                    | 8.0+ (or MariaDB 10.6+)            |
| Node.js     | _(not required yet)_   | 20 LTS+ when frontend builds land  |
| Webserver   | Apache 2.4 with `mod_rewrite`, or Nginx 1.20+, or OpenLiteSpeed 1.7+ | Same |
| Disk space  | 500 MB                 | 2 GB+ for vendor + uploads         |

### Required PHP extensions

`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd` _(or `imagick`)_,
`json`, `mbstring`, `mysqli` _(or `pdo_mysql`)_, `openssl`, `pcre`,
`tokenizer`, `xml`, `zip`.

On most cPanel/DirectAdmin hosts these are already enabled; if not, the
host's "PHP extensions" panel can toggle them.

### Supported local stacks

- **Laragon** on Windows (used in development).
- **XAMPP** on Windows / macOS / Linux.
- **MAMP / MAMP Pro** on macOS.
- **Laravel Herd** on macOS / Windows.
- **Native** PHP-FPM + Nginx on Linux.
- Any **Docker** environment that exposes Laravel via `/public`.

---

## Local Development Setup (Laragon)

This is the supported local environment. Output paths in examples assume
`C:\laragon\www\laravel-cms`; adapt as needed.

### 1. Create the project folder

```bash
# Laragon's "www" auto-virtual-hosts the project as http://laravel-cms.demo
cd C:\laragon\www
# the project already exists; if cloning fresh:
# git clone <repo-url> laravel-cms
cd laravel-cms
```

### 2. Create the database

Open Laragon -> **Menu** -> **MySQL** -> **HeidiSQL** (or any client) and
create:

```sql
CREATE DATABASE `laravel-cms`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
```

Default Laragon credentials: `root` / _empty password_.

### 3. Confirm the virtual host

Laragon auto-creates `http://laravel-cms.demo` from the folder name.

- Right-click the Laragon tray -> **Apache** -> **sites-enabled** -> open
  `auto.laravel-cms.test.conf` (or `.demo.conf`, depending on Laragon's
  auto-host setting).
- Verify `DocumentRoot` points to **`C:/laragon/www/laravel-cms/public`**.

If Laragon set the document root to the project root (not `public`),
right-click Laragon -> **Preferences** -> **Apache** -> enable
"_Document root = public_". Restart Laragon.

### 4. `.env`

```bash
copy .env.example .env
```

Then edit:

```dotenv
APP_NAME="TheNguyen CMS"
APP_URL=http://laravel-cms.demo

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel-cms
DB_USERNAME=root
DB_PASSWORD=

CMS_NAME="TheNguyen CMS"
CMS_ACTIVE_THEME=default
CMS_DEPLOYMENT_MODE=public_root

# Public content resolution cache TTL in seconds (default 3600). Set to 0 to disable.
CMS_PUBLIC_CACHE_TTL=3600
```

#### Public content cache & archive performance (1.0.0-beta.6.3)

The public frontend caches resolved URL → `cms_slugs` reference lookups so
repeated guest hits skip the slug query. Tune it with `CMS_PUBLIC_CACHE_TTL`
(seconds; `0` disables the cache). The cache is metadata-only (it never stores
HTML), skips authenticated/preview requests, and self-invalidates on every
content/term/slug/menu/settings write. Force a clear in code with
`public_content_cache()->flush()`.

Term and tag archives paginate using the `reading.posts_per_page` setting
(the same "Số bài viết mỗi trang" used by the homepage/blog listing; default 10,
floored at 1, hard-capped at 100), so a category with thousands of posts no
longer loads them all at once — `?page=N` walks the pages. As of
**1.0.0-beta.7.1.8** the default theme actually renders the pagination control
(`theme::partials.pagination`), preserving the query string on each link; before
that the controller paginated but the view never emitted the links.
`/cms-health` reports `public_cache_ready`, `public_cache_enabled`,
`public_cache_ttl`, `public_cache_version`, `settings_hot_path_optimized`, and
`taxonomy_archive_pagination_ready`.

### 5. Install dependencies and migrate

```bash
composer install
php artisan key:generate
php artisan migrate
```

### 6. Create the first admin user

Filament ships an interactive command:

```bash
php artisan make:filament-user
```

You will be prompted for **Name**, **Email**, **Password**.

### 7. Verify

```bash
php artisan optimize:clear
php artisan route:list   # /admin and /cms-health should appear
php artisan about
```

Open:

- <http://laravel-cms.demo/admin> -> Filament login -> dashboard.
- <http://laravel-cms.demo/cms-health> -> JSON status payload.

---

## Manual Installation (Shared Hosting)

The CMS supports two shared-hosting layouts. Pick the one your host
allows.

### Mode A — Core outside `public_html` (recommended)

Use this when your host lets you set the document root for an addon or
subdomain (cPanel almost always allows this).

```
/home/your-user/
├── laravel-cms/        full project (app, bootstrap, vendor, ...)
└── public_html/        contents of laravel-cms/public
```

**Steps**

1. SSH or use cPanel File Manager.
2. Upload the **entire project except the contents of `public/`** to
   `/home/your-user/laravel-cms/`.
3. Upload the **contents of `public/`** (not the folder itself) to
   `/home/your-user/public_html/`.
4. Edit `public_html/index.php`. Change:

   ```php
   require __DIR__.'/../vendor/autoload.php';
   $app = require_once __DIR__.'/../bootstrap/app.php';
   ```

   to:

   ```php
   require __DIR__.'/../laravel-cms/vendor/autoload.php';
   $app = require_once __DIR__.'/../laravel-cms/bootstrap/app.php';
   ```

   Some Laravel 12 builds use `bootstrap/app.php`; adjust paths so they
   point at `/home/your-user/laravel-cms/...`.

5. Upload your `.env` to `/home/your-user/laravel-cms/.env`.
6. Set:

   ```dotenv
   APP_URL=https://yourdomain.com
   CMS_DEPLOYMENT_MODE=public_root
   ```

7. Make `storage/` and `bootstrap/cache/` writable:

   ```bash
   chmod -R 775 /home/your-user/laravel-cms/storage
   chmod -R 775 /home/your-user/laravel-cms/bootstrap/cache
   ```

8. SSH into the project (or use a "Composer for cPanel" UI):

   ```bash
   cd /home/your-user/laravel-cms
   composer install --no-dev --optimize-autoloader
   php artisan key:generate          # only if .env has no APP_KEY yet
   php artisan migrate --force
   php artisan optimize:clear
   php artisan make:filament-user
   ```

9. Visit `https://yourdomain.com/admin`.

**`.htaccess`**

You do **not** need the file from `deployment/apache/` for Mode A. The
default `public/.htaccess` shipped by Laravel is enough because the
document root already points at `public/`.

**Permissions**

| Path                            | Mode | Notes                            |
| ------------------------------- | ---- | -------------------------------- |
| `laravel-cms/storage/`          | 775  | Recursive. PHP must write here.  |
| `laravel-cms/bootstrap/cache/`  | 775  | Recursive.                       |
| `public_html/uploads/`          | 775  | If using local uploads disk.     |
| `.env`                          | 600  | Never world-readable.            |

### Mode B — Full core inside `public_html` (no document-root control)

Use this when the host forces the document root to be `public_html` and
nothing else.

```
/home/your-user/public_html/
├── app/
├── bootstrap/
├── vendor/
├── public/
├── ...
└── .htaccess          (from deployment/apache/root-public-html.htaccess)
```

**Steps**

1. Upload the **entire project** (every file) into `public_html/`.
2. Copy the file `deployment/apache/root-public-html.htaccess` to
   `public_html/.htaccess`. Leave `public_html/public/.htaccess`
   untouched.
3. Upload `.env` to `public_html/.env`.
4. Set:

   ```dotenv
   APP_URL=https://yourdomain.com
   CMS_DEPLOYMENT_MODE=public_html_rewrite
   ```

5. Permissions as in Mode A (`storage/`, `bootstrap/cache/`).
6. Composer + migrate:

   ```bash
   cd /home/your-user/public_html
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan optimize:clear
   php artisan make:filament-user
   ```

7. Visit `https://yourdomain.com/admin`.

**What the root `.htaccess` does**

- Blocks direct access to `.env`, `artisan`, `composer.json`,
  `composer.lock`, `package.json`, `package-lock.json`, `phpunit.xml`.
- Blocks direct access to `app`, `bootstrap`, `config`, `database`,
  `modules`, `packages`, `themes`, `vendor`, `storage`, `tests`,
  `resources`, `routes`.
- Serves real files from `/public` when they exist.
- Rewrites everything else into `/public/index.php`.

> **Uploads.** Inside Mode B, uploads still live at
> `public_html/public/uploads`. The rewrite rules make
> `https://yourdomain.com/uploads/foo.jpg` resolve to that path
> transparently. Do not move uploads to `public_html/uploads`.

---

## VPS Installation (Nginx)

The reference VPS layout. Replace `example.com` and `/var/www/laravel-cms`
to taste.

### 1. Upload / clone

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone <repo-url> laravel-cms
sudo chown -R deploy:www-data /var/www/laravel-cms
cd laravel-cms
```

### 2. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=laravel_cms
DB_USERNAME=laravel_cms
DB_PASSWORD=secret

CMS_DEPLOYMENT_MODE=public_root
```

### 3. Permissions

```bash
sudo chown -R deploy:www-data /var/www/laravel-cms
sudo find /var/www/laravel-cms -type d -exec chmod 755 {} \;
sudo find /var/www/laravel-cms -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/laravel-cms/storage
sudo chmod -R 775 /var/www/laravel-cms/bootstrap/cache
sudo chmod 600 /var/www/laravel-cms/.env
```

### 4. Migrate + first user

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan make:filament-user
```

### 5. Nginx config

Copy `deployment/nginx/public-root.conf` (Mode A — recommended) to
`/etc/nginx/sites-available/laravel-cms`. Edit `server_name`,
`root`, and `fastcgi_pass`:

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    root /var/www/laravel-cms/public;
    index index.php index.html;
    # ... (full template in deployment/nginx/public-root.conf)
}
```

Enable + reload:

```bash
sudo ln -s /etc/nginx/sites-available/laravel-cms /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6. PHP-FPM

Verify the PHP-FPM socket your template references:

```bash
ls /var/run/php/
# e.g. php8.3-fpm.sock
```

Update `fastcgi_pass` accordingly:

```nginx
fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
```

Restart:

```bash
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

### 7. HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d example.com -d www.example.com
```

### Alternative — Nginx Mode B (root -> project root)

If policy forces the document root to be the project root (not
`/public`), use `deployment/nginx/public-html-rewrite.conf` instead and
set `CMS_DEPLOYMENT_MODE=public_html_rewrite`.

---

## Apache Installation

Apache deployment is similar to Nginx Mode A. The key requirements:

- Apache 2.4+ with `mod_rewrite` enabled.
- A virtual host whose `DocumentRoot` is the project's `/public`
  directory.
- `AllowOverride All` on that directory so the bundled
  `public/.htaccess` is honoured.

### Virtual host example

```apacheconf
<VirtualHost *:80>
    ServerName example.com
    ServerAlias www.example.com
    DocumentRoot /var/www/laravel-cms/public

    <Directory /var/www/laravel-cms/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/laravel-cms-error.log
    CustomLog ${APACHE_LOG_DIR}/laravel-cms-access.log combined
</VirtualHost>
```

Enable the site + module:

```bash
sudo a2enmod rewrite
sudo a2ensite laravel-cms
sudo systemctl reload apache2
```

If you cannot point the document root at `/public`, use **Mode B**:
point Apache at the project root and drop
`deployment/apache/root-public-html.htaccess` as `.htaccess` in the
project root. Set `CMS_DEPLOYMENT_MODE=public_html_rewrite`.

---

## OpenLiteSpeed Installation

OpenLiteSpeed (and LiteSpeed Enterprise) respects `.htaccess` files when
"_Allow Override_" is enabled on the document context.

### Mode A — document root -> `/public`

1. In the **WebAdmin Console** -> **Virtual Hosts** -> **Context** or
   the default vhost, set:
   - `Document Root`: `/var/www/laravel-cms/public`
   - `Index Files`: `index.php index.html`
2. **Rewrite** tab:
   - `Enable Rewrite`: Yes
   - `Auto Load from .htaccess`: Yes
3. Restart OpenLiteSpeed.
4. Browse to `https://example.com/admin`.

The bundled `public/.htaccess` does the rest.

### Mode B — document root -> project root

1. Set `Document Root` to the project root, e.g.
   `/var/www/laravel-cms`.
2. Copy `deployment/apache/root-public-html.htaccess` to
   `/var/www/laravel-cms/.htaccess`.
3. Same rewrite settings as Mode A.
4. Set `CMS_DEPLOYMENT_MODE=public_html_rewrite` in `.env`.

### LSPHP

Pick the matching `LSPHP` external app in the OpenLiteSpeed admin
(usually `lsphp83` for PHP 8.3). Make sure the configured PHP version
has the required extensions enabled.

---

## Database Import / Export

### phpMyAdmin (shared hosting)

- **Export:** select the database -> **Export** -> Format: SQL ->
  **Custom** -> tick "Add `DROP TABLE / VIEW / PROCEDURE / FUNCTION /
  EVENT / TRIGGER` statement" if you want a clean restore.
- **Import:** create an empty database with the same charset -> **Import**
  -> upload the `.sql` file.

### MySQL CLI

```bash
# Dump
mysqldump -u root -p \
  --single-transaction --quick \
  --default-character-set=utf8mb4 \
  laravel-cms > laravel-cms-$(date +%F).sql

# Restore
mysql -u root -p laravel-cms < laravel-cms-2026-05-28.sql
```

### From Laragon to a VPS

```bash
# On local
mysqldump -u root laravel-cms > dump.sql
scp dump.sql deploy@example.com:/tmp/

# On VPS
mysql -u laravel_cms -p laravel_cms < /tmp/dump.sql
```

After import, run on the VPS:

```bash
cd /var/www/laravel-cms
php artisan migrate --force   # apply any pending migrations
php artisan optimize:clear
```

---

## Updating the CMS

```bash
cd /var/www/laravel-cms

# 1. pull / upload new code
git pull            # or rsync, or upload via SFTP

# 2. update dependencies
composer install --no-dev --optimize-autoloader

# 3. apply schema changes
php artisan migrate --force

# 4. blow away stale caches
php artisan optimize:clear

# 5. rebuild caches (production only)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. (optional) reload PHP-FPM so opcache picks up new files
sudo systemctl reload php8.3-fpm
```

> **Never** run `config:cache` / `route:cache` on a dev machine where
> `.env` is volatile — cached config files freeze the values at the
> moment they were generated.

---

## Common Commands

| Command                                  | What it does                                    |
| ---------------------------------------- | ----------------------------------------------- |
| `composer install`                       | Install PHP deps from `composer.lock`.          |
| `composer dump-autoload`                 | Rebuild PSR-4 class map after adding namespaces.|
| `composer update`                        | Upgrade PHP deps according to `composer.json`.  |
| `php artisan optimize:clear`             | Clear config / route / view / event caches.    |
| `php artisan migrate`                    | Run pending migrations.                         |
| `php artisan migrate --force`            | Run migrations in production.                  |
| `php artisan migrate:fresh --seed`       | **Destructive.** Drop all tables, re-migrate, seed. |
| `php artisan route:list`                 | List all registered routes.                     |
| `php artisan about`                      | Show Laravel + Filament + driver summary.       |
| `php artisan make:filament-user`         | Create / promote a Filament admin user.         |
| `php artisan tinker`                     | REPL.                                           |
| `php artisan storage:link`               | Symlink `public/storage -> storage/app/public`. |
| `php artisan vendor:publish --tag=cms-config` | Publish the CMS config to `config/cms.php`. |
| `php artisan cms:settings-clear`         | Clear the CMS settings autoload cache.          |
| `php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder"` | Seed the default CMS settings. |
| `php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"` | Seed core taxonomies + sample page/post. |

---

## Settings Manager

The Settings Manager (shipped in `0.2.0`) is the CMS-wide key-value
store. It is backed by the `cms_settings` table, cached aggressively,
and editable from `/admin/settings`.

### 1. Run the migration

The migration lives inside the CMS core package and is auto-loaded by
`CmsServiceProvider`:

```bash
php artisan migrate
```

You should see `2026_05_28_000001_create_cms_settings_table` run.

### 2. Seed default settings

```bash
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder"
```

This populates sensible defaults for `general.*`, `seo.*`, `admin.*`,
`system.*`, and `theme.*`. The seeder is idempotent — existing rows are
not overwritten.

### 3. Edit settings in the admin

Log in to `/admin` and open **CMS → Settings** in the sidebar
(`/admin/settings`). Since **0.9.6** the page is **tabbed**:

- **General** — site name, tagline, description, admin email, timezone,
  date/time formats, and a **favicon** image picker (rendered as
  `<link rel="icon">` by the default theme; the tagline shows under the
  site brand).
- **Reading** — homepage display (your latest posts / a static page) with
  the homepage & posts-page selectors, posts-per-page, feed item count/mode,
  and the **search-engine visibility** toggle (`seo.noindex_site`).
- **Writing** — default post status, comment status, category, authoring
  language, and post format applied to **new** posts/translations.
- **Media** — organize uploads by date, max upload size (MB), auto
  alt/title from filename, the thumbnail/medium/large size fields, and
  **custom image sizes** (stored for future use — no resizing yet).
- **SEO** — title separator, default meta title/description, default OG
  image, and the default robots directive.
- **Permalinks** — post / category / tag URL bases (defaults `blog` /
  `category` / `tag`). **After changing a base run `php artisan route:clear`**
  so the frontend routes pick it up (saving also rebuilds `cms_slugs` so
  existing links update). Bases must be lowercase, slash-free, not a reserved
  prefix, and unique. **Leave a base empty for base-less URLs** — e.g. an empty
  post base serves posts at `/post-slug` instead of `/blog/post-slug`; the
  record then resolves through the generic `/{slug}` route via `cms_slugs`.
  Because `/{slug}` must be unambiguous, public slugs are **globally unique per
  language** across pages/posts/categories/tags — a conflicting slug
  auto-increments (`slug-2`, `slug-3`, …) on save.

Click **Save settings**. The save handler clears the autoload cache
automatically. (The Theme Manager — shipped in `0.6.0` — owns the
active-theme choice; see **Theme Manager** below.)

### 4. Clear the settings cache manually

If you change settings outside the admin (raw SQL, tinker, a future
import tool), bust the cache:

```bash
php artisan cms:settings-clear
```

### 5. Read settings from code

```php
// Helper (preferred in views and controllers)
settings('general.site_name');
settings('general.site_name', 'Fallback');

// Facade
use TheNguyen\CMS\Facades\Settings;

Settings::get('general.site_name');
Settings::set('admin.brand_name', 'Acme Co');
Settings::all();
Settings::group('seo');
Settings::has('seo.default_title');
Settings::forget('plugin.legacy_flag');

// Localized settings (beta.6.2) — per-language values with graceful fallback
setting_localized('general.site_name');          // current request locale
setting_localized('seo.default_meta_title', 'en'); // explicit locale
Settings::getLocalized('maintenance.title', 'vi');
Settings::setLocalized('general.site_tagline', 'en', 'Open-source Laravel CMS');
```

Translatable keys (`general.site_name`, `general.site_tagline`,
`general.site_description`, `seo.default_meta_title`,
`seo.default_meta_description`, `maintenance.title`, `maintenance.message`)
store one value per language in `cms_settings_translate`. `setting_localized()`
resolves requested locale → CMS default locale → global `cms_settings` →
provided default and never throws. `/admin/settings` has a language switcher
that loads and saves one locale at a time; permalinks stay global.

Keys use dot notation: `group.key`. Supported types are `string`,
`text`, `boolean`, `integer`, `float`, `array`, `json`. The manager
infers the type when you do not pass one to `set()`.

### Troubleshooting settings

#### `/cms-health` shows `"settings_loaded": false`

The `cms_settings` table is missing. Run:

```bash
php artisan migrate
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder"
```

The health endpoint is intentionally tolerant — it returns `200` with
`settings_loaded: false` rather than 500 when the table is missing.

#### Settings changes are not visible in the frontend

The autoload cache is sticky by design. Run:

```bash
php artisan cms:settings-clear
```

Or, in tinker:

```php
TheNguyen\CMS\Facades\Settings::clearCache();
```

#### `settings()` returns null after composer dump

The helper is autoloaded from `composer.json` `autoload.files`. After
adding the entry, you must run:

```bash
composer dump-autoload
```

If it still returns `null`, confirm the database is reachable —
`SettingsManager::get()` falls back to the default when the
`cms_settings` table cannot be queried.

#### Admin page `/admin/settings` 404s

The page is auto-discovered by the existing `admin` panel. Confirm:

```bash
php artisan route:list | findstr settings    # Windows
php artisan route:list | grep settings       # Linux/macOS
```

If the route is missing, clear caches:

```bash
php artisan optimize:clear
```

---

## Content Core

The Content Core (shipped in `0.3.0`) is the unified engine that backs
**Pages** and **Posts**, **Categories**, **Tags**, **Taxonomies**, and
the slug router. Pages and Posts share `cms_contents` but appear as
two separate admin menus.

### 1. Run the content migrations

The 7 content migrations ship inside the CMS core package and load
automatically:

```bash
php artisan migrate
```

You should see:

```
2026_05_28_000002_create_cms_contents_table              DONE
2026_05_28_000003_create_cms_content_translations_table  DONE
2026_05_28_000004_create_cms_taxonomies_table            DONE
2026_05_28_000005_create_cms_terms_table                 DONE
2026_05_28_000006_create_cms_term_translations_table     DONE
2026_05_28_000007_create_cms_content_terms_table         DONE
2026_05_28_000008_create_cms_slugs_table                 DONE
```

### 2. Seed core taxonomies + sample content

```bash
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"
```

This is idempotent. It creates the `category` and `tag` taxonomies
for posts (marked `is_core=true`), plus a home page (`Trang chủ`),
a sample post (`Bài viết đầu tiên`), one category (`Tin tức`), and
one tag (`Laravel`). The sample post is auto-attached to the
category and tag.

### 3. Create pages and posts in the admin

Log in to `/admin`. In the **Content** navigation group:

- **Pages** → list at `/admin/pages`. Click **Add New Page** for a
  WordPress-style create button.
- **Posts** → list at `/admin/posts`. Click **Add New Post**.
  Includes excerpt + multi-select for categories and tags.
- Under **Posts**: **Categories**, **Tags**, **Taxonomies**.

Each form has a title, slug, content, status, publish date, and
SEO meta fields. Posts also have an excerpt and category/tag picker.
Pages also have a template name and featured image URL.

The post category/tag picker is **strict per-locale** (v1.0.0-beta.7.1.6):
when you switch the editor's language, the Categories list and Tag chips show
only terms translated in that locale — a category translated only in Vietnamese
does not appear with its Vietnamese label while editing in English. Such
untranslated assignments are kept behind the scenes, so saving in one locale
never drops a term that belongs to another.

### 4. How slug generation works

Slugs are generated per locale through `TheNguyen\CMS\Services\SlugManager`.
Default locale is `vi`.

| Input | Output |
| ----- | ------ |
| `Thiết kế website chuẩn SEO` (vi) | `thiet-ke-website-chuan-seo` |
| `Đặng Văn Lâm` (vi) | `dang-van-lam` |
| `Tối ưu hóa tốc độ` (vi) | `toi-uu-hoa-toc-do` |
| `Hello World` (en) | `hello-world` |
| `こんにちは 世界` (ja) | `こんにちは-世界` |

You can also call the helper:

```php
cms_slug('Bài viết đầu tiên');           // bai-viet-dau-tien
cms_slug('Hello World', 'en');           // hello-world
cms_slug('こんにちは 世界', 'ja');         // こんにちは-世界
```

If you leave the slug field empty in the admin form, the service
generates one from the title. If you provide a slug, it is still
passed through the same normalization rules.

Uniqueness is per locale: a collision appends `-2`, `-3`, … until
the slug is unique.

**Slugs are locale-specific (beta.6.1).** Each translation
(`cms_content_translations` / `cms_term_translations`) owns its own `slug`,
unique per `(locale, slug)`. `vi: home` and `en: home` can coexist; editing one
locale's slug never changes another's. A same-locale conflict — or a reserved
prefix (`admin`, `install`, `themes`, …) — auto-increments (`home-2`). The
parent `cms_contents` / `cms_terms` tables carry **no** slug column.

`cms_slugs` is the public-URL resolver: one row per `(reference_type,
reference_id, locale)` holding `slug` + `prefix` + `full_path`. The frontend
resolves a request by `findPublic($fullPath, $currentLocale)`, so `/en/trang-chu`
404s unless `trang-chu` is genuinely the English slug. `cms_slugs` is rebuilt on
every content/term save; to repair stale rows run:

```bash
php artisan tncms:slugs:rebuild
```

### 5. Programmatic content / term writes

```php
use TheNguyen\CMS\Facades\Content;
use TheNguyen\CMS\Facades\Taxonomy;

$post = Content::create([
    'type' => 'post',
    'status' => 'published',
    'locale' => 'vi',
    'title' => 'Bài viết mới',
    'content' => '...',
    'term_ids' => [1, 2],
]);

Content::update($post, [
    'status' => 'draft',
    'content' => 'Updated content',
]);

$category = Taxonomy::createTerm('category', [
    'locale' => 'vi',
    'name' => 'Công nghệ',
]);
```

All writes happen inside a DB transaction and update the routing
entry in `cms_slugs` automatically.

### 6. Multi-language posture

Languages are managed in **Admin → CMS → Languages** (`cms_languages`), seeded
with `vi` (default) + `en`. The **default language** drives URL prefixing and
the content fallback locale; mark a language default to change it. A frontend
language switcher is available via the `language_switcher()` helper.

**Locale resolution (beta.6 / B1, B8).** Content/term/menu reads and writes, and
the `cms_slug()` / `menu()` helpers, resolve the locale through the explicit
argument → `current_locale()` → the **CMS default language** (`defaultCode()`).
Nothing falls back to a hardcoded `'vi'` or to `config('app.locale')`, so the
CMS behaves correctly when the default language is changed (e.g. to `en`) or
when the framework locale differs from the CMS locale.

**Language codes (beta.6 / B2).** Codes are stored lowercase and must match
`^[a-z]{2}(-[a-z]{2})?$` (e.g. `vi`, `en`, `pt-br`). The form normalizes the
input before validation, so re-adding an existing code in a different case
(`EN` when `en` exists) fails with a validation error instead of a 500.

**Deleting a language (beta.6 / B3).** Deletion is **refused** (non-destructively)
for the default language, the last remaining language, or any language that
still owns translated content/terms/menus or `cms_slugs` rows — the admin shows
the reason. Remove or reassign that locale's data first. (Cascade delete is not
implemented; it is deferred to the localized-slug work.)

**Route cache (beta.6 / B5).** Localized routes (`/{locale}/…`) are constrained
to the active language codes at route-registration time, so creating, editing,
or deleting a language clears the route cache automatically. If the automatic
clear fails, run `php artisan route:clear`.

### Troubleshooting content

#### `/cms-health` shows `"content_tables_ready": false`

One or more of the 7 content tables is missing. Run:

```bash
php artisan migrate
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"
```

#### `SQLSTATE[23000]` / Integrity constraint violation: duplicate slug

You have two records with the same slug in the same locale. Either:

- Change one of the slugs in the admin form, or
- Inspect the conflicting row:
  ```php
  TheNguyen\CMS\Models\ContentTranslation::where('locale','vi')
      ->where('slug','my-slug')
      ->get();
  ```

`SlugManager::uniqueContentSlug()` normally prevents this by
appending a numeric suffix, so a raw duplicate usually means a
direct DB write bypassed the service.

#### Admin form is missing the title or content I saved

The `EditRecord` page reads from the matching `vi` translation. If
the translation row is missing (e.g. an SQL-level insert created a
content row without a translation), the form will look empty even
though the content row exists. Fix:

```php
TheNguyen\CMS\Facades\Content::update($content, [
    'locale' => 'vi',
    'title' => 'Recovered title',
]);
```

#### Slug for Japanese / Chinese / Arabic comes out empty

Make sure the input string is not whitespace-only. The unicode-safe
slug path strips punctuation, collapses whitespace, and lowercases,
but it preserves CJK / Arabic / Hebrew / Thai / Cyrillic / Greek /
Devanagari letters. Empty input falls back to `content-{timestamp}`.

#### `/admin/pages` 404s

The Filament resource is auto-discovered under
`app/Filament/Admin/Resources/`. After adding a new resource file,
clear the route cache:

```bash
php artisan optimize:clear
```

Then verify:

```bash
php artisan route:list | grep admin/pages
```

---

## Media Core

Added in **0.4.0**. A minimal media library: upload files, browse them,
preview images, copy URLs, edit metadata, and delete. As of **0.4.1** a
small **Media Picker** lets you choose a Page/Post **featured image**
from existing media (see *Featured image* below). It still does **not**
include editor integration — you cannot embed media into the content
body from the editor in this version.

### Using the media library

Log in to `/admin`. In the **Media** navigation group:

- **Library** → `/admin/media`. Browse all uploaded files. Each row
  shows a thumbnail (or a document icon for non-images), the original
  filename, **alt text**, **title**, a **caption** indicator, type,
  human-readable size, dimensions, and upload date. The search box spans
  filename, alt, title, caption, and description. Filters: **Images**,
  **Documents**, **Missing alt text**, **Missing title**, **Missing
  caption** (handy for SEO clean-up). Row actions: **View**, **Edit**,
  **Delete**. Bulk-select rows to delete several at once.
- **Upload** → `/admin/media/upload`. Drag and drop or pick up to **20**
  files at a time, then click **Upload**. Supported types by default:
  images (**JPG, PNG, GIF, WebP, AVIF**) and **PDF**. A success
  notification confirms how many files were stored. See *Upload security*
  below to widen or narrow the accepted types.

To get a file's public URL, open it via **View** or **Edit** — the
**URL** field shows the full link you can copy.

### Where files are stored

Uploads are written to `public/uploads/YYYY/MM`
(e.g. `public/uploads/2026/05`). Each file keeps its original name in
the database; the on-disk filename is a slug of the original name plus a
short random suffix to prevent collisions. For raster images, width and
height are detected automatically. Deleting a file (single or bulk)
removes the physical file from disk and soft-deletes the database
record.

### Upload security (1.0.0-beta.6.4)

Every upload — from the media library, the media picker, and the rich
editor — is validated server-side in one place (`MediaManager::upload()`).
The browser-side file filter is only a convenience; the server is the
authority. Policy lives in `config/cms.php` under `media`:

| Key | Purpose | Default |
| --- | --- | --- |
| `allowed_extensions` | Extension allow-list | `jpg, jpeg, png, gif, webp, avif, pdf` |
| `allowed_mimes` | Real-MIME allow-list (`finfo`) | matching image + pdf MIMEs |
| `denied_extensions` | Always-on hard block | `php*, phtml, phar, exe, bat, sh, js, html, …` |
| `max_upload_size` | Hard byte ceiling (`CMS_MEDIA_MAX_UPLOAD_SIZE`) | `8 MB` |
| `allow_svg` | Opt-in, sanitized SVG (`CMS_MEDIA_ALLOW_SVG`) | `false` |

A file is rejected unless its extension is allowed (and not on the hard
deny-list) **and** its real detected MIME type is on the allow-list — so
a PHP payload renamed to `evil.jpg` is blocked even though `.jpg` is
allowed. The effective size limit is the smaller of `max_upload_size` and
the **Media → Max upload size (MB)** admin setting.

**To accept more types** (e.g. DOC/DOCX/ZIP/TXT, which are no longer
accepted by default), add both the extension to `allowed_extensions` and
its MIME to `allowed_mimes`. **SVG** is special: set `allow_svg` to `true`
*and* add `svg` to `allowed_extensions`; every uploaded SVG is then
sanitized (scripts, event handlers, and `javascript:`/`data:` URLs
removed) before it is written to disk.

### Editing metadata (Media Metadata Manager — 0.9.3)

Open a file and choose **Edit**. The form is split into a **Metadata**
column and a sidebar:

- **Alt text** — *Describe the image for accessibility and SEO.* On upload
  this is **auto-filled** from the humanized filename (e.g. `nguoi-dep.jpg`
  → `Nguoi dep`) when you don't provide one; edit it to something
  descriptive.
- **Title** — *Optional title attribute.*
- **Caption** — *Shown under images when inserted as figure.*
- **Description** — *Internal/media library description.*
- Sidebar: image **preview**, a **copyable URL** (Copy button), and
  read-only file info (filenames, type, size, dimensions).

The file itself cannot be replaced from this page — to change the file,
upload a new one and delete the old record. When you insert an image from
the **Insert Media** modal in the rich editor, its Alt/Title default from
this metadata and the Caption is pre-filled, so good metadata pays off
across the site.

**Per-item actions (edit page header — 0.9.5).** Two buttons sit in the edit
page header:

- **Generate alt from filename** — fills Alt from the humanized filename, but
  only when Alt is currently empty (it won't overwrite existing alt; clear it
  first to regenerate).
- **Clear metadata** — wipes Alt/Title/Caption/Description for this item
  (asks for confirmation; the file is untouched).

### Bulk metadata actions (Media UX Polish — 0.9.5)

On the `/admin/media` table, select rows and use the toolbar:

- **Generate alt from filename** — for each selected item, fills Alt from the
  filename **only where Alt is empty**. Existing alt text is never
  overwritten; a notification reports how many were updated.
- **Clear metadata** — sets Alt/Title/Caption/Description to empty for the
  selected items (confirmation required).
- **Delete** — the existing bulk delete (also removes the underlying files).

Searching, filtering, and sorting the table:

- **Search** matches original filename, stored filename, alt, title, caption,
  and description.
- **Filters:** Images, Documents, Missing alt text, Missing title, Missing
  caption.
- **Sort** by clicking the **Filename**, **Size**, or **Uploaded** column
  headers (toggles ascending/descending — covers Newest/Oldest, A–Z/Z–A,
  largest/smallest).

### Verifying media health

`/cms-health` reports the media fields:

```json
{
  "media_count": 0,
  "image_count": 0,
  "media_metadata_ready": true,
  "featured_image_modal_ready": true,
  "media_ux_ready": true
}
```

`media_count` / `image_count` are `null` (never an error) if the
`cms_media` table has not been migrated yet; `media_metadata_ready` is
`true` once the table has the `description` column (0.9.3);
`media_ux_ready` (0.9.5) is `true` when `media_metadata_ready` and
`featured_image_modal_ready` are both true and the shared `MediaItems`
helper is present.

#### `/admin/media` 404s

Same as other resources — clear the route cache and re-check:

```bash
php artisan optimize:clear
php artisan route:list | grep admin/media
```

### Featured image (Media Modal picker — 0.9.4)

The **Featured image** field on Pages and Posts (right-hand **Publish**
section) is a WordPress-like media picker. Since **0.9.4** it replaces the
old dropdown.

**1. No image yet.** You see a "No image selected" block with an **Add
image** button and the helper *"Select or upload an image for this
content."*

**2. Open the picker.** Click **Add image**. A modal — *"Select featured
image"* — opens with two tabs:

- **Media Library** — a grid of your image thumbnails (newest first). The
  grid loads the newest 50, but the search box runs **server-side over your
  whole media library** (since **1.0.0-beta.7.1.8**), so logos/favicons and
  any image imported beyond the first 50 — e.g. after a large WordPress
  import — are findable. Search matches filename, **URL, path**, alt, title,
  caption, and description. Click a thumbnail to select it, and review its
  details (preview, filename, URL, dimensions, alt, title, caption,
  description) in the right panel. Click **Set featured image**. The same
  picker powers the **Theme Options** logo/favicon fields.
- **Upload files** — drag & drop or browse a **JPG, PNG, GIF or WebP**
  (max 8 MB). A note reminds you *"Uploaded image will be used as the
  featured image."* and a **preview appears before you submit**. It is
  uploaded into your media library (with the SEO filename and auto alt from
  v0.9.3) **without leaving the edit form**, and used as the featured image
  when you click **Set featured image** (an upload takes priority over any
  Media Library selection).

**3. Selected state.** The field shows a **preview** plus the metadata we
can resolve from the media library (filename, alt, dimensions) and the URL,
with **Replace image** and **Remove image** buttons.

**4. Replace / Remove.** **Replace image** reopens the modal; **Remove
image** clears the selection (back to the Add image state). Save the
Page/Post to persist. The value stored in `cms_contents.featured_image` is
the image **URL string** (e.g. `/uploads/2026/05/nguoi-dep.jpg`).

> The modal closes on **Escape**, on the close button, or by clicking the
> backdrop; it scrolls internally and is responsive.

#### Troubleshooting the picker

- **No images in the Library tab.** It only lists **image** media. Use the
  **Upload files** tab (or `/admin/media/upload`) to add one. Non-image
  files (PDF, DOC, ZIP, …) never appear.
- **"External or missing media record".** The stored URL is not found in
  `cms_media` (e.g. an externally set URL, or the media row was deleted).
  The preview still loads from the URL; pick or upload an image to replace
  it.
- **Broken preview / broken image icon.** The preview loads the stored URL
  directly. If it is broken, the file is missing on disk or the URL is
  wrong — confirm the file exists under `public/uploads/YYYY/MM`.
- **Wrong public URL.** Media URLs are root-relative (they start with
  `/uploads/...`) and are served straight from `public/uploads`. If images
  404 in the browser, make sure your document root points at the project's
  `public/` directory (Mode A) — see the installation modes above. No
  `storage:link` symlink is required for media.

---

## Menu Manager

Added in **0.5.0**. Menus live under **Appearance → Menus** in the admin.
They are core CMS data: a future Theme Manager will decide *where* each
menu renders (header, footer, sidebar), but the menus themselves are
created and managed here. Menu and item titles are translatable
(default locale `vi`). Since **0.9.2** the menu **item** titles follow the
Menu edit locale: switch the **Language** selector on the menu edit page (or
open it with `?locale=en`) and the item create/edit modal edits that locale's
title (empty when missing, so you can create it without overwriting another
locale). The frontend renders item titles in the current request locale.

### 1. Migrate the menu tables

The four menu tables ship with the core package, so a normal migrate
creates them:

```bash
php artisan migrate
```

This adds `cms_menus`, `cms_menu_translations`, `cms_menu_items`, and
`cms_menu_item_translations`.

### 2. Seed the default menus

```bash
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsMenuSeeder"
```

This creates a **Header Menu** (location `header`) and **Footer Menu**
(location `footer`). If the sample *Trang chủ* page and *Bài viết đầu
tiên* post exist, they are added to the header menu. The seeder is
idempotent — running it again will not create duplicates.

You can confirm via `/cms-health`, which now reports `menus_count` and
`menu_items_count`.

### 3. Create a menu

1. Go to `/admin/menus` and click **Create Menu**.
2. Enter a **Name** (required). Leave **Slug** empty to auto-generate it
   from the name; slugs are made unique automatically.
3. Optionally add a **Description**.
4. In the **Settings** panel choose a **Location** (header / footer /
   sidebar, optional), a **Status** (active / inactive), and a
   **Sort order**.
5. Save. You are taken to the edit page, where you can add items.

### 4. Add menu items

On a menu's edit page, use the **Menu Items** panel → **Add Item**:

- **Title** (required) — the visible label (translatable, `vi`).
- **Type** — `Custom link`, `Page`, `Post`, `Category`, or `Tag`.
  - For **Custom link**, a **URL** field appears and is required
    (e.g. `/lien-he` or `https://example.com`).
  - For **Page / Post / Category / Tag**, a **Linked content** dropdown
    appears listing the matching records by their Vietnamese title; the
    public URL is resolved automatically from the CMS slug table.
- **Open in** — same tab (`_self`) or new tab (`_blank`).
- **Parent item** — choose another item in the same menu to nest under
  it (optional; leave empty for a top-level item).
- **CSS class**, **Icon**, **Sort order**, **Active** — optional
  presentation/ordering controls.

Items are ordered by **Sort order**. Edit or delete an item with the row
actions.

### 5. Assign a header / footer / sidebar location

Open the menu, set **Location** in the **Settings** panel, and save. Only
one active menu per location is resolved by `menu('header')` /
`getMenuByLocation('header')`. To swap which menu is used for a slot,
change the `Location` (and/or `Status`) on the menus involved.

From code you can resolve a menu and walk its tree:

```php
$menu = menu('header');               // by location, then slug
$tree = app('cms.menu')->tree($menu); // nested array: title, url, children
```

> **Note:** there is no frontend rendering yet — `tree()` gives you the
> resolved structure (titles + URLs) to render in a theme later.

### Troubleshooting menus

- **A referenced page/post/category/tag is missing from the dropdown.**
  The dropdown only lists existing records of that type. Create the
  page/post/category/tag first (under **Content**), then reopen the menu
  item form. Category/Tag options come from the `category`/`tag`
  taxonomies — seed core content if the lists are empty
  (`php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"`).
- **A menu item links to `#`.** That happens when a referenced record has
  no slug entry yet, or a custom item has an empty URL. Re-save the
  referenced page/post (which (re)builds its `cms_slugs` row) or set the
  custom item's URL.
- **A deleted page/post still appears as an item.** The item keeps its
  stored `reference_id`; it simply resolves to `#`. Remove the item from
  the menu, or point it at a different record.

---

## Theme Manager

Added in **0.6.0**. Themes live in the project's `themes/` directory.
The Theme Manager discovers them, lets you activate one, and copies a
theme's assets into `public/themes/` so the browser can reach them. The
active theme is remembered in the settings table (`theme.active`) — there
is **no themes database table**.

> **Frontend rendering is not part of this phase.** Activating a theme
> stores the choice and publishes its assets; it does not yet render your
> pages/posts with the theme.

### Anatomy of a theme

A theme is just a folder under `themes/` with a `theme.json`:

```
themes/default/
├── theme.json          # name, slug, version, author, supports…
├── screenshot.png      # preview shown in the admin
├── functions.php       # config-returning file; declares Theme Options schema (loaded safely)
├── assets/             # css/, js/, images/  → published to public/themes/default
└── views/              # layouts/, pages/, posts/, partials/ (placeholders)
```

Minimum `theme.json`:

```json
{
    "name": "Default Theme",
    "slug": "default",
    "version": "1.0.0",
    "author": "The Nguyen Media",
    "author_uri": "https://tncms.org",
    "support_email": "support@tncms.org",
    "description": "Default starter theme for TN CMS.",
    "screenshot": "screenshot.png",
    "supports": { "menus": ["header", "footer"], "theme_options": false, "widgets": false }
}
```

> **Manifest validation (0.9.7):** `name`, `slug`, `version`, and `author` are
> **required** (non-empty). A theme with a missing/invalid `theme.json` is
> skipped and listed under *Invalid themes* on the Themes page. See the full
> theme author guide in [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md).

### Activating a theme in the admin

1. Go to **Appearance → Themes** (`/admin/themes`).
2. Each discovered theme shows as a card with its screenshot, name,
   version, author, and description.
3. The **active** theme shows an **Active** badge and no buttons. Inactive
   valid themes show an **Activate** button.
4. There is **no Deactivate action** — TN CMS always requires exactly one active
   theme. To change themes, click **Activate** on another theme; this *switches*
   `theme.active` to it and publishes its assets. Activation is transactional:
   if the target theme has an invalid manifest, is missing required views, or
   its assets cannot be published, activation fails and the **current theme
   stays active**. If `theme.active` is ever empty, the frontend still renders
   with the *effective* theme (the `default` theme); if no valid theme exists at
   all it shows a 503 "No active theme found" page instead of an error.

### Using the theme from code

```php
theme();                 // ThemeManager instance
theme('default');        // the Theme value object (or null)
theme()->active();       // the active Theme (or null)
theme()->all();          // every discovered Theme
```

Published asset URLs follow `/themes/{slug}/css/app.css`, etc.

### Theme Options (v0.9.9)

A theme can declare admin-editable **options** in its `functions.php`. The CMS
renders them as a form and stores the values in `cms_settings` (no new table).

1. The active theme returns an option schema from `functions.php`:
   `return ['options' => ['sections' => [['key'=>'identity','label'=>'Site
   Identity','fields'=>[['key'=>'logo','label'=>'Logo','type'=>'image']]]]]];`.
   Field types: `text`, `textarea`, `boolean`, `number`, `select`, `image`,
   `color`.
2. Go to **Appearance → Theme Options** (`/admin/theme-options`). The page builds
   a form from the schema, grouped by section. If the active theme declares no
   options it shows a friendly empty state; with no active theme it shows a
   warning (it never errors).
3. Edit values and **Save**. They persist under `cms_settings`
   (`theme_options.{slug}.{key}`) and are cached.
4. Read values in theme views with `theme_option('key', $default)` — it falls
   back to the field's schema default, then `$default`:

```php
theme_option('footer_text');                 // saved value or schema default
theme_option('primary_color', '#2563eb');    // safe fallback
app('cms.theme_option')->all('default');      // all option values for a theme
```

The default theme ships options for the logo, tagline visibility, container
width, primary colour, and footer text. `functions.php` is loaded **safely** —
a missing/broken/non-array file is ignored and never breaks the admin or
frontend. See [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md) §10 for the full
schema reference. There is **no live customizer** yet — values apply on save.

### Theme Custom CSS (v1.0.0-beta.7.1.13.4)

**Appearance → Theme Options** always includes a **Custom CSS** section (even for
a theme that declares no options) with two multiline editors:

- **Frontend CSS** — applied only on the public site.
- **Admin CSS** — applied only inside the admin panel.

Edit and **Save**. Values persist in the Theme Options namespace
(`theme_options.{slug}.custom_css_frontend` / `custom_css_admin`) and render
**through the Asset Registry** as scoped inline styles — Frontend CSS in the site
`<head>`, Admin CSS in the admin `<head>`, with no cross-leak. Nothing is echoed
directly, and no theme code is required.

It is **CSS only**. Values are validated on save; anything containing `</style`,
`<script`, `javascript:`, `vbscript:`, `expression(`, `behavior:`, a dangerous
`@import` (remote/protocol-relative or `javascript:`/`vbscript:`/`data:`),
control characters, or over **256 KB** is rejected, with the reason shown in a
notification — invalid CSS is never stored and never renders. Generated styles
are tagged `settings/theme-options`, so they appear in the Scripts →
Diagnostics tab; `/cms-health` reports `theme_custom_css_*` **sizes only**, never
the CSS itself.

### Publishing assets manually

Assets are published automatically on activation. After **editing** a theme's
CSS/JS (or deploying a theme without re-activating it), re-publish them so the
served files match the source — otherwise `public/themes/{slug}` keeps the old
assets and the frontend looks stale. Use the artisan command
(v1.0.0-beta.7.1.12):

```bash
php artisan theme:publish                 # active theme
php artisan theme:publish default         # one named theme
php artisan theme:publish --all           # every valid theme
php artisan theme:publish default --dry-run   # preview only (writes nothing)
php artisan theme:publish default --clean     # wipe public/themes/default first
```

Only web-servable files are copied (`css`, `js`, `mjs`, `json`, images, fonts,
`txt`, `xml`, `webmanifest`); PHP/Blade/`.env`/`.sql`/`.map` are always skipped.
`--clean` only ever removes `public/themes/{slug}` — `public/uploads` is never
touched. Assets are **copied** (no symlinks), so it works on shared hosting.

From Tinker, the underlying service is also available directly:

```php
app('cms.theme')->publishAssets('default');           // copy (no allowlist)
app('cms.theme_publisher')->publish('default');       // allowlisted publish
```

### Extensions & plugins (Extension Framework Core, 0.9.8)

The **Extension Framework** orchestrates themes and plugins via the
`extension()` helper (the `Extension` facade / `cms.extension` service). Plugins
live in `plugins/{slug}/` with a `plugin.json`. Manage plugins from the admin
(see **Managing plugins in the admin** below) or from code/Tinker:

```php
extension()->plugins();                    // valid discovered plugins
extension()->invalidPlugins();             // [{slug, reason}] for bad manifests
extension()->activatePlugin('hello-world'); // validates + adds to the registry
extension()->isPluginActive('hello-world');
extension()->activePlugins();              // valid + active plugins
extension()->deactivatePlugin('hello-world');
```

The active set is stored in `cms_settings` (`extensions.active_plugins`). When a
plugin is active, its service providers, `routes/web.php` (web middleware,
before the frontend catch-all), `resources/views` (`{slug}::` namespace), and
`database/migrations` are loaded automatically; classes autoload from
`plugins/{slug}/src/` under `Plugins\{StudlySlug}\` (no `composer dump-autoload`
needed). A broken plugin is logged and skipped — it never crashes the CMS. The
bundled `plugins/hello-world` serves `/hello-world` once activated. To build a
plugin, see [`PLUGIN_DEVELOPMENT.md`](PLUGIN_DEVELOPMENT.md).

### Managing plugins in the admin (Plugin Manager UI, 1.0.0-beta.1)

A **Plugins** navigation group provides admin UI over the Extension Framework:

1. **Plugins → Installed Plugins** (`/admin/plugins`) lists every valid plugin as
   a card with its name, description, slug, version, author, `requires.tncms`,
   provider list/count, and an **Active**/**Inactive** badge.
2. Click **Activate** on an inactive plugin or **Deactivate** on an active one.
   You get a success/danger notification; a broken plugin never crashes the page.
   Invalid plugin folders (bad/missing `plugin.json`) are listed in a separate
   **Invalid plugins** section with their reason and have no Activate button.
3. Because plugin routes register at application **boot**, a plugin's routes
   (e.g. `/hello-world`) only change on the **next** request. The page clears the
   settings cache and best-effort runs `optimize:clear`; in some environments you
   may need to run `php artisan optimize:clear` or reload.
4. **Plugins → Install Plugin** (`/admin/plugins/install`) installs a plugin from
   a `.zip` upload (see below). **Plugins → Plugin Editor** remains a placeholder.

### Installing themes & plugins from a ZIP (1.0.0-beta.2)

Two admin pages install extensions from a local `.zip` upload:

- **Plugins → Install Plugin** (`/admin/plugins/install`)
- **Appearance → Install Theme** (`/admin/themes/install`)

Upload a `.zip` whose archive contains a manifest (`plugin.json` / `theme.json`)
either at the root or inside a single wrapping folder. The installer validates the
archive (rejecting **path traversal**, absolute paths, symlinks, and oversized
archives), extracts into a throwaway temp directory, validates the manifest
(`name`, `slug`, `version`, `author`; slug must be lowercase and not a reserved
word), then moves the files into `plugins/{slug}` or `themes/{slug}`. The
extension is installed **inactive** — activate it from Installed Plugins /
Appearance → Themes. Tick **Overwrite existing files** to replace an existing
slug, but an **active** theme/plugin must be deactivated first. The "Install
Plugin" / "Install Theme" buttons also appear as header actions on the manager
pages. (No remote-URL/CLI installer, marketplace, or file editor yet.)

**Deleting an extension.** Installed extensions that are **not active** can be
deleted. On **Plugins → Installed Plugins**, inactive plugins show a danger
**Delete** action (with a confirmation modal); on **Appearance → Themes**,
inactive theme cards show a **Delete** button (shown only when more than one valid
theme exists). Deletion permanently removes the `plugins/{slug}` / `themes/{slug}`
folder and **cannot be undone**. **Active** extensions have no Delete action — you
must **deactivate a plugin** or **activate another theme** first; the CMS also
refuses to delete the last remaining theme. The slug is validated and the path is
resolved internally with a `realpath` containment check, so nothing outside
`plugins/` / `themes/` can ever be removed.

### Troubleshooting themes

- **A theme is missing from `/admin/themes`.** It must be a folder under
  `themes/` containing a valid `theme.json` (with at least a `slug`).
  Fix the JSON, then reload the page.
- **The screenshot does not appear.** Add a `screenshot.png` to the
  theme folder. The admin embeds it directly (the `themes/` directory is
  not web-served), so no publishing step is needed for the preview.
- **Theme CSS/JS returns 404 on the site.** Make sure the theme was
  activated (which publishes assets) and that `public/themes/{slug}`
  exists. Re-run `app('cms.theme')->publishAssets('{slug}')` if needed,
  and confirm your document root points at `public/`.
- **`active_theme` is `null` in `/cms-health`.** No theme is active —
  activate one under **Appearance → Themes**.

---

## Frontend (Public Site)

Added in **0.7.0**. The public website renders with the active theme.
Default locale is `vi`.

### Public URL patterns

| URL | Renders |
| --- | ------- |
| `/` | The `trang-chu` page (or a fallback home) |
| `/{page-slug}` | A published **page** (e.g. `/trang-chu`, `/gioi-thieu`) |
| `/blog/{post-slug}` | A published **post** (e.g. `/blog/bai-viet-dau-tien`) |
| `/category/{category-slug}` | A category archive (e.g. `/category/tin-tuc`) |
| `/tag/{tag-slug}` | A tag archive (e.g. `/tag/laravel`) |

Only **published** content is shown; unknown slugs return `404`. The
catch-all page route is constrained so it never captures `admin`,
`cms-health`, `livewire`, `filament`, `storage`, `uploads`, `themes`, or
`up`.

### Activate a theme and publish assets

The frontend uses whatever theme is active (see **Theme Manager** above).
Activating a theme in **Appearance → Themes** also publishes its assets.
To (re)publish manually after editing a theme's CSS/JS:

```bash
php artisan tinker
>>> app('cms.theme')->publishAssets('default');
```

Assets are **copied** to `public/themes/{slug}` (no symlinks).

### Test the frontend

```bash
php artisan optimize:clear
php artisan route:list   # confirm cms.home / cms.post / cms.resolve etc.
```

Then visit (local domain `http://laravel-cms.demo`), assuming default bases:

- `/` — homepage with header, footer, and CSS loaded
- `/trang-chu` — the home page directly
- `/blog/bai-viet-dau-tien` — the sample post (`/bai-viet-dau-tien` if the post
  base is empty)
- `/category/tin-tuc` — category archive (`/tin-tuc` if the category base is empty)
- `/tag/laravel` — tag archive (`/laravel` if the tag base is empty)

Frontend helpers available in theme views: `theme_asset('css/app.css')`,
`theme_view('pages.page')`, `frontend_menu('header')`,
`content_url($content)`, `term_url($term)`, plus `settings()` and
`menu()`.

### Troubleshooting the frontend

- **Blank / unstyled page (no theme).** If `/cms-health` shows
  `frontend_ready: false` or `active_theme_views_ready: false`, no theme
  is active or its `views/` folder is missing. Activate a theme under
  **Appearance → Themes**. With a theme present but a view missing, the
  controller returns a plain fallback page instead of a 500. If **no valid
  theme exists at all** (`theme_system_ready: false`), the frontend returns a
  friendly **503** "No active theme found" page — add/activate a valid theme.
- **Missing CSS/JS (styles not applied).** Confirm
  `public/themes/{slug}/css/app.css` exists; if not, re-publish with
  `app('cms.theme')->publishAssets('{slug}')`. Verify the document root
  points at `public/` so `/themes/...` resolves to static files.
- **A page slug "captures" a reserved path.** The catch-all `/{slug}`
  route excludes reserved prefixes via a route constraint. If you add a
  new reserved area, extend the `where(...)` in
  `packages/thenguyen/cms-core/routes/frontend.php`. Avoid creating a
  page whose slug equals a reserved word (e.g. `admin`).
- **`404` on a page/post you just created.** Only **published** content
  renders. Set the status to *Published* and ensure its `vi` slug matches
  the URL. Re-save to (re)build its `cms_slugs` entry.
- **Homepage shows the fallback message.** Create/publish a page with
  slug `trang-chu` (the core content seeder does this), or add a
  `theme::pages.home` view to your theme.

---

## SEO Core

Added in **0.7.5**. A lightweight SEO layer: every frontend page emits
title / description / keywords / robots / canonical, Open Graph, and
Twitter card tags, and the site serves `robots.txt` and `sitemap.xml`.
There is **no SEO plugin, analyzer, or AI** — it reads the meta fields you
already fill in on Pages/Posts and the term description on archives.

### Where SEO values come from

| Page | Title | Description |
| ---- | ----- | ----------- |
| Home (`/`) | `general.site_name` | `general.site_description` |
| Page | `meta_title` → title | `meta_description` → excerpt → first 160 chars |
| Post | `meta_title` → title | `meta_description` → excerpt → first 160 chars |
| Category | `meta_title` → `Category: {name}` | `meta_description` → term description → site description |
| Tag | `meta_title` → `Tag: {name}` | `meta_description` → term description → site description |

- **Keywords** come from the post/page `meta_keywords` field only.
- **Robots** defaults to `index,follow` (override with a `seo.robots`
  setting if you add one).
- **OG / Twitter image** uses the content's **featured image** when set.
- If `general.site_name` / `general.site_description` are missing, the
  fallbacks are **`TheNguyen CMS`** and **`TheNguyen CMS website`**.

Fill **Meta title**, **Meta description**, and **Meta keywords** in the
SEO fields of a Page/Post to override the defaults. **Categories** and
**Tags** have their own collapsed **SEO** box (Meta title + Meta
description) on the edit form — these write to the existing
`cms_term_translations` columns and override the archive defaults above.
(Terms have no `meta_keywords` column, so tags/categories expose only
title + description.)

### Using SEO from code / a theme

The `seo()` helper returns the SEO manager for the current page:

```php
seo()->title();        // resolved <title> text
seo()->description();  // meta description
seo()->canonical();    // canonical URL
seo()->current();      // everything as an array
```

The default theme already renders the tags via
`themes/default/views/partials/seo.blade.php`, included from
`layouts/master.blade.php`. A custom theme gets SEO automatically as long
as its master layout does `@include('theme::partials.seo')` inside
`<head>` (and does not also emit its own `<title>`).

### robots.txt and sitemap.xml

- **`/robots.txt`** is generated by the app (not a static file). It emits
  `User-agent: * / Allow: /` and a `Sitemap:` line pointing at
  `/sitemap.xml`.

  > The stock Laravel `public/robots.txt` was **removed** so the dynamic
  > route is authoritative. If you re-add a physical `public/robots.txt`,
  > the webserver will serve that file instead of the route.

- **`/sitemap.xml`** lists the homepage plus all **published** pages,
  posts, categories, and tags, with `lastmod` timestamps. It is a single
  `urlset` (no sitemap index yet).

Test them:

```bash
curl -s http://laravel-cms.demo/robots.txt
curl -s http://laravel-cms.demo/sitemap.xml
```

### Verifying SEO health

`/cms-health` reports three SEO flags:

```json
{
  "seo_ready": true,
  "sitemap_ready": true,
  "robots_ready": true
}
```

`seo_ready` is true when the `cms.seo` service is bound and the theme's
`partials/seo` view exists; `sitemap_ready` / `robots_ready` are true when
their routes are registered.

### Troubleshooting SEO

- **`/robots.txt` shows the old `Disallow:` content.** A physical
  `public/robots.txt` is shadowing the route — delete it so the dynamic
  route serves.
- **A page's `<title>` is just the site name.** The page has no
  `meta_title` and no title, or the content is a draft. Set a title and
  publish it.
- **`sitemap.xml` is missing a page/post.** Only **published** content is
  included; drafts are excluded. Publish it and reload.
- **Tags not appearing at all.** Confirm your theme's master layout
  includes `theme::partials.seo` in `<head>`.

---

## Languages (Multi-language)

Added in **0.9.0**. The CMS is multi-language aware. Out of the box it ships
with **Vietnamese (vi, default)** and **English (en)**.

### Managing languages

Go to **CMS → Languages** (`/admin/languages`). Each language has a `code`
(e.g. `vi`, `en`, `ja`), an optional `locale` (e.g. `en_US`), display names,
a `direction` (ltr/rtl), an **Active** flag, a **Default** flag, and a sort
order.

Rules enforced for you:

- Exactly **one** default language — setting a new default clears the old one.
- The **default language cannot be deleted or deactivated**.
- `code` must be unique.

### Frontend URLs

- The **default language** renders at the normal, unprefixed paths: `/`,
  `/trang-chu`, `/blog/bai-viet-dau-tien`.
- **Other active languages** render under a locale prefix: `/en`,
  `/en/home`, `/en/blog/first-post`, `/en/category/{slug}`, `/en/tag/{slug}`.
- Only **active** language codes are accepted as a prefix; anything else
  falls through to the normal page lookup.
- Set **`language.prefix_default = true`** (a setting) if you also want the
  default language to be reachable with its prefix.
- A request for a locale that has **no translation** returns **404** (there is
  no automatic fallback to the default language in this phase).

### Translating content

On a Page / Post / Category / Tag / Menu form there is a **Language**
selector.

1. Open the record (it loads the **default** language's translation).
2. Switch **Language** to e.g. `en`. The form **live-loads** the English
   translation (since 0.9.1). If no English translation exists yet, the
   translatable fields load **empty** so you can create one cleanly.
3. Enter / adjust the English **title / slug / content / meta**.
4. **Save** — this creates/updates the `en` translation only; the other
   locales and all non-translatable fields (status, publish date, featured
   image, categories, tags, menu location, …) are untouched.
5. Visit the localized URL (e.g. `/en/blog/your-en-slug`). Switch back to
   `vi` to see the Vietnamese version — each locale stays separate.

> **Note:** switching the language **discards unsaved edits** in the current
> form (it reloads the page for the new locale). Save before switching. This
> is deliberate — it prevents accidentally overwriting the wrong locale.

> **Menus (0.9.2):** on a **Menu** edit page the **Menu Items** relation
> manager follows the same selected locale. With the menu open at `?locale=en`,
> adding or editing an item edits the **`en`** item title; at `?locale=vi` it
> edits the **`vi`** title. The item table shows the selected locale's title
> (falling back to the default title flagged `(default)`). Saving one locale's
> item title never overwrites another's.

### Language switcher + SEO

- The default theme header shows a **VI | EN** switcher when more than one
  language is active. It links to the matching translation, or that
  language's home when no translation exists.
- The page `<html lang>`/`dir` reflect the current language.
- `hreflang` alternates (plus `x-default`) are emitted automatically, the
  canonical points at the current localized URL, and `sitemap.xml` lists
  every published translation's URL.

### Helpers

```php
language();                      // LanguageManager
language('en');                  // Language model (or null)
current_locale();                // 'vi' | 'en' | ...
localized_url('en', '/blog/x');  // '/en/blog/x'
content_url($post, 'en');        // localized post URL (or '#')
language_switcher();             // array for the theme switcher
```

---

## Rich Editor (Content body)

Added in **0.8.0**. The **Content** field on Pages and Posts is a
**TinyMCE** rich editor. It supports headings, bold/italic/underline/
strikethrough, links, lists, tables, a code view, and inserting images by
URL. Content is saved as **sanitized HTML**.

### Writing content

Open `/admin/pages/create` or `/admin/posts/create` (or edit an existing
record). The Content box is the editor. Use the toolbar to format text,
add a heading (the **blocks** dropdown), insert a link, build a list or
table, or switch to **code view** (`<>`) to edit raw HTML.

### Inserting images

Three toolbar buttons handle media (there is **no** in-editor upload yet):

- **Insert Media** — opens the image **Media Modal** (recommended). Pick an
  image from a thumbnail grid and click **Insert**. See below.
- **Insert media by URL** (image icon) — prompts for an image URL and
  inserts `<img src="…" alt="" loading="lazy">`.
- **Media** — opens the **Media Library** (`/admin/media`) in a new tab.

#### Insert Media modal (0.8.1)

Click **Insert Media** in the editor toolbar. A centered **Select media**
dialog opens with a grid of your **images** on the left (newest first, up
to 50) and a details panel on the right. Each card shows a thumbnail,
filename, dimensions, and alt text; the selected card is highlighted with a
ring and a check badge.

- **Search** by filename, alt, or title using the box at the top; a count
  ("Showing X images") updates as you type.
- **Select** an image (click it) — the right-hand panel shows a larger
  preview, filename, dimensions, a **Copy URL** button, and **insert
  options**: an **Alt text** box (pre-filled from the image's alt or
  filename), a **Title** box (pre-filled from the image's title), and an
  **Add caption** checkbox that reveals a **Caption** box. Then press
  **Add to editor**, or **double-click** the image.
- **Insert output (0.8.2):** with **Add caption** unchecked the editor
  inserts a plain `<img>` (stored URL + your `alt`/`title`, plus `width`,
  `height`, `loading="lazy"` when known). With **Add caption** checked it
  inserts a `<figure class="cms-image">` wrapping the `<img>` and a
  `<figcaption>` with your caption text. `title` is included only when
  non-empty; all values are escaped.
- **Cancel** (or `Esc`, or clicking outside) closes without inserting.
- **No images?** The modal shows *"No images found. Upload an image first,
  then return here."* with an **Upload media** button
  (`/admin/media/upload`, new tab). A search with no matches shows *"No
  images match your search."*

It is **image-only** — no documents, galleries, multi-select, or uploading
from inside the editor. To add a new image, upload it under **Media →
Upload**, then reopen the editor and use **Insert Media**.

To get a URL manually: **Media → Library → View/Edit** shows the file's URL.

### How content is stored and rendered (security)

- On save, the body is **sanitized** by `HtmlSanitizer` — `<script>`,
  inline event handlers (`onclick`, …), `style`, `iframe`, and other
  dangerous markup are removed. External links opening in a new tab get
  `rel="noopener noreferrer"`. `<figure>`/`<figcaption>` and the `class`
  attribute are allow-listed, so the **Add caption** output is preserved
  while an unsafe image (e.g. `src="javascript:…"` or `onerror=…`) is
  still stripped.
- The frontend renders the body with `{!! cms_html($body) !!}`, which
  sanitizes again (defense in depth). Existing **plain-text** content
  (created before 0.8.0) still renders fine — line breaks are preserved.

You can sanitize HTML yourself anywhere:

```php
cms_html($html);                                  // helper (render-safe)
\TheNguyen\CMS\Facades\Html::sanitize($html);     // facade
app('cms.html')->sanitize($html);                 // container
```

### Paste, links & editor styling (0.8.2)

- **Paste cleanup** — pasting from Word/Google Docs strips inline `style`
  attributes (and won't inline pasted images), so content stays tidy.
- **Links** — new links default to the same tab; typing a bare domain
  assumes `https`; the link dialog offers a **Rel** list
  (None / nofollow / sponsored / noopener noreferrer).
- **In-editor styling** — the editor approximates the frontend (heading
  spacing, responsive images, `figure.cms-image`, table borders,
  blockquote, code) so what you write looks close to what renders.

### TinyMCE assets (self-hosted)

TinyMCE (v8, community/GPL) is **self-hosted** under
`public/vendor/tinymce` and loaded from `/vendor/tinymce/tinymce.min.js` —
**no Tiny Cloud, no API key, no CDN request**. If the editor falls back to
a plain textarea, confirm `public/vendor/tinymce/tinymce.min.js` exists
(the loader is in
`resources/views/filament/admin/components/rich-editor.blade.php`).

### Verifying editor health

`/cms-health` reports:

```json
{
  "rich_editor_ready": true,
  "html_sanitizer_ready": true,
  "media_modal_ready": true,
  "editor_polish_ready": true
}
```

`media_modal_ready` is true when the rich editor is ready and the
`cms_media` table exists. `editor_polish_ready` (0.8.2) is true when the
rich editor **and** the HTML sanitizer are both ready.

### Troubleshooting the editor

- **Insert Media modal is empty.** It only lists **images**. Upload at
  least one image under **Media → Upload**, then reopen the editor.
- **Editor doesn't appear (plain textarea instead).** The self-hosted
  TinyMCE assets failed to load — confirm
  `public/vendor/tinymce/tinymce.min.js` exists. The plain textarea is the
  intentional fallback — content still saves. Check the browser
  console/network.
- **My `<script>`/embed disappeared after saving.** That's the sanitizer
  working as intended; script and unsafe embeds are stripped.
- **Pasted styles look different on the site.** Inline `style` attributes
  are removed; use the theme's CSS / allowed tags for formatting.

---

## Troubleshooting

### SSL / Composer install fails on Windows with `curl error 60`

Laragon ships a CA bundle that is not always trusted by PHP's default
SSL stack. The shipped `composer.json` currently includes a Laragon-only
override:

```json
"config": {
  "cafile": "C:/laragon/etc/ssl/cacert.pem",
  "secure-http": false,
  "disable-tls": true
}
```

> ⚠️ **`secure-http: false` and `disable-tls: true` are a temporary local
> Laragon-only workaround. They disable TLS verification for Composer and
> are NOT safe for production or CI — never present them as deployable.
> They MUST be removed before any production/CI use.**

Before deploying or running in CI:

- **Remove** `secure-http` and `disable-tls` from `composer.json`
  (keep TLS on), or
- Use a per-environment `composer.json` override that does not disable
  TLS on the CI / production side.

If you need a permanent fix, point Composer at a known-good CA bundle:

```bash
composer config --global cafile "C:/laragon/etc/ssl/cacert.pem"
```

### Visiting `/admin` errors with `Route [login] not defined`

The Filament admin panel must be marked as the default panel and have
`->login()` enabled. Confirm:

```php
return $panel
    ->default()
    ->id('admin')
    ->path('admin')
    ->login()
    // ...
```

If you change this, clear caches:

```bash
php artisan optimize:clear
```

### Hitting `http://example.com/public/admin` instead of `/admin`

This means the webserver is serving the project root instead of
`/public`. You have two options:

- **Preferred:** change the document root to point at `/public`
  (Mode A / Nginx Mode A / Apache `DocumentRoot`).
- **If the host won't allow that:** drop the bundled
  `deployment/apache/root-public-html.htaccess` (Apache) or use
  `deployment/nginx/public-html-rewrite.conf` (Nginx). Set
  `CMS_DEPLOYMENT_MODE=public_html_rewrite`.

### `storage` / `bootstrap/cache` permission errors

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

On shared hosting where you cannot change ownership, set 775 and make
sure the directories are owned by your cPanel user (already true in
practice).

### `php artisan migrate` says "No such table" mid-run

A previous partial migration left the schema inconsistent. Recover:

```bash
php artisan migrate:status         # see what ran
php artisan migrate                # try again
# last resort, destructive:
php artisan migrate:fresh
```

Never run `migrate:fresh` in production unless you have a backup.

### Stale caches after a deploy

```bash
php artisan optimize:clear
# in production, then rebuild:
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If a class change still isn't picked up, the opcache is the culprit:

```bash
sudo systemctl reload php8.3-fpm
```

### `/cms-health` returns 404

The `CmsServiceProvider` is not registered, or routes are cached against
an older version. Fix:

1. Verify `bootstrap/providers.php` contains
   `TheNguyen\CMS\Providers\CmsServiceProvider::class`.
2. `composer dump-autoload`.
3. `php artisan optimize:clear`.
4. `php artisan route:list | grep cms-health`.

### Widget does not appear on the Filament dashboard

1. Confirm the widget class is listed in the panel's `->widgets([...])`
   array (in `AdminPanelProvider`).
2. Confirm the Blade view path matches the widget's `$view` property.
3. `php artisan view:clear`.

---

## Future Plugin Development

> **Planned — not implemented.** There is no plugin / module manager in
> the codebase today (`modules/` is an empty, boot-created directory). The
> Plugin / Module Manager is scheduled as a future release (see
> [`CMS_CHANGELOG.md`](CMS_CHANGELOG.md) → `[1.0.0] Plugin / Module
> Manager`). The contract below is **provisional** and may change before
> it ships.

A future plugin / module will be a self-contained directory under
`modules/`:

```
modules/Blog/
├── module.json
├── composer.json           (optional; only if shipped as a package)
├── src/
│   ├── Providers/
│   │   └── BlogServiceProvider.php
│   ├── Models/
│   ├── Filament/
│   │   └── Resources/
│   └── Http/
├── database/
│   └── migrations/
├── resources/
│   └── views/
└── routes/
    └── web.php
```

`module.json`:

```json
{
  "name": "Blog",
  "slug": "blog",
  "namespace": "TheNguyen\\CMS\\Modules\\Blog",
  "version": "0.1.0",
  "provider": "TheNguyen\\CMS\\Modules\\Blog\\Providers\\BlogServiceProvider",
  "depends": []
}
```

Expectations:

- A module must own its migrations, models, routes, controllers, and
  Filament resources.
- A module may publish assets into `public/vendor/{slug}/`.
- A module **must not** edit the CMS core or other modules.
- A module's service provider is auto-discovered by the module manager
  once it ships.

For now, you can prototype a module by manually registering its
service provider in `bootstrap/providers.php` and adding a PSR-4 entry
to the root `composer.json`. Migrate to the module manager once Phase 4
lands.

---

## Theme Development (historical / provisional design)

> ✅ **The Theme Manager already ships** (Theme Manager Core, `0.6.0`;
> Frontend Rendering, `0.7.0`). For how themes actually work today — the
> real `themes/{slug}/` layout, `theme.json`, the `theme::` view
> namespace, and asset publishing — see the **Theme Manager** and
> **Frontend (Public Site)** sections above, and
> [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md) §10–§11.
>
> The sketch below was an **earlier provisional design** (a PSR-4,
> namespaced theme with `src/Providers/`). It was **not** the structure
> that was built and is kept only for historical context. Do not treat it
> as current.

A provisionally-designed theme would have lived under `themes/{slug}/`:

```
themes/default/
├── theme.json
├── src/
│   └── Providers/
│       └── DefaultThemeServiceProvider.php
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   └── app.blade.php
│   │   ├── partials/
│   │   │   ├── header.blade.php
│   │   │   └── footer.blade.php
│   │   └── pages/
│   │       └── home.blade.php
│   ├── css/
│   └── js/
└── public/                    copied into public/themes/default/
    ├── images/
    └── fonts/
```

`theme.json`:

```json
{
  "name": "Default",
  "slug": "default",
  "namespace": "TheNguyen\\CMS\\Themes\\Default",
  "version": "0.1.0",
  "screenshot": "screenshot.png",
  "supports": ["page", "blog", "menu"],
  "layouts": {
    "default": "layouts.app"
  }
}
```

Expectations:

- A theme owns layouts, partials, components, and presentation-only
  assets.
- A theme renders data exposed by modules via documented Blade
  components / view-models. It never queries module tables directly.
- A theme's source assets compile into `themes/{slug}/public/` and the
  theme manager publishes that into `public/themes/{slug}/`.
- The active theme is controlled by a `cms_settings` row (`theme.active`),
  which overrides the `CMS_ACTIVE_THEME` env default.

> The shipped Theme Manager uses a simpler `theme.json` + `views/` +
> `assets/` layout (no per-theme service provider). Follow the
> **Theme Manager** section above for the real, current workflow.

---

## Where to go next

- Read [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md) — the single source of
  truth for what is implemented vs planned.
- Skim [`CMS_STRUCTURE.md`](CMS_STRUCTURE.md) to internalise the
  namespaces and conventions.
- Build a theme with [`THEME_DEVELOPMENT.md`](THEME_DEVELOPMENT.md) or a plugin
  with [`PLUGIN_DEVELOPMENT.md`](PLUGIN_DEVELOPMENT.md).
- Watch [`CMS_CHANGELOG.md`](CMS_CHANGELOG.md) for upcoming releases.
- Open <http://laravel-cms.demo/cms-health> any time to confirm the
  environment is wired up correctly.

**This phase (`1.0.0-beta.3`): Users / Roles / Permissions Core.** A practical
RBAC layer: **Users → Users** (`/admin/users`) manages users + role assignment,
**Users → Roles** (`/admin/roles`) edits roles and their grouped permission
checkboxes. Sensitive admin surfaces (Settings, Themes, Theme Options, Plugins,
install pages, content/media resources) are permission-guarded, and the new
`cms_can()` helper / `Permission` facade drive every check. The first user is
seeded as **super admin** and can never be locked out. See **Users, roles &
permissions** below. The prior phase (`1.0.0-beta.2`) shipped the Theme/Plugin
**ZIP Installer** + discovery/installer/delete hardening.

**Next planned phase:** **v1.0.0-beta.4 — GitHub / URL Extension Installer.**
A plugin/theme file editor, remote marketplace, CLI installer, license manager,
a live theme customizer, audit logs, two-factor auth, and widgets remain planned
(not scheduled, not implemented).

---

## Users, roles & permissions (1.0.0-beta.3)

TN CMS ships a small, practical RBAC layer. Users have **roles**; roles hold
**permissions** (string keys such as `themes.install`); admin surfaces check
those permissions.

### Setup

Roles/permissions live in the core package's migrations and a seeder:

```bash
php artisan migrate
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder"
```

The seeder is idempotent. It creates four roles and, **on a fresh install only**,
assigns `super-admin` to the first existing user so the owner keeps full access.

| Role          | Summary                                                            |
| ------------- | ------------------------------------------------------------------ |
| `super-admin` | Everything, always (bypasses individual permission rows). System.  |
| `admin`       | Everything except `roles.manage` and `users.delete`. System.       |
| `editor`      | All content, pages, posts, taxonomy, media, menus. No settings/themes/plugins/users. |
| `author`      | Create/edit posts + upload media. No publish/delete (see limitation). |

### Managing users & roles

- **Users → Users** (`/admin/users`): create/edit/delete users and assign roles.
  Password is required on create and optional on edit (blank = unchanged).
- **Users → Roles** (`/admin/roles`): edit a role's permissions via grouped
  checkboxes. System roles cannot be deleted; the super-admin role always keeps
  every permission.

### Checking permissions in code

```php
cms_can('themes.install');            // current user
cms_can('plugins.delete', $user);     // a specific user
auth()->user()->hasPermission('settings.manage');
auth()->user()->isSuperAdmin();
```

### Never locked out

The super admin is protected three ways: a configurable email allowlist
(`CMS_SUPER_ADMIN_EMAILS` → `config('cms.super_admin_emails')`), the seeded
`super-admin` role, and **fail-open** behaviour — before any role is seeded every
check passes, so a fresh install behaves exactly as before. You also cannot delete
yourself or remove the last super admin.

### Panel access

`App\Models\User` implements Filament's `FilamentUser`; admin access requires the
`admin.access` permission (super admins bypass). All four seeded roles include it.

### Known limitation

There is no post-ownership column yet, so the `author` role is not scoped to its
own posts; authors can create/edit posts broadly but cannot publish or delete.
Per-resource ownership scoping is planned hardening.

---

## Maintenance mode (1.0.0-beta.4)

Built into core — no plugin required. While maintenance is **on**, public visitors
see a maintenance page, but the **admin panel, login, Livewire, `/cms-health`,
`/robots.txt` and `/sitemap.xml` stay reachable**.

### Turning it on

**Settings → Maintenance** (needs the `system.maintenance.manage` permission, which
`super-admin` and `admin` have). Options:

- **Enable maintenance mode** — the master switch.
- **Display mode** — *Theme maintenance view* (`themes/{slug}/views/maintenance.blade.php`,
  with a core fallback) or *Custom CMS page* (render a chosen published page).
- **Maintenance page** — the published page used when display mode is *page*.
- **Title / Message** — text shown by the theme/fallback view.
- **HTTP status code** — `503 Service Unavailable` (recommended; tells search engines
  the outage is temporary) or `200 OK`.
- **Retry after (minutes)** — sent as the `Retry-After` header on 503 responses
  (leave `0` to omit).
- **Allow admin bypass** — holders of `system.maintenance.bypass` (super admins
  included) view the site normally.
- **Allow logged-in user bypass** — any authenticated user views the site normally.
- **Allowed IPs** — IPs that bypass maintenance (one per tag).
- **Excluded paths** — paths that always stay accessible (e.g. `admin`, `livewire`,
  `robots.txt`). Bare segments match the segment and anything beneath it; entries
  with a dot (like `robots.txt`) match the exact path only.

### How it works

A `CheckMaintenanceMode` middleware runs on the **frontend route group only**. When
enabled and the visitor is not on an excluded path and cannot bypass, it returns the
maintenance response with the configured status, a `Retry-After` header (503 only),
and `X-Robots-Tag: noindex, nofollow` so the page is never indexed. In *page* mode the
chosen page is rendered through the active theme without re-running the gate (no
loop). The reusable logic is the `MaintenanceManager` service:

```php
maintenance()->isEnabled();
maintenance()->mode();          // 'theme' | 'page'
maintenance()->shouldBypass(request());
```

`/cms-health` reports `maintenance_ready`, `maintenance_enabled`, and
`maintenance_mode` (allowed IPs are never exposed).

---

## Interface translations (1.0.0-beta.5)

TN CMS translates **interface** strings (theme/plugin/core UI labels) with Laravel-style
**JSON** files — separate from page/post **content** translations (which live in the
content tables and are edited per record). No `.po`/`.mo`, no auto/AI translation.

### Where files live

```
lang/{locale}.json                          # CMS core
themes/{slug}/lang/{locale}.json            # active theme
plugins/{slug}/lang/{locale}.json           # plugin (preferred)
plugins/{slug}/resources/lang/{locale}.json # plugin (compatibility)
```

A file is a flat map of source string → translation:

```json
{ "Read more": "Đọc thêm", "Search": "Tìm kiếm" }
```

### Helpers

```php
core_trans('Search');                                   // CMS core string
theme_trans('Read more');                               // active theme (→ core → key)
plugin_trans('hello-world', 'Hello World from TN CMS Plugin');
plugin_trans('hello-world', 'Hello :name', ['name' => 'TN CMS']); // → "Hello TN CMS"
core_trans('Search', [], 'vi');                          // force a locale
```

The locale defaults to `current_locale()`. Each helper tries the requested locale, then
the **default** locale, then falls back (theme/plugin → core), and finally returns the
key unchanged. Missing files or invalid JSON never error — you just get the fallback.

### Syncing files for languages

**Admin → Languages** has a **Sync Translation Files** action (and a per-row **Sync
files**). It creates a missing `{locale}.json` (as empty `{}`) for the CMS core, the
active theme, and each **active** plugin, across all active languages. **Existing files
are never overwritten.** Creating a new language syncs its locale automatically. The
service method is `extension_translation()->syncTranslationFiles($locales = null)`,
returning `['created' => [...], 'skipped' => [...], 'errors' => [...]]`. Requires the
`languages.manage` permission (granted to `super-admin` and `admin`).

`/cms-health` reports `extension_translation_ready` and the core/theme/plugin file
counts (counts only — never absolute paths).

## Health output cleanup (1.0.0-beta.5.2)

`/cms-health` was hardened for the upcoming Web Installer Core:

- **Canonical metric names.** Each metric has **one** canonical singular name:
  `theme_count`, `invalid_theme_count`, `plugin_count`, `active_plugin_count`,
  `invalid_plugin_count`, `language_count`, `active_language_count`, `role_count`,
  `permission_count`. The old plural aliases (`themes_count`, `plugins_count`,
  `languages_count`, `roles_count`, `permissions_count`, …) were **removed**. If you
  monitor `/cms-health`, update your checks to the singular names.
- **No filesystem paths by default.** `base_path` and `public_path` are **omitted** unless
  you explicitly opt in with `CMS_HEALTH_SHOW_PATHS=true` (config `cms.health.show_paths`,
  default `false`). This is **not** tied to `APP_DEBUG` — even with `APP_DEBUG=true` the
  public endpoint exposes no server paths by default. No storage/plugin/theme/installer
  paths are ever exposed. Use the flag for local debugging only, then turn it back off.
- **`health_output_clean` (bool).** A summary flag that is `true` when the output is
  de-duplicated, **no** absolute filesystem paths are exposed, and the translation-health
  metrics are valid. Enabling `CMS_HEALTH_SHOW_PATHS=true` flips it to `false`.
- **`health_output_debug_paths_enabled` (bool).** Mirrors `cms.health.show_paths`
  (default `false`) so monitoring can detect when path output is intentionally enabled.
- **Translation metrics.** `core_translation_keys_count`,
  `core_translation_missing_keys_count`, `core_translation_untranslated_keys_count`, and
  the `*_translation_files_count` metrics are final. `core_translation_keys` is a
  **deprecated alias** of `core_translation_keys_count` and will be removed later.

The endpoint always returns `200`/`ok` and never throws — missing JSON counts as `0` and
invalid JSON is treated as empty.

**Vendor translation note.** Filament's own built-in strings (Create / Save / Delete /
Search / pagination / "No records found") and Laravel validation messages follow the CMS
locale through Filament's and Laravel's **shipped** per-locale translation files (Vietnamese
and English both ship). TN CMS aligns the framework locale via `SetCmsAdminLocale` and adds
`lang/vi/validation.php`, but **does not modify or translate vendor internals** — locales
that Filament does not publish will show Filament's English fallback for those built-ins.

## Installing TN CMS with the web installer (1.0.0-beta.6)

On a freshly deployed copy, visit:

```text
https://your-domain.com/install
```

The installer walks five steps:

1. **Welcome** — overview.
2. **Requirements** — server checks (below). You cannot continue until all pass.
3. **Database & site** — DB connection plus site name, URL, timezone, default language
   (`vi`/`en`), and the admin URL path. The connection is **tested** before anything is
   written to `.env`. Your DB password is never shown back.
4. **Super-admin** — name, email, and a password (min 8, confirmed). On submit the installer
   runs migrations and seeders, creates the account, grants the `super-admin` role, clears
   caches, and **locks** itself.
5. **Finish** — shows your **Frontend URL** and **Admin URL**.

### Required PHP extensions

`openssl`, `pdo`, `pdo_mysql`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`,
`curl`, `zip`, and **`gd` or `imagick`**. PHP must be **8.3+**.

### Required writable paths

`.env` (or the project root if `.env` does not exist yet), `storage/`, `bootstrap/cache/`,
and `public/`.

### Installer lock

The CMS is considered installed — and the installer is disabled — when **either** of these
is present:

- the marker file `storage/app/tncms-installed`, or
- `TN_CMS_INSTALLED=true` in `.env`.

`markInstalled()` writes both. Once locked, every installer step except `/install/finish`
redirects to `/`, and locked POSTs are rejected by CSRF before any work runs — so a live
site can never be re-installed or have another admin created through `/install`.

### Resetting the installer (local development only)

> Never do this on a production site with real data.

1. Delete `storage/app/tncms-installed`.
2. Remove the `TN_CMS_INSTALLED` line from `.env` (or set it to `false`).
3. Run `php artisan optimize:clear`.

`/install` becomes available again.

### Health

`/cms-health` reports `web_installer_ready`, `cms_installed`, and `installer_locked`. No
installer paths or collected credentials are ever exposed.

## Widgets (1.0.0-beta.7)

Widgets are small render units (text, HTML, recent posts, categories) placed into
**widget areas** — named slots a theme exposes such as `footer-1`, `footer-2`,
`footer-3`, and `sidebar-blog`.

**Manage** under **Appearance → Widgets** (`/admin/widgets`), guarded by the
`widgets.manage` permission (granted to super-admin and admin). The screen is a
WordPress-style two-column board (1.0.0-beta.7.1):

- **Available Widgets** (left): a search box, widgets grouped by category
  (Basic, Content, …), an "Add new widgets to" area selector, plus **Presets**
  and **Export / Import** panels.
- **Widget Areas** (right): each area is a collapsible card listing its widgets.

You can:

- **Add** a widget — pick the target area, then click *Add* on any widget card
  (cards are grouped and searchable).
- **Reorder** by **drag & drop**, and **drag widgets between areas**; the new
  order is saved automatically. Areas are collapsible and remember their
  open/closed state.
- **Edit** inline — clicking edit expands the widget in place (no popup). The
  title and any field marked *Localized* are saved per language (use the locale
  switcher); global fields (e.g. *Number of posts*) apply to all languages.
- **Duplicate**, **enable/disable**, and **delete** a widget. *Duplicate* clones
  a widget (settings + all translations) right below the original.
- **Apply a preset** to stamp a bundle of widgets into an area.
- **Export** widgets — **Copy JSON** to the clipboard or **Download JSON** —
  and **Import** them by pasting JSON or uploading a `.json` file, in **Append**
  (keep existing) or **Replace** (clear the area first) mode.

**Built-in widgets:** *Text* (escaped plain text), *HTML* (sanitized custom
HTML), *Recent Posts* (latest published posts in the current language, limit
1–20), *Categories* (category links, optional post count). The admin lives in the
`cms-core` package, so it travels with the CMS.

**Rendering safety.** A broken widget never takes down the page — it is logged
and skipped (you may see an HTML comment only when `APP_DEBUG=true`). Saving,
reordering, enabling, or deleting a widget clears the public content cache.

**Themes** render an area with `{!! widget_area('footer-1') !!}`; the default
theme uses the three footer areas (falling back to the menu footer when empty)
and the blog sidebar. See `THEME_DEVELOPMENT.md` and `PLUGIN_DEVELOPMENT.md` to
register your own widgets and areas.

`/cms-health` reports `widgets_ready`, `widget_count`, `widget_area_count`, and
`active_widget_count` (counts only).

## Hooks & Shortcodes (1.0.0-beta.7.1.11)

TN CMS ships a WordPress-inspired **hooks & shortcodes** layer so plugins and
themes can extend the CMS without editing core. It runs entirely in-process —
it is **not** a webhook system.

**Actions** run callbacks at a named point: `do_action('cms.content.saved', $content)`.
**Filters** transform a value: `$title = apply_filters('cms.content.title', $title, $content)`.
**Shortcodes** turn `[tag]` tokens in post/page content into rendered output.
Register them from a plugin service provider (or a theme's `functions.php`) with
`add_action()`, `add_filter()`, and `add_shortcode()`.

**Built-in shortcodes** you can drop into any post/page body:

- `[button url="https://tncms.org" target="_blank"]Read more[/button]` — a safe
  link button (URL sanitized; `_blank` gets `rel="noopener noreferrer"`).
- `[year]` — the current year.
- `[site_name]` — your localized site name.

Unknown shortcodes are left untouched, and a broken hook or shortcode is caught
and logged — it never takes down the page. **Themes** expose injection points
via `{!! render_hook('cms.theme.header') !!}` (also `before_content`,
`after_content`, `footer`). See `PLUGIN_DEVELOPMENT.md` and `THEME_DEVELOPMENT.md`
for the full API and hook-point list. `/cms-health` reports `hooks_ready`,
`shortcodes_ready`, and `registered_shortcode_count`.

**Hook Context & Extensibility API (v1.0.0-beta.7.1.11.1).** Core hooks now pass
an immutable `HookContext` (request/user/locale/theme/route/panel — all
null-safe) as the final callback argument, callbacks can be tagged with
`source`/`source_slug`/`label` metadata, and hook points can be documented via
`define_action()` / `define_filter()` and discovered with `hook_definitions()`.
Backward compatible: callbacks that request fewer arguments keep working.
`/cms-health` adds `hook_definitions_count`, `defined_action_count`,
`defined_filter_count`, and `hook_callback_count`.

## Global Script Manager (v1.0.0-beta.7.1.13)

The Global Script Manager is the one safe place to add frontend head/footer
scripts, verification meta tags, JSON-LD, and trusted iframe embeds. Themes never
hand-write these — the default theme only calls `render_head_assets()` inside
`<head>` and `render_footer_assets()` before `</body>`.

Register from a plugin (or app) via the `Script` facade or the `register_*`
helpers:

```php
use TheNguyen\CMS\Facades\Script;

Script::externalHead('gtm', 'https://www.googletagmanager.com/gtag/js?id=G-XXXX');
Script::meta('robots', 'index,follow');
Script::verification('google', 'your-verification-token');
Script::jsonLd('organization', ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'TN CMS']);
Script::embed('yt', '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>', 'footer');
```

**What it protects against.** Inline scripts with `eval(`, `document.write`,
`new Function`, `javascript:`, or inline event handlers are rejected. External
scripts must be same-origin relative or on a trusted host allowlist. JSON-LD is
validated and HTML-escaped. Embeds are iframe-only, host-allowlisted, and
attribute-sanitized. Every method returns `true`/`false`; rejected assets are
never rendered and rendering never throws on the frontend.

**Order & duplicates.** Assets render by `priority` (default `10`, lower first),
ties broken by registration order. Re-registering the same key replaces the
earlier asset.

**Extending output.** Use the existing hooks: actions
`cms.scripts.rendering_head` / `cms.scripts.rendering_footer` and filters
`cms.scripts.head_html` / `cms.scripts.footer_html`.

`/cms-health` adds `script_manager_ready`, `registered_head_assets`,
`registered_footer_assets`, `registered_meta`, `registered_json_ld`,
`registered_verifications`, and `registered_embeds` (counts only — never
contents, URLs, or values).

### Settings → Scripts (v1.0.0-beta.7.1.13.2)

Site admins configure the Global Script Manager from **Settings → Scripts** (a
dedicated admin page, permission `settings.manage`) instead of writing code. The
page has tabs for:

- **Enable** — a master toggle plus a diagnostics readout (valid / invalid entry
  counts, and why any entry was skipped).
- **Verification** — Google, Bing, Yandex, Facebook, Pinterest, Baidu. Paste the
  **value only**, not the meta tag; any pasted HTML is stripped.
- **JSON-LD** — repeatable `key` / `enabled` / JSON. Invalid JSON is skipped.
- **Head Scripts** / **Footer Scripts** — repeatable inline scripts
  (`key` / `enabled` / `priority` / `code`). Unsafe patterns are rejected.
- **External Scripts** — repeatable `key` / `enabled` / `position` (head or
  footer) / `priority` / `src`. Only allowlisted trusted hosts render.
- **Trusted Embeds** — repeatable iframe embeds (`key` / `enabled` / `position` /
  `priority` / iframe HTML). Iframe-only, host-allowlisted.

Configuration is stored in `cms_settings` under the `scripts.*` keys
(`scripts.enabled`, `scripts.verifications`, `scripts.json_ld`,
`scripts.head_inline`, `scripts.footer_inline`, `scripts.head_external`,
`scripts.footer_external`, `scripts.embeds`). At render time the
`ScriptSettingsRegistrar` registers them into the ScriptManager through the
`cms.scripts.rendering_head/footer` hooks — so **the same validation applies** and
nothing on this page can bypass it. Values are never executed as PHP.

**Settings-managed vs plugin-registered.** The `Enable` toggle
(`scripts.enabled`) controls **only** the scripts configured on this page. Scripts
a plugin registers directly through the `Script` facade / ScriptManager API
always render, regardless of the toggle. This lets an operator switch off all
manually-entered marketing tags without disabling functional plugin scripts.

`/cms-health` adds counts only: `script_settings_ready`,
`script_settings_enabled`, `script_settings_verification_count`,
`script_settings_json_ld_count`, `script_settings_inline_count`,
`script_settings_external_count`, `script_settings_embed_count` (never any script
value, URL, or key).

## Asset Registry (v1.0.0-beta.7.1.13.1)

The **Asset Registry** (`cms.assets`, facade `Asset`) loads CSS/JS **by handle**
with dependency resolution — a cleaner equivalent of WordPress `wp_enqueue_*`.
Use it for stylesheets and script files; use the Script Manager (above) for
meta/JSON-LD/embeds. There is no bundling, minification, or build step.

**Register, then enqueue.** Registering *defines* an asset; only *enqueued*
assets (and their dependencies) render:

```php
use TheNguyen\CMS\Facades\Asset;

// Define once (e.g. in a service provider) …
Asset::registerStyle('product', plugin_asset('ecommerce', 'css/product.css'), deps: ['tncms.frontend'], version: '1.0.0');
Asset::registerScript('checkout', plugin_asset('ecommerce', 'js/checkout.js'), deps: ['tncms.frontend'], version: '1.0.0', attributes: ['defer' => true]);

// … enqueue where it is actually needed (e.g. on the product page)
Asset::enqueueStyle('product');
Asset::enqueueScript('checkout');

// Inline config attached after a handle
Asset::inlineScript('checkout.config', 'window.TNCMS_CHECKOUT = {currency:"USD"};', after: 'checkout');
```

Helper equivalents exist for everything: `register_style()`, `register_script()`,
`register_module()`, `enqueue_style()`, `enqueue_script()`, `enqueue_module()`,
`inline_style()`, `inline_script()`, plus `tn_*` aliases.

**Dependencies.** `deps` lists handles that must render first. A dependency you
never enqueued is pulled in automatically. A missing dependency logs a warning
(`Asset::lastRenderWarnings()`) but the dependent still renders; a circular
dependency is broken safely.

**Scopes & positions.** `scope` is `frontend` (default), `admin`, or `both`;
`position` is `head` or `footer` (styles default head, scripts/modules default
footer). Rendering is bucketed by position: `render_frontend_styles()` (head)
and `render_frontend_scripts()` (footer) are called by the default theme;
`render_admin_styles()` / `render_admin_scripts()` are wired into the Filament
admin. A head-positioned script therefore renders in the head bucket.

**Security.** External `src` must be HTTPS on a trusted allowlist (jsDelivr,
cdnjs, unpkg, Google Fonts, esm.sh); relative/same-origin URLs are always fine.
`javascript:`, `data:`, `blob:`, `file:` and protocol-relative `//` are rejected,
as are `on*` attributes and unsafe inline JS/CSS. Every call returns
`true`/`false`; rejected assets never render and rendering never throws.

`/cms-health` adds `asset_registry_ready`, `registered_asset_count`,
`enqueued_frontend_asset_count`, and `enqueued_admin_asset_count` (counts only).

### Script & Asset Diagnostics (v1.0.0-beta.7.1.13.3)

A **passive** observability layer — validation and rendering are unchanged.

**Source metadata.** Every `Script`/`Asset` registration method takes an optional
trailing `source` array (`['source_type' => …, 'source_name' => …]`). `source_type`
is `core`, `settings`, `theme`, `plugin`, or `custom`; omit it and the source
defaults to `custom`. This lets diagnostics attribute each script/asset to where
it came from without changing anything that renders.

**Diagnostics.** `Script::diagnostics()` and `Asset::diagnostics()` return counts
only — `registered` / `rendered` / `rejected`, source/plugin/theme breakdowns,
warnings, and duplicates. They never expose script code, JSON-LD, verification
values, embed HTML, or URLs. **Settings → Scripts** now has a read-only
**Diagnostics** tab showing the same information for scripts and assets.

**Warnings** surface duplicate keys/handles, missing dependencies, unknown
sources, and rejected entries as short safe strings. Nothing throws; last-wins is
unchanged.

**Settings loading hook.** Plugins can inject settings-managed scripts via the new
`cms.scripts.settings.loading` action, which fires with the `ScriptManager` before
the stored `scripts.*` settings register.

`/cms-health` adds counts only: `script_source_count`, `asset_source_count`,
`script_warning_count`, `asset_warning_count`, `plugin_script_sources`,
`theme_script_sources`.

## Frontend Authentication (v1.0.0-beta.7.1.14)

TN CMS ships a secure **frontend login/registration** system on the same users
table and `web` guard as the admin — there is no separate customers table. Roles
decide what a user can do, and a frontend login never grants admin access.

**Routes** (unprefixed, registered before the page catch-all):
`/login`, `/register`, `/forgot-password`, `/reset-password/{token}`,
`/email/verify`, `/logout`. Basic themed pages ship under `cms::auth.*`.

**Registration & default role.** New sign-ups are enabled by
`auth.registration_enabled` and receive `auth.default_role_id`. If that role is
unset/missing, they get the safe, permission-less **`subscriber`** role (created
on demand). Never point the default at an admin role.

```php
// Read/observe the current frontend user anywhere:
if (is_frontend_authenticated()) {
    $user = frontend_user();          // or current_user()
}

// Protect routes with the frontend middleware:
Route::middleware(['web', 'cms.auth'])->get('/dashboard', ...);
Route::middleware(['web', 'cms.auth', 'cms.role:subscriber'])->get('/members', ...);
Route::middleware(['web', 'cms.auth', 'cms.verified'])->get('/secure', ...);
```

**Sessions, remember me, and device changes.** Without *remember me* a session
lasts `auth.frontend_session_lifetime_minutes` (default 24h); with it,
`auth.frontend_remember_lifetime_minutes` (default 7d). Security is layered so a
stolen cookie stops working:

- Logging in on a **new device** (different browser/User-Agent) or **changing
  your password** invalidates older sessions and remember cookies.
- Every protected request checks a per-user session version; a mismatch logs the
  stale session out.
- An optional `auth.idle_timeout_minutes` expires idle sessions.

Only a hash of the User-Agent is stored (never the raw string), and IP is
recorded for audit only. All of this is configurable under the Core `auth.*`
settings group (with `config/cms.php` fallbacks).

**Email verification** is off by default; enable
`auth.email_verification_required` and gate routes with `cms.verified`.
Registration itself is never blocked — only protected routes are.

**Extending.** Actions (`cms.auth.registered`, `cms.auth.login.success`,
`cms.auth.logout`, `cms.auth.session_invalidated`, …) and filters
(`cms.auth.default_role_id`, `cms.auth.registration_data`,
`cms.auth.redirect_after_login`, `cms.auth.redirect_after_logout`) let plugins
hook the whole flow. `/cms-health` adds `frontend_auth_ready`,
`frontend_registration_enabled`, `frontend_default_role_configured`,
`frontend_session_policy_ready`, and `frontend_email_verification_available`.

## Account Foundation (v1.0.0-beta.7.1.15)

The logged-in **account area** for frontend users, built on Frontend
Authentication. After login, users manage their account at:

- `/account` — dashboard (greeting + overview + quick links)
- `/account/profile` — name, username, phone, bio, optional avatar URL
- `/account/security` — change password, change email (both require the current
  password)
- `/account/sessions` — current session facts + "log out other sessions"
- `/account/preferences` — site / admin / content-editing language (each
  independent) and timezone

All routes require login (`cms.auth` + `cms.frontend_session`); sensitive POSTs
are throttled and CSRF-protected. Pages render on a self-contained core layout
(`cms::account.layout`) — no theme changes required.

**What Core owns:** identity, profile basics, email/password changes, and locale
preferences. **What plugins own:** everything business-specific — Orders,
Addresses, Wishlist, Invoices, Downloads, VIP, Courses, Forum Topics, etc. Core
never ships those.

**Email change** resets verification state (when enabled) and logs out other
sessions. **Password change** bumps the session version, rotates the remember
token, and regenerates the session — old stolen sessions die.

**Extending.** Plugins add account sections through hooks. Add a navigation item:

```php
add_filter('cms.account.navigation_items', function (array $items) {
    $items[] = new \TheNguyen\CMS\Support\Account\AccountNavItem(
        key: 'orders', label: __('Orders'), url: '/account/orders',
        icon: 'box', priority: 60, badge: '3',
    );

    return $items;
});
```

Inject markup into any account page via the render-area actions, e.g.:

```php
add_action('cms.account.dashboard.after', function () {
    echo view('shop::account.recent-orders')->render();
});
```

Available render areas: `cms.account.before`, `cms.account.after`,
`cms.account.sidebar.before/after`, `cms.account.navigation`,
`cms.account.dashboard.before/after`, `cms.account.profile.after`,
`cms.account.security.after`, `cms.account.preferences.after`. Lifecycle actions
(`cms.account.{profile,email,password,preferences}.{updating,updated}`,
`cms.account.sessions.invalidated`) and filters (`cms.account.navigation_items`,
`cms.account.profile_data`, `cms.account.preferences_data`,
`cms.account.redirect_after_update`) cover the rest. `/cms-health` adds
`account_foundation_ready`, `account_routes_ready`, `account_hooks_ready`, and
`account_profile_fields_ready`.
