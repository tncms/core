# TheNguyen CMS Changelog

All notable changes to **TheNguyen CMS** (`laravel-cms`) are documented in
this file.

The format is based on [Keep a Changelog](https://keepachangelog.com) and
this project adheres to [Semantic Versioning](https://semver.org).

> Conventions used here:
>
> - `Added` — new features.
> - `Changed` — changes to existing behaviour.
> - `Fixed` — bug fixes.
> - `Removed` — features that were removed or replaced.
> - `Notes` — context that future maintainers / AI assistants will need.

---

## [1.0.0-beta.7.1.26] — Active-Theme Diagnostics Authority Unification (CORE-THEME-3) — 2026-09-05

Fixes the active-theme split-brain where the Dashboard environment summary
reported `default` while the frontend, Themes page, Theme Options, Import Demo
and the page-template registry all reported the committed active theme.

### Fixed

- **Dashboard diagnostics follow the committed active theme.**
  `CmsInfo::activeTheme()` now resolves the single canonical authority
  (`cms_settings` `theme.active` via `ThemeManager::active()`) instead of the
  stale `config('cms.theme.active')` (== `env('CMS_ACTIVE_THEME')`), which is only
  the install seed and is never updated on activation. The environment-summary
  widget now shows `Name (slug)` (e.g. `Ngo Hoang Nguyen (ngohoangnguyen)`)
  derived from that same authority. Config/`.env` is retained strictly as a
  pre-install/recovery bootstrap fallback.

### Notes

- No new persisted state, cache key or config is introduced; the frontend theme
  still never skins the Filament admin shell (diagnostics only *report* the
  committed frontend theme). See
  `docs/platform-runtime/ADR-CORE-THEME-003-ACTIVE-THEME-DIAGNOSTICS-AUTHORITY.md`.
- `config:cache`/`optimize`/process restart no longer drift the reported theme,
  because the authority is the DB pointer, not the compiled config.
- Certified over real authenticated HTTP, process restart and an authentic
  public `1.0.0-beta.7.1.25` → `1.0.0-beta.7.1.26` upgrade (committed non-Default
  theme preserved; `.env`/APP_KEY/content/settings unchanged).

---

## [1.0.0-beta.7.1.25] — Active-Theme Page Templates & Theme-Scoped Demo Import (CORE-THEME-2)

Themes converted from real HTML templates can now preserve their genuine page
compositions: a theme declares a finite set of page templates that editors pick
per Page, and ships declarative demo presets that Core previews, imports and
rolls back safely — always scoped to the active theme.

### Added

- **Active-theme page templates.** `theme.json` may declare a `page_templates`
  list (stable `id`, translatable `label`, theme-relative `view`, optional
  `description`). Declarations are strictly validated (id/view syntax, no
  traversal/namespace/absolute paths, the view must exist inside the theme's
  own hierarchy, child overrides parent deterministically) and an invalid
  declaration fails theme activation closed. A Page stores only the stable
  identifier (`cms_contents.template`); Core resolves it at render time
  through the active theme's validated allowlist — never a raw Blade path and
  never a per-view fallback to an inactive theme. An empty, undeclared or
  unavailable identifier renders the theme's canonical page view with a logged
  diagnostic.
- **Validated Template selector.** The Page editor's free-text Template input
  is replaced by a Select sourced from the active theme's declarations
  (labels via `theme_trans()`, EN/VI admin strings included). A stored value
  the current theme does not declare is shown as *unavailable* — preserved,
  never silently destroyed — and server-side validation mirrors the allowlist.
- **Demo preset `pages` file.** A theme demo preset may declare Pages with
  localized translations, a page-template identifier (validated against the
  owning theme's declarations), a publish status, `show_page_title`, and an
  optional static-homepage assignment. Core owns every write (transactions,
  slug uniqueness, revisions); symbolic keys map to created rows so re-import
  is idempotent; reset removes only importer-created pages and restores the
  captured pre-import homepage settings.
- **Demo import preview.** A read-only dry-run action reporting what an import
  would create, update, set or skip — including template-availability
  warnings — before any write.
- **Theme Example.** `examples/themes/example-theme` (shipped in the source
  package) now demonstrates the full public theme contract: required views,
  declarative assets, one custom page template (`landing`) with translated
  labels, and a minimal theme-scoped starter preset (pages + menu) with
  ownership/escaping documentation.

### Changed

- **Demo import is scoped to the active theme.** Appearance → Import Demo
  lists the active theme's presets only (plus active-plugin packages); an
  inactive theme's already-imported preset stays listed for reset only, and
  importing an inactive theme's preset is refused server-side. A theme without
  presets exposes none. Existing installations keep their imported content —
  nothing is re-imported, duplicated or deleted on upgrade or theme switch.

### Notes

- Page templates choose presentation; demo presets create optional starter
  content — the two contracts are deliberately independent.
- The bundled Default theme already used the generic theme-owned demo format;
  Core retains only generic importer infrastructure.

## [1.0.0-beta.7.1.24] — Active Theme Authority & Theme Lifecycle (EG-6)

The active theme becomes the single presentation authority: `theme.json` gains a
declarative asset manifest and explicit parent/child semantics, and theme asset
publication and activation are atomic with fail-closed validation and rollback.
Also ships the CORE-FRONTEND-1 frontend contracts (theme-aware error pages and
search noindex) on top of the public `1.0.0-beta.7.1.23` floor.

### Added

- **Declarative theme asset manifest.** `theme.json` may declare an `assets`
  array (handle, src, type, position, deps, primary, defer/async, media,
  version, replaces). A resolver produces an owner-aware, dependency-ordered
  plan fed to the Asset Registry and rendered via
  `render_frontend_styles()` / `render_frontend_scripts()`. Strict validation
  (unique handle, safe normalized path, single primary, dependency DAG, no
  impossible head→footer ordering) makes an invalid manifest fail activation
  closed. The imperative `theme_asset()` path remains supported.
- **Explicit parent/child themes.** `theme.json "parent"` (never inferred)
  drives deterministic, cycle- and depth-guarded child→parent resolution for
  views (child overrides, parent supplies, no implicit Default mix) and assets
  (inheritance, `replaces` semantics, owner-aware URLs). Active-child parent
  lifecycle protection prevents deleting a parent an active child depends on.
- **Atomic theme asset publication** (`ThemeAssetPublisher::publishAtomic`):
  validate → stage → verify → snapshot → promote → verify, with rollback of the
  previous live assets on failure (Windows/shared-hosting-safe swap).
- **Theme-aware frontend error pages** (CORE-FRONTEND-1). Frontend exceptions
  render through the active theme (`theme::errors.{status}` →
  `theme::errors.error` → Core-owned fallback) with the real HTTP status
  preserved — a genuine 404 is never a redirect. JSON/API, admin, installer,
  upgrade and Livewire surfaces, authentication and validation responses are
  never themed, and production 500s leak no exception internals.
- **Search noindex policy** (CORE-FRONTEND-1). The search surface emits
  `noindex, follow` through the new `SeoManager::noindex()` seam; localized
  search routes keep their canonical behaviour.

### Changed

- **Active theme is the single presentation authority (EG-6).** `theme::` view
  namespace resolves to the active theme (standalone) or child→parent chain,
  never a per-view Default fallback; Default participates only as a whole-
  authority fallback or when itself active. `ThemeManager::activate()` validates
  the manifest + required views across the chain and publishes the chain
  atomically before committing the active-theme pointer.
- **Default theme** now declares its assets in `theme.json` and renders them
  through the Asset Registry instead of hard-coded tags (no behaviour change to
  the served output or asset URLs).

### Notes

- Reconciles the public `1.0.0-beta.7.1.23` authority with the previously
  unreleased CORE-FRONTEND-1 (themed errors + search noindex) and EG-6
  view-authority fix into a single release lineage.
- Independently developed standalone themes are compliant under the corrected
  contract and require no migration; the public Core release continues to bundle
  the Default theme only.

## [1.0.0-beta.7.1.23] — Page Title Visibility Contract (CORE-RELEASE-4-A)

A generic, Core-owned presentation contract that lets an editor decide whether the
visible page title (the document primary heading) is rendered above the content,
plus the bundled Default Theme adapter that honors it. This closes the release-order
dependency for the Page Builder Free Library Show/Hide consumer. Follows the public
`1.0.0-beta.7.1.22` release; the intervening `7.1.22` train identifier was published
from the separate core-release line.

### Added

- **`cms_contents.show_page_title`** — an additive, `NOT NULL`, default-`true` boolean
  column (idempotent migration; rollback drops only the column it owns). Existing rows
  and fresh installs keep showing their title (pre-contract behaviour) with no backfill.
- **Model + service contract.** `Content` casts `show_page_title` to boolean and
  allowlists it; `ContentManager` preserves the stored value when the field is omitted
  from an unrelated update, and an explicit `false` is never coerced to “missing”.
- **Admin control.** A bounded page-form toggle (**Show page title** / **Hiển thị tiêu
  đề trang**) injected through the `cms.form.schema.page` hook immediately after the
  editing-language selector — no host/Filament resource edit; present even without
  Page Builder. Defaults to Show.
- **Core→Theme presentation contract.** The page render context exposes a boolean
  `showPageTitle` (safe `?? true` fallback), consumed by the bundled Default Theme
  page template via `@if ($showPageTitle ?? true)`. Show renders the existing
  `<h1 class="entry-title">` wrapper; Hide omits the wrapper entirely.

### Removed

- The two certification-only upgrade-probe migrations (`cms_upgrade_probe`,
  `cms_upgrade_probe2`) — never part of the public `1.0.0-beta.7.1.22` release, with no
  production/updater/manifest/test dependency.

### Notes

- Hiding the visible title is a **presentation** preference only: the SEO `<title>`,
  canonical URL, hreflang, slug, stored content title, navigation label, Admin label,
  and Page Builder layout identity are all unchanged.
- The Page Builder consumer already reads this contract read-only. The built-in
  `hero-basic` starter template supplies its own `<h1>` heading, so with the title
  shown it composes two `<h1>` elements; that is a Page-Builder-owned limitation to be
  addressed in a later Page Builder release, not by this Core/Theme contract.

## [1.0.0-beta.7.1.21] — First-Run Environment Bootstrap (CORE-INSTALLER-2)

Shared-hosting first-run redesign. `.env` is now an explicit **result** of a
successful installer commit, never a file silently created just so the first HTTP
request could encrypt a cookie. No end-user CLI is required for a normal install.

### Added

- **Ephemeral pre-install bootstrap key.** A file-backed, cryptographically secure
  key (`storage/framework/tncms-installer.key`) lets a keyless fresh extract boot
  cookie/session encryption before any `.env` exists. It is stable across wizard
  requests, never written to `.env`, never logged/rendered, and is removed the
  moment the permanent `.env` is committed and the runtime holds the permanent key
  (`InstallerBootstrapKey`, `EnsureInstallerAppKey`).
- **Atomic `.env` creation.** `AtomicEnvWriter` writes temp → flush → validate →
  rename so a failed commit leaves no partial, secret-bearing `.env`; a previous
  valid `.env` is preserved on failure.
- **Single install commit.** `InstallerManager::commitEnvironment()` is the only
  place a permanent `.env` is created — it generates a unique permanent `APP_KEY`
  on a fresh install and **reuses** (never rotates) an existing key on a resume,
  then switches the running process to the permanent key.
- **Review step + explicit `/install/run` commit** in the wizard: welcome →
  requirements → configure → admin → review → (commit) → finish. Configuration is
  collected server-side; nothing touches `.env` until the review step is confirmed.
- **Friendly first-run routing.** A fresh, unconfigured extract redirects any
  non-installer request to `/install` (`RedirectToInstaller`) instead of showing a
  raw error. Installed sites are unaffected.
- **Distribution verifier gates:** artifacts hard-fail if they carry the ephemeral
  bootstrap key, an install lock, or a fixed universal `APP_KEY` in runtime config.
- `APP_TIMEZONE` is now honored by `config/app.php` so the installer-chosen timezone
  actually applies.

### Changed

- The pre-install runtime no longer writes `.env` on the first request. The DB test
  step no longer persists connection details. State model: `FRESH` (no `.env`, no
  marker) → `CONFIG_COMMITTED_NOT_INSTALLED` (valid `.env` + permanent key, no
  marker) → `INSTALLED` (marker). `.env` existence alone never means installed.
- `.env` values with a bare `=` (e.g. the base64 `APP_KEY`) are written unquoted, to
  match Laravel's `key:generate` convention.

### Notes

- Failure after the `.env` commit preserves the permanent `APP_KEY` and allows a safe
  retry without rotating the key or duplicating the admin.
- Upgrade continues to preserve a site's `.env` and `APP_KEY`; the installer owns
  only initial `.env` creation.

## [1.0.0-beta.7.1.20] — Composer TLS & Zero-CLI APP_KEY Hygiene (CORE-RELEASE-1-H2)

Security/release hardening. No Core functionality changes.

### Fixed

- **Composer TLS bypass removed from every shipped artifact.** The root
  `composer.json` no longer carries the Laragon build-host workarounds
  `disable-tls: true`, `secure-http: false`, and a machine-specific
  `cafile: C:/laragon/…`. Public Composer configuration now uses secure defaults
  (TLS verification on). The release build's own `composer install` was verified
  to work securely on the build host with defaults (Composer's bundled
  `composer/ca-bundle`), so no CA path is needed in, or ships with, any artifact.
  `composer.lock` is unchanged (the `config` block is not part of the content
  hash); `composer validate` passes.

### Added

- **Distribution verifier gates (CORE-RELEASE-1-H2):**
  - `composer:no-tls-bypass` — hard-fails any profile whose `composer.json`
    ships `disable-tls=true`, `secure-http=false`, or a `cafile`/`capath`.
  - `app-key:no-shipped-key` — hard-fails any profile that ships a non-empty
    `APP_KEY` in `.env.example`/`.env` (no fixed universal production key).

### Notes

- **Zero-CLI APP_KEY (unchanged, re-certified).** The installer already generates
  a unique, cryptographically secure `base64:random_bytes(32)` per fresh site on
  the first pre-install request (`InstallerManager::ensureRuntimeAppKey`, via the
  not-installed-only `EnsureInstallerAppKey` middleware) and never rotates an
  existing key (`ensureAppKey`); upgrades preserve `.env`/`APP_KEY` under the
  CORE-UPGRADE-1 preserve authority. No fixed universal key is shipped;
  `.env.example` carries an empty `APP_KEY=`. H2 adds artifact gates + tests + a
  live extracted-package HTTP certification proving keyless `/install`,
  per-installation key uniqueness, key persistence, and upgrade preservation.
- Certified bytes changed (`composer.json` + verifier); the version advances per
  the release/version authority. `v1.0.0-beta.7.1.18` and its immutable public
  release are untouched. The Core-only boundary (hello-world + default theme) is
  preserved.

## [1.0.0-beta.7.1.19] — Public Source Release Hygiene (CORE-RELEASE-1-H1)

Packaging-only release. No runtime behaviour changes. Closes two public-source
release-hygiene debts found during CORE-PUBLISH-1 remote certification.

### Fixed

- **Monorepo-only test leakage.** The `source` distribution profile no longer
  ships `tests/Feature/RepositorySourceBoundaryTest.php`. That guard asserts the
  development monorepo keeps Page Builder source and monorepo test paths
  git-tracked — paths intentionally absent from standalone `tncms/core`, where it
  false-failed. The guard is retained in the monorepo and excluded only from the
  public source profile (`manifest.php` `exclude_tests`).
- **`.gitignore` hides legitimate public source.** The shipped `.gitignore` is now
  sanitized during staging to drop the rules that ignore force-tracked public
  files (`README.md`, `CMS_*.md`, `PLUGIN_DEVELOPMENT.md`, `THEME_DEVELOPMENT.md`,
  `plugins/hello-world/plugin.json`). A fresh `git init && git add -A` in the
  extracted public tree now stages the complete corpus with no `git add -f`. Every
  runtime/private/generated ignore (`vendor/`, `node_modules/`, `.env`, build
  output, `.tokensave/`, …) is preserved verbatim.

### Added

- **Public Git corpus equivalence gate** (`verify.php`
  `tncms_core_git_corpus_gate()`), wired into the `source` publisher and the
  unified release builder. It exercises REAL git (a build/certification dependency
  only, never an end-user runtime dependency) to prove
  `SOURCE_ZIP_EXTRACTION == FILES_STAGED_BY_PLAIN_GIT_ADD_A`, failing closed on any
  missing, unexpected, or force-added path.

### Notes

- Certified bytes changed (source profile file set + shipped `.gitignore`), so the
  version advances per the release/version authority. `v1.0.0-beta.7.1.18` and its
  immutable public release are untouched. `install` and `upgrade` profiles are
  unaffected; the Core-only boundary (hello-world + default theme) is preserved.

## [1.0.0-beta.7.1.18] — Open-Source Publication Readiness (MIT)

Publication-preparation release for the first public open-source distribution of
TNCMS Core (CORE-PUBLISH-1). No runtime behaviour changes.

### Added

- Official **MIT `LICENSE`** at the repository root, now included in the
  `source`, `install`, and `upgrade` distribution profiles and required by the
  distribution verifier.
- Public open-source project metadata: a rewritten public `README.md`, plus
  `SECURITY.md`, `CONTRIBUTING.md`, and `SUPPORT.md`.

### Changed

- Root `composer.json` description, homepage, keywords, authors, and support
  metadata updated for the public project (license remains MIT; the application
  skeleton package name is retained deliberately — see CORE-PUBLISH-1 notes).

### Notes

- Certified through CORE-RELEASE-1 as a Core-only release (bundled
  `plugins/hello-world` + `themes/default`; all other plugins/themes absent).
  Prepared for publication to `https://github.com/tncms/core`; this release does
  not itself publish to GitHub.

## [1.0.0-beta.7.1.17] — Manual Web Core Upgrade (certification target)

Forward release used as the certified upgrade target for the manual web upgrade
workflow. Reads the Core ownership model from the shipped ownership record so
`/upgrade` works on a real installed site (no dev-manifest dependency), and adds
`cms_upgrade_probe2` to exercise the migration stage of a genuine
v7.1.16 → v7.1.17 upgrade.

## [1.0.0-beta.7.1.16] — Manual Web Core Upgrade

Adds the first safe, authenticated **manual Core upgrade** workflow at `/upgrade`
(CORE-UPGRADE-1). A Super Admin uploads an official Core upgrade package; the
wizard verifies the package, checks the system, creates and **verifies** a full
backup (PHP-native database dump + Core files + `.env`), then applies the upgrade
as a clean **replace-not-merge** of Core-owned release state with obsolete-file
removal, migrations, cache refresh and health checks — rolling back
automatically from the verified backup on failure.

### Added

- `/upgrade` wizard (Package → System Check → Backup → Ready → Upgrade → Finish),
  Super-Admin gated, registered only on an installed site.
- Upgrade engine under `TheNguyen\CMS\Upgrade`: durable state machine + lock,
  package verification + safe staging, Core file/`.env` backup with a hard
  verification gate, replace-not-merge apply + stale Core removal, health checks
  and automatic recovery. Single `cms.upgrade` service.
- PHP-native MySQL/MariaDB backup/restore (no `mysqldump`/shell dependency).
- `system.upgrade.manage` permission; `cms_upgrade_probe` schema probe.
- Distribution `upgrade` package profile + `core_ownership` model.

### Notes

- Never extract an install or upgrade ZIP over a live site — use `/upgrade`.

## [1.0.0-beta.7.1.13.4] — Theme Custom CSS

Adds a Core-level **Theme Custom CSS** feature to Theme Options. Two multiline
editors (Frontend CSS, Admin CSS) whose values are validated, stored in the
canonical Theme Options namespace, and rendered **through the existing Asset
Registry** — never echoed directly from Blade or the settings page. No Custom
JS, no CSS editor/compiler/minifier/bundler. `AssetRegistry`, Theme rendering,
Theme Options architecture, Script Manager, and settings are **unchanged**; no
vendor edits.

### Added

- **Custom CSS tab in Theme Options** — a "Custom CSS" section (always present,
  even for a theme that declares no option schema) with two plain multiline
  editors: **Frontend CSS** and **Admin CSS**.
- **`ThemeCustomCssManager`** (`cms.theme_custom_css`) — validates, stores,
  reads, and registers Custom CSS. Storage travels with Theme Options under the
  canonical namespace keyed by the active theme slug:
  `theme_options.{slug}.custom_css_frontend` and
  `theme_options.{slug}.custom_css_admin` (via `ThemeOptionManager`). No new
  tables, no loose `theme.custom_css_*` / `custom_css.*` / `scripts.*` keys.
- **Asset Registry rendering** — the manager registers valid CSS as inline
  styles via `AssetRegistry::inlineStyle()`: frontend CSS scoped to the frontend
  head, admin CSS scoped to the admin head. The registry's own scope filtering
  keeps each from leaking into the other surface. Registration is wired once at
  boot (inline styles are active on registration), so **no new rendering hook**
  was added.
- **Source metadata** — every generated inline style is tagged
  `source_type=settings` / `source_name=theme-options`, so it appears
  automatically in **Asset Diagnostics** (`sources.settings`).
- **Validation** (never throws; invalid CSS is neither stored nor rendered, and
  the UI shows the rejection reasons) — rejects `</style`, `<script`,
  `javascript:`, `vbscript:`, `expression(`, `behavior:`, dangerous `@import`
  (remote/protocol-relative URLs and `javascript:`/`vbscript:`/`data:`), NUL
  bytes and unsafe control characters, and any payload over **256 KB** per field.
- **Optional hook** — `cms.theme.custom_css.loading` fires (with the
  `AssetRegistry`) before Custom CSS is registered, letting plugins inject first.
- **Health** (`/cms-health`, sizes only — never CSS contents):
  `theme_custom_css_enabled`, `theme_custom_css_frontend_size`,
  `theme_custom_css_admin_size`.

### Notes

- `CmsInfo::VERSION` is left at its current, higher value; this entry documents
  the phase, not a version bump.
- Custom CSS is stored per active theme, so switching themes swaps the applied
  CSS — consistent with Theme Options travelling with the theme.

## [1.0.0-beta.7.1.13.3] — Script & Asset Diagnostics

Final Foundation polish for the Script & Asset subsystem: **diagnostics,
observability, and source metadata only**. The `ScriptManager`, `AssetRegistry`,
Global Script Settings, and the render pipeline are **unchanged** — no rendering
logic was rewritten. No SEO/Analytics/Consent/CSP/Export/Import/Bundler/Optimizer
features; no vendor edits. Diagnostics are strictly **passive**.

### Added

- **Source metadata** — every registered script/asset can optionally carry
  `source_type` (`core` | `settings` | `theme` | `plugin` | `custom`) and
  `source_name` (e.g. `theme:default`, `plugin:ecommerce`). Passed as an
  optional trailing `array $source = []` on every registration method
  (`Script::head/footer/externalHead/externalFooter/meta/verification/jsonLd/embed`
  and `Asset::registerStyle/registerScript/registerModule/registerMarker/style/
  script/inlineStyle/inlineScript`). **Omitted → defaults to `custom`.** The old
  API is 100% unchanged; existing calls keep working.
- **`ScriptManager::diagnostics()` / `AssetRegistry::diagnostics()`** — metadata-only
  snapshots: `registered`, `rendered`, `rejected`, `sources` (counts by type),
  `plugins`, `themes`, plus `settings` (script) and `dependencies` (asset)
  breakdowns, `warnings`, and `duplicate_keys` / `duplicates`. They never return
  script code, inline JavaScript, JSON-LD content, verification values, embed
  HTML, or URLs.
- **`ScriptManager::warnings()` / `AssetRegistry::warnings()`** — short, safe
  warning strings: duplicate handle/key, missing dependency, unknown source,
  and rejected-entry reasons.
- **Warning system** — duplicate handles/keys and unknown source types are
  detected passively and recorded as warnings. Last-wins behavior is unchanged;
  nothing throws.
- **Settings loading hook** — new action `cms.scripts.settings.loading` fires
  (with the target `ScriptManager`) before admin-managed `scripts.*` settings are
  registered, letting plugins inject settings-managed scripts. Best-effort; a
  misbehaving listener never breaks rendering.
- **Admin Diagnostics** — **Settings → Scripts** gains a read-only **Diagnostics**
  tab showing registered/rendered/rejected counts, source/plugin/theme/settings
  breakdowns, and warnings for scripts and assets.
- **Health** (`/cms-health`, counts only): `script_source_count`,
  `asset_source_count`, `script_warning_count`, `asset_warning_count`,
  `plugin_script_sources`, `theme_script_sources`.

### Notes

- Settings-managed scripts are now tagged `source_type = settings`, so the
  settings breakdown and health counts attribute them correctly.
- `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15` (> this phase label); it is
  intentionally **not** downgraded. This is an additive diagnostics phase over the
  `beta.7.1.13` / `beta.7.1.13.1` foundations.

---

## [1.0.0-beta.7.1.13.2] — Global Script Settings UI

Admin UI **only** for the existing Global Script Manager (`beta.7.1.13`). No
changes to the ScriptManager itself; no SEO/Analytics/Consent/CSP features; no
Asset Registry, Frontend Auth, Widget, Preview, or Installer changes.

Adds **Settings → Scripts** (a dedicated Filament page, `settings/scripts`, in the
CMS nav group, gated by `settings.manage`) so site admins can configure
verification meta, JSON-LD, head/footer inline scripts, external head/footer
scripts, and trusted iframe embeds. Everything is stored in `cms_settings` under
the `scripts.*` keys; **nothing executes** — no PHP, no callbacks. Every value is
validated by the ScriptManager at render time and invalid entries simply never
render.

### Added

- **`ScriptSettingsRegistrar`** (`cms.script_settings`) — reads the `scripts.*`
  settings and registers them into the ScriptManager via the existing
  `cms.scripts.rendering_head` / `cms.scripts.rendering_footer` action hooks
  (idempotent — the ScriptManager de-duplicates by key). Provides `apply()`
  (enabled-gated render bridge), `registerInto()` (pure), `diagnostics()`
  (valid/invalid counts + safe skip reasons), and `healthSnapshot()`.
- **`ScriptSettingsPage`** (Filament) with tabs: Enable (+ diagnostics),
  Verification, JSON-LD, Head Scripts, Footer Scripts, External Scripts, Trusted
  Embeds.
- **Settings keys** (JSON arrays where repeatable): `scripts.enabled`,
  `scripts.verifications`, `scripts.json_ld`, `scripts.head_inline`,
  `scripts.footer_inline`, `scripts.head_external`, `scripts.footer_external`,
  `scripts.embeds`. No new tables.
- **Health** (`/cms-health`, counts only — never any script value/URL/key):
  `script_settings_ready`, `script_settings_enabled`,
  `script_settings_verification_count`, `script_settings_json_ld_count`,
  `script_settings_inline_count`, `script_settings_external_count`,
  `script_settings_embed_count`.

### Behavior

- **Validation:** verification values are `strip_tags`-cleaned on save (paste the
  value, not the meta HTML); JSON-LD must be valid JSON; inline scripts, external
  URLs, and iframe embeds all pass through ScriptManager validation. Invalid
  entries are skipped and never render; the save page shows a warning with the
  invalid count, and the Enable tab shows valid/invalid diagnostics.
- **`scripts.enabled = false`** disables ONLY settings-managed scripts. Scripts a
  plugin registers directly through the `Script` facade / ScriptManager API always
  render (the enabled gate never touches them).
- **Idempotent** per request; duplicate keys do not duplicate output. Render never
  throws on malformed stored settings.

### Notes

- `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15` (> this phase label); it is
  intentionally **not** downgraded. This entry is an additive UI phase over the
  `beta.7.1.13` foundation.

---

## [1.0.0-beta.7.1.15] — Account Foundation

A frontend **Account area** for logged-in users, built on the existing
`beta.7.1.14` frontend-auth system (shared `users` table + `web` guard). Core
owns **only** user identity, profile basics, email/password changes, locale
preferences, the account shell, session/security basics, and hook points.

Core does **not** own customer/business data. **No** customers table, addresses,
orders, invoices, wishlist, VIP/membership, downloads, checkout, or payments —
those are plugin domains (Ecommerce/Forum/Membership/CRM/LMS) injected through
the account hooks. The Ecommerce plugin was **not** modified.

### Database

- `add_account_profile_fields_to_users_table` migration adds (only if missing):
  `username` (unique), `avatar`, `phone`, `bio`, `timezone`. Locale preferences
  reuse the existing `frontend_locale` / `admin_locale` / `editing_locale`
  columns — no `profile_locale`. No customers/orders/addresses tables.
- `User::$fillable` extended with the identity/profile fields above.

### Added

- **`AccountManager`** (`cms.account`) — all account business logic: profile,
  email, and password updates; independent locale/timezone preferences; session
  rotation on sensitive changes; extensible account navigation; health snapshot.
  Controllers stay thin.
- **`AccountNavItem`** (`Support/Account/`) — immutable value object
  (`key/label/url/icon/priority/active/permission/badge`) shared by core and
  plugin navigation entries.
- **`FrontendAuthManager::rotateSessionsExceptCurrent()`** — new primitive that
  bumps the session version + rotates the remember token (logging out other
  devices) while re-seeding the current session. Reused by email change and
  "log out other sessions".
- **Controllers** (`Http/Controllers/Account/`): `AccountController`,
  `AccountProfileController`, `AccountSecurityController`,
  `AccountSessionController`, `AccountPreferenceController`.
- **Routes** (`routes/account.php`, registered before the frontend catch-all,
  all behind `web` + `cms.auth` + `cms.frontend_session`; sensitive POSTs
  throttled): `GET /account`, `GET|POST /account/profile`, `GET /account/security`
  + `POST /account/security/password` + `POST /account/security/email`,
  `GET /account/sessions` + `POST /account/sessions/logout-others`,
  `GET|POST /account/preferences`.
- **Views** (`cms::account.*`) on a self-contained core layout
  (`cms::account.layout`) with a sidebar, flash messages, and hook areas — no
  theme changes required.
- **Account hooks** (defined in the core hook registry, group `Account`):
  - Actions: `cms.account.profile.updating|updated`,
    `cms.account.email.updating|updated`,
    `cms.account.password.updating|updated`,
    `cms.account.preferences.updating|updated`,
    `cms.account.sessions.invalidated`, and render areas
    `cms.account.before|after`, `cms.account.sidebar.before|after`,
    `cms.account.navigation`, `cms.account.dashboard.before|after`,
    `cms.account.profile.after`, `cms.account.security.after`,
    `cms.account.preferences.after`.
  - Filters: `cms.account.navigation_items`, `cms.account.profile_data`,
    `cms.account.preferences_data`, `cms.account.redirect_after_update`.
- **Health** (`/cms-health`): `account_foundation_ready`,
  `account_routes_ready`, `account_hooks_ready`, `account_profile_fields_ready`
  (booleans only; no user data).

### Security

- Email and password changes and "log out other sessions" require the current
  password (validated against the stored hash) and CSRF; sensitive POSTs are
  throttled (`throttle:6,1`).
- Email change resets verification state (when enabled) and rotates other
  sessions. Password change bumps the session version, rotates the remember
  token, and regenerates the current session id + CSRF token, so old stolen
  sessions become invalid.

### Example — a plugin adding an "Orders" menu item

```php
add_filter('cms.account.navigation_items', function (array $items) {
    $items[] = new \TheNguyen\CMS\Support\Account\AccountNavItem(
        key: 'orders', label: __('Orders'), url: route('shop.account.orders'),
        icon: 'box', priority: 60,
    );

    return $items;
});
```

### Notes

- `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15`; this entry is the phase
  label and does not change the version.

---

## [1.0.0-beta.7.1.14] — Frontend Identity & Authentication Foundation

A secure frontend user authentication system built on the **existing shared
users table** and the **existing `web` guard**. Users are shared across frontend
and admin; roles decide capability. Frontend login never grants admin access on
its own. **No** separate customers table, customer dashboard, checkout, orders,
payments, social login, or 2FA — foundation only.

> **Version note:** `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15`. This is
> the phase label only and does **not** downgrade the version. (A separate,
> earlier "Section Data Source Extension Point" entry further down also carries
> the `7.1.14` label; the two are distinct changes on the same version line.)

### Database

- `add_frontend_auth_to_users_table` migration adds (only if missing):
  `frontend_last_login_at`, `frontend_last_login_ip`,
  `frontend_last_user_agent_hash`, `frontend_session_version` (default 1),
  `remember_token_rotated_at`. No customers/profiles/addresses tables.
- `User` now implements `MustVerifyEmail`; new datetime/int casts for the
  columns above.

### Added

- **`FrontendAuthManager`** (`cms.frontend_auth`) — register, login, logout,
  password change, session rotation, device-fingerprint validation, role
  assignment, and hook emission. Controllers stay thin.
- **Controllers** (`Http/Controllers/Auth/`): `FrontendLoginController`,
  `FrontendRegisterController`, `FrontendPasswordResetController`,
  `FrontendEmailVerificationController`.
- **Middleware aliases:** `cms.auth`, `cms.guest`, `cms.role`, `cms.permission`,
  `cms.verified`, `cms.frontend_session`.
- **Routes** (`routes/auth.php`, registered before the frontend catch-all,
  unprefixed): `GET|POST /login`, `POST /logout`, `GET|POST /register`,
  `GET|POST /forgot-password`, `GET /reset-password/{token}` +
  `POST /reset-password`, `GET /email/verify`, signed
  `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification`.
  Uses framework-standard route names (`password.reset`, `verification.verify`)
  so Laravel's built-in notifications resolve without extra wiring.
- **Views** (`cms::auth.*`) on a minimal, self-contained core layout
  (`cms::layouts.auth`) that renders the Asset Registry / Script Manager hooks.
- **Helpers:** `frontend_auth()`, `frontend_user()`, `current_user()`,
  `is_frontend_authenticated()` (Laravel's `auth()` is untouched).
- **Settings** (Core `auth.*` group, private + autoloaded) with `config/cms.php`
  fallbacks: `registration_enabled`, `default_role_id`,
  `email_verification_required`, `auto_login_after_registration`,
  `frontend_session_lifetime_minutes`, `frontend_remember_lifetime_minutes`,
  `admin_session_lifetime_minutes`, `admin_remember_lifetime_minutes`,
  `rotate_session_on_device_change`, `force_logout_on_password_change`,
  `single_session_per_user`, `idle_timeout_minutes`.
- **Safe default role:** a permission-less `subscriber` role is seeded (and
  created on demand) so frontend registration never grants admin access.
- **Health:** `/cms-health` gains `frontend_auth_ready`,
  `frontend_registration_enabled`, `frontend_default_role_configured`,
  `frontend_session_policy_ready`, `frontend_email_verification_available`
  (flags only — no emails, session ids, or tokens).

### Session / device security

Defense in depth against stolen cookies:

1. Session id + CSRF token regenerated on login (and cleared on logout).
2. Per-user `frontend_session_version` seeded into the session at login; every
   protected request (via `cms.auth` / `cms.frontend_session`) checks
   `session.version == user.frontend_session_version`.
3. Password change (when `force_logout_on_password_change`) bumps the version
   and rotates the remember token — old sessions **and** old remember cookies
   stop working; the current session is re-seeded to stay valid.
4. Device change (when `rotate_session_on_device_change`): a login from a
   different **User-Agent hash** bumps the version + rotates the remember token,
   invalidating older stolen sessions; the same device does not.
5. Absolute expiry (frontend vs remember lifetime) and optional idle timeout are
   enforced by the session middleware, independent of the global cookie lifetime.

Only the SHA-256 **hash** of the User-Agent is stored (never the raw agent). IP
is recorded for audit but is not a hard gate (mobile networks change IPs); an
optional soft `ip_prefix_octets` check is available.

### Hooks

Actions `cms.auth.registering`, `cms.auth.registered`, `cms.auth.login.attempt`,
`cms.auth.login.success`, `cms.auth.login.failed`, `cms.auth.logout`,
`cms.auth.password_reset`, `cms.auth.session_invalidated`; filters
`cms.auth.default_role_id`, `cms.auth.registration_data`,
`cms.auth.redirect_after_login`, `cms.auth.redirect_after_logout`. A
`HookContext` is passed as the final argument.

### Notes

- One users table, one `web` guard. Admin panel access remains gated by
  `canAccessPanel()` / `admin.access`; frontend login grants no admin rights.
- Per-guard remember durations are enforced via an absolute expiry stored in the
  session (Laravel's remember cookie itself is long-lived); documented limitation.
- 27 feature tests in `tests/Feature/FrontendAuthTest.php`.

---

## [1.0.0-beta.7.1.13.1] — Asset Registry Foundation

A Core-level registry for themes and plugins to register, enqueue, and render
frontend/admin CSS/JS assets by handle — a modern, cleaner equivalent of the
WordPress enqueue API. Resolves dependencies, supports styles/scripts/modules
and inline assets, targets frontend/admin/both scopes, and renders in head or
footer buckets. **No** bundling, minification, or build pipeline. Complements
(does not replace or redesign) the Global Script Manager.

> **Version note:** `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15`. This phase
> is *included in* the current higher version and does **not** downgrade it; the
> heading tracks the phase label only.

### Added

- **`AssetRegistry`** (`cms.assets`) service + `Asset` / `AssetRenderResult`
  value objects (`Support/Assets/`). Singleton bound in `CmsServiceProvider`;
  facade `Asset` (`TheNguyen\CMS\Facades\Asset`).
- **Register / enqueue / render flow.** `registerStyle/Script/Module` define an
  asset by handle (does not render until enqueued); `enqueueStyle/Script/Module`
  activates it; `render*` emits it. Only enqueued assets (and their dependencies)
  render.
- **Helpers:** `asset_registry()`, `register_style/script/module()`,
  `enqueue_style/script/module()`, `inline_style/script()`,
  `render_frontend_styles/scripts()`, `render_admin_styles/scripts()`,
  `render_styles/scripts($scope)`, plus `tn_register_style/script()` and
  `tn_enqueue_style/script()` aliases.
- **Dependency graph.** A dependency renders before its dependent (topological
  order). A missing dependency is reported as a warning and the dependent still
  renders; a circular dependency is broken safely (never an infinite loop) and
  reported via `lastRenderWarnings()`.
- **Scopes** `frontend` / `admin` / `both` (default `frontend`) and **positions**
  `head` / `footer`. Styles default to head, scripts/modules to footer. A
  head-positioned script renders in the head bucket, a footer style in the footer
  bucket (`wp_head`/`wp_footer` model).
- **Inline assets** (`inline_style` / `inline_script`) that stand alone or attach
  `before` / `after` a target handle, rendering adjacent to it.
- **Core dependency anchors** `tncms.frontend` (scope `both`) and `tncms.admin`
  (scope `admin`) registered as no-output markers so plugins can depend on them
  without a missing-dependency warning.
- **Theme integration:** the default layout renders `render_frontend_styles()`
  in `<head>` (after core theme styles) and `render_frontend_scripts()` before
  `</body>` (after core theme JS), ahead of the Script Manager helpers.
- **Admin integration:** `AdminPanelProvider` renders `render_admin_styles()`
  via the Filament `HEAD_END` hook and `render_admin_scripts()` via `BODY_END`.
  No admin UI was added.
- **Health:** `/cms-health` gains `asset_registry_ready`,
  `registered_asset_count`, `enqueued_frontend_asset_count`, and
  `enqueued_admin_asset_count` (counts only — no handles, URLs, or code).

### Security

- **Source URLs:** relative and same-origin absolute URLs are allowed; external
  URLs must be HTTPS on a small trusted host allowlist (`cdn.jsdelivr.net`,
  `cdnjs.cloudflare.com`, `unpkg.com`, `fonts.googleapis.com`,
  `fonts.gstatic.com`, `esm.sh`). Rejected: `javascript:`, `vbscript:`,
  `data:` (incl. `data:text/html`), `blob:`, `file:`, and protocol-relative `//`.
- **Attributes** are allow-listed per type (scripts: `defer`, `async`, `type`,
  `crossorigin`, `integrity`, `referrerpolicy`, `nomodule`, `id`, `nonce`;
  styles: `media`, `crossorigin`, `integrity`, `referrerpolicy`, `id`), escaped
  with `htmlspecialchars`, and any `on*` event attribute rejects the registration.
- **Inline JS** rejects `</script`, `eval(`, `new Function(`, `document.write`,
  `javascript:`, `vbscript:`, `data:text/html`, `blob:`, `file:`, and
  event-handler-like patterns. **Inline CSS** rejects `<script`, `javascript:`,
  `expression(`, `behavior:`, and `@import` of a remote/protocol-relative URL.
- This is a trusted theme/plugin API, not a full JS/CSS sanitizer. Rendering is
  defensive and never throws; invalid assets are skipped and reported.

### Notes

- **Handles are a single namespace** across styles/scripts/modules;
  re-registering the same handle replaces the earlier definition (last wins) but
  keeps its registration sequence so replacing never reorders siblings.
- **No overlap with the Global Script Manager**, which remains the path for
  verification meta, JSON-LD, and trusted iframe embeds. The Asset Registry owns
  file-based CSS/JS enqueueing with dependency resolution.
- 26 feature tests in `tests/Feature/AssetRegistryTest.php`.

---

## [1.0.0-beta.7.1.13] — Global Script Manager

A Core-level, security-first manager for rendering frontend head/footer scripts,
verification meta tags, JSON-LD, and trusted iframe embeds. Themes no longer
hand-manage custom scripts: the default layout only calls `render_head_assets()`
and `render_footer_assets()`.

> **Version note:** `CmsInfo::VERSION` is already `1.0.0-beta.7.1.15`. This phase
> is *included in* the current higher version and does **not** downgrade it; the
> heading tracks the phase label only.

### Added

- **`ScriptManager`** service (`cms.scripts`) + `Script` facade, backed by the
  immutable `Support\Scripts\ScriptAsset` and `ScriptRenderResult` value objects.
- **Supported types**: inline head/footer scripts, external head/footer scripts,
  generic meta tags, typed verification meta, JSON-LD, and trusted iframe embeds.
- **Helpers**: `script_manager()`, `render_head_assets()`, `render_footer_assets()`
  (+ `tn_` aliases), `register_head_script()`, `register_footer_script()`,
  `register_meta()`, `register_json_ld()`, `register_verification()`,
  `register_embed()`.
- **Verification providers**: `google`, `bing`, `yandex`, `facebook`, `pinterest`,
  `baidu` — the manager stores only the value and emits the correct
  `<meta name="…">`. Unknown providers are rejected.
- **JSON-LD**: accepts an array or JSON string, validates it, and re-encodes with
  HTML-safe flags (`JSON_HEX_TAG` prevents `</script>` breakout). Invalid JSON is
  skipped.
- **Security policy**:
  - Inline content containing `eval(`, `new Function`, `document.write`,
    `javascript:`, `vbscript:`, `data:text/html`, `blob:`, `file:`, or inline
    event handlers (`onload=`/`onclick=`/`onerror=`) is rejected.
  - External script URLs must be same-origin relative or on a trusted host
    allowlist (Google/GTM/GA, Facebook, Clarity, Cloudflare Insights, jsDelivr,
    cdnjs, unpkg). Protocol-relative `//host` URLs are rejected.
  - Embeds are **iframe-only**, host-allowlisted (Google/Maps/GTM, Facebook,
    YouTube, Vimeo, giscus), attribute-sanitized (only `src`, `width`, `height`,
    `title`, `loading`, `allow`, `allowfullscreen`, `referrerpolicy`, `class`),
    and reject `<script>/<object>/<embed>`, event handlers, `style`, `srcdoc`,
    and `javascript:`/`data:`/`blob:`/`file:` sources.
- **Duplicates & order**: registering the same `{type}:{key}` **replaces** the
  earlier asset (last write wins). Assets render by `priority` ascending (default
  `10`, lower first), ties broken by registration order.
- **Hook integration** (existing `HookManager`): `cms.scripts.rendering_head` /
  `cms.scripts.rendering_footer` actions and `cms.scripts.head_html` /
  `cms.scripts.footer_html` filters.
- **Theme**: the default theme `master.blade.php` renders head assets before
  `</head>` and footer assets after core theme JS, before `</body>`.
- **Health** — `/cms-health` adds `script_manager_ready`,
  `registered_head_assets`, `registered_footer_assets`, `registered_meta`,
  `registered_json_ld`, `registered_verifications`, `registered_embeds`
  (counts/booleans only — never contents, URLs, keys, or values).

### Notes

- Rendering is defensive and **never throws on the frontend**: invalid
  registrations are rejected up-front and reported via `rejected()`, and each
  asset is rendered inside a guard so a bad asset is skipped, not fatal.
- Foundation only — no Admin UI, no Asset Registry, no analytics/SEO/consent
  layers. Those remain future phases.

---

## [1.0.0-beta.7.1.12.2] — Admin Form Hook Bridge

A WordPress-inspired extensibility seam so plugins can reshape TN CMS-owned
admin forms, extend the admin shell, and observe the full content/media
lifecycle — WITHOUT editing core resources. Additive; existing hooks and forms
are unchanged.

### Added

- **`FormHookBridge`** (`cms.form_hooks`) with helpers `tn_form_schema()` /
  `tn_form_regions()` and accessor `form_hooks()`. Applies:
  - `cms.form.schema` + per-alias `cms.form.schema.{alias}` — reshape a
    resource's form component array.
  - `cms.form.regions` + per-alias `cms.form.regions.{alias}` — contribute
    EXTRA components appended full-width beneath the form.
  Aliases are TN CMS-owned and stable: **post**, **page**, **term**
  (category + tag), **media**. Wired into `PostResource`, `PageResource`,
  `AbstractTaxonomyResource`, and `MediaResource`. Defensive: a broken listener
  or non-array return falls back to the original components (the form always
  renders).
- **Admin extension points** in `AdminPanelProvider`:
  - `cms.admin.navigation` (filter) — contribute extra navigation items.
  - `cms.admin.dashboard.widgets` (filter) — extend the dashboard widget list.
  - `cms.admin.assets` (action) — echo `<style>/<script>/<meta>` into the admin
    `<head>` (rendered via a Filament `HEAD_END` render hook).
- **New lifecycle actions**: `cms.content.deleting` / `cms.content.deleted` and
  `cms.media.deleting` / `cms.media.deleted` (fired from Eloquent model events,
  so EVERY delete path — Filament, manager, cascade, programmatic — emits them
  exactly once); `cms.media.uploading` (fired after validation, before bytes are
  written). The existing `cms.content.saving/saved`, `cms.term.saving/saved`,
  `cms.media.uploaded`, and `cms.plugin.*` actions are unchanged.
- **Health** — `/cms-health` adds `form_hooks_ready`, `form_hook_points`,
  `admin_hook_points` (counts/booleans only).
- All new hook points are documented in the HookManager registry (groups
  *Admin Forms*, *Admin*, *Content*, *Media*).

### Fixed

- **Test isolation (ecommerce)** — `CartCheckoutTest` now force-registers the
  ecommerce service provider so `InventoryManagerInterface` is always bound,
  guarding a pre-existing order-dependent isolation defect (unrelated to this
  phase) that could surface as a `BindingResolutionException` in a full-suite run.

### Notes

- No routing/widget/hook/shortcode/theme redesign; no vendor edits. Form
  extensibility is filter-driven; a plugin injecting an invalid Filament
  component is responsible for its own validity (core guarantees array shape).

## [1.0.0-beta.7.1.12.1] — Preview API Polish

Finalises the Preview Foundation as a stable Core seam for Posts, Pages,
Products, and future plugin content — **without** future API changes. Every
change is ADDITIVE; all previous PreviewManager APIs keep working unchanged.

### Added

- **`PreviewContext`** (`Support\Preview\PreviewContext`) — an immutable render
  context that REUSES the `HookContext` architecture for the shared runtime
  accessors (request, locales, theme, route) and layers on the preview fields:
  `preview`, `previewType`, `previewKey`, `expiresAt`, `generatedAt`,
  `generatedBy`, `signed`, `ttl`. Helper `preview_context()` (+ `tn_` alias).
- **Preview lifecycle hooks** — actions `cms.preview.generating`,
  `cms.preview.generated`, `cms.preview.rendering`, `cms.preview.rendered`,
  `cms.preview.denied`, `cms.preview.expired`; filters `cms.preview.url`,
  `cms.preview.response`, `cms.preview.metadata`, `cms.preview.context`. Every
  callback receives the `PreviewContext` as its final argument. Best-effort — a
  broken listener never breaks a preview. Existing callbacks are unaffected.
- **Preview policy** — `temporaryUrl($previewable, options: ['ttl' => 30,
  'require_login' => false])`. `require_login` is signed INTO the URL (cannot be
  stripped) and enforced with the app's existing auth (403 + `denied`); default
  remains `false`. No new authentication logic.
- **Preview registry** — memory-only `types()`, `hasType()`, `forgetType()`,
  `definition()`, `definitions()` (safe `PreviewDefinition` descriptors, no
  callables), `registeredCount()`.
- **Richer metadata** — `metadata()` now returns `type`, `key`, `title`,
  `preview`, `signed`, `ttl`, `created_at`, `expires_at`, `generator`,
  `version`; extensible via `cms.preview.metadata`. Never leaks unpublished body.
- **Core Post/Page previews** — `Content` implements `Previewable`; the
  `cms.post` / `cms.page` types are registered with the PreviewManager and render
  through the existing `FrontendController::renderPreviewable()` seam, reusing the
  SAME SEO, breadcrumbs, theme data, layout, hooks, and shortcode pipeline as the
  published page (no duplicate rendering pipeline).
- **Filament** — Posts and Pages expose a **Preview** action (published → public
  URL; draft/scheduled → signed preview URL, new tab), matching Products.
- **Health** — `/cms-health` adds `preview_ready`, `preview_types`,
  `preview_hooks`, `preview_context` (counts/booleans only; never URLs).

### Notes

- Security unchanged: signed routes, temporary signatures, 403 on invalid/expired,
  `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store … private`.
- No routing/widget/hook/shortcode/theme redesign; no database-backed preview
  storage. One-time links, revocation, history, analytics, and share management
  remain OUT of scope (a future Preview Management phase).

## [1.0.0-beta.7.1.15] — Plugin Lifecycle & Auto-Install

Plugin activation now **installs the plugin's database before marking it active**,
so a plugin's dashboard can never 500 on a missing table (e.g. `cms_import_jobs`)
right after activation. Users no longer have to run `php artisan migrate` by hand.

### Added

- **`PluginLifecycleManager`** (`cms.plugin_lifecycle`) — orchestrates activation:
  `validate → beforeActivate → migrate → seed → verify → mark active → afterActivate`.
  A migration/seeder failure or a failed verification leaves the plugin **inactive**
  (never half-installed), records a friendly error, and returns a
  `PluginActivationResult`. Activation is idempotent (already-active = no-op;
  already-applied migrations never rerun). `deactivate()` disables only — it
  **never** rolls back migrations. Includes the architectural hook seams
  `before/after` × `activate/deactivate/upgrade/uninstall`, firing a generic
  `cms.plugin.<event>` action for future listeners (upgrade/uninstall flows are
  seams only for now).
- **`PluginDatabaseManager`** (`cms.plugin_database`) — discovers, runs, verifies
  and reports a plugin's migrations and seeders. Honors the manifest `database`
  block (`{ "database": { "migrations": ..., "seeders": ... } }`), falling back to
  `database/migrations` + `database/seeders`. Idempotent via the shared migration
  repository.
- **`PluginInstallationGuard`** (`cms.plugin_installation_guard`) — `isInstalled()`,
  the recorded install `error()`, and `safe(callable, fallback)` so a plugin page
  can read a table without risking a missing-table 500 (renders "installation
  incomplete" instead).
- **`plugin.json` `database` block** — plugins may declare custom migration/seeder
  paths; existing plugins keep working unchanged via the conventional fallback.

### Changed

- `plugin:activate` and the admin **Installed Plugins** activate action now run the
  full lifecycle (install-then-activate) and surface a friendly error on failure;
  `plugin:deactivate` / the admin deactivate action route through the lifecycle
  (disable only). `ExtensionManager::activatePlugin()` is unchanged (still the
  low-level "mark active" registry op) so existing callers are unaffected.
- Plugin migration discovery at boot now honors the manifest `database.migrations`
  paths (fallback unchanged), so `php artisan migrate` sees custom paths too.

### Notes

- A plugin whose install fails stays inactive, so its routes/pages are never
  registered — it cannot reach a dashboard to 500. The guard covers the residual
  legacy case (a plugin activated before this system with missing tables).

---

## [1.0.0-beta.7.1.14] — Section Data Source Extension Point

Adds a generic, WordPress-agnostic extension surface so a plugin can register a
new **section data source** (an extra `data_source` option plus its resolution)
for data-bound sections — without core knowing the source. This is the hook the
WP Remote Contents plugin uses to offer *WP2TNCMS API* as a source for the
`post-grid` and `category-blocks` sections (Phase 9E-D).

### Added

- **`cms.sections.schema` filter** — the section catalog is passed through this
  filter (`array $schema`) before the `SectionRegistry` is built, so an extension
  can add settings/fields to any section (e.g. a new `data_source` enum value and
  the fields it reveals). Resolved lazily after boot; with no listener the schema
  is unchanged, so core behaviour is identical.
- **`cms.section.data_source` filter** — `SectionDataProvider::resolve()` applies
  this filter for any `data_source` that is **not** a core mode
  (`manual`/`placeholder`/`posts`), passing
  `(null, $sectionType, $mode, $settings, $locale, $currentPost)`. A listener may
  return the injected repeater map (e.g. `['posts' => [...]]`) to render its
  source, or pass `null` through to keep the authored content. Runs inside the
  provider's existing try/catch, so a throwing listener degrades to authored
  content — never a 500.

### Notes

- Core carries **no** WordPress/WP2TNCMS names or classes; both hooks are generic
  and the only knowledge core has is the two filter names.
- Both filters have `HookDefinition`s (group *Section*), so they appear in the
  hook catalogue / `/cms-health` counts.

---

## [1.0.0-beta.7.1.13] — Menu Resolve Hook & WP-style Aliases

Completes the menu-injection extension point: a frontend menu *location* with no
local menu can now be filled by a plugin through a core filter, with no theme or
menu-table changes. This is the hook the WP Remote Contents plugin needs to
render a remote menu in place of an absent local one.

### Added

- **`cms.menu.resolve` filter** — `frontend_menu($location)` now applies this
  filter **only when the location has no local menu**, passing
  `(null, $location, $locale)`. A listener may return a render-ready menu tree
  (`array<int, array{item, title, url, children}>`) to inject, or `null` to skip
  (the location then renders empty). A local menu always wins — the filter never
  fires when one exists. Registered as a core hook definition (group `Menu`).
- **WordPress-style `cms_*` aliases** — `cms_add_action()`, `cms_do_action()`,
  `cms_add_filter()`, `cms_apply_filters()`: thin, collision-safe wrappers over
  the existing `cms.hooks` manager (default priority 10), for plugins that prefer
  an explicitly namespaced hook API alongside the existing `add_action` /
  `apply_filters` helpers.

### Notes

- No change to menu storage or rendering for existing local menus; the hook is
  purely additive and scoped to the "no local menu" branch.

---

## [1.0.0-beta.7.1.12] — Theme Asset Publish Command

Adds a first-class, deploy-safe way to publish a theme's public assets so the
served CSS/JS/images always match the theme source. This closes the gap where
editing `themes/{slug}/assets` left the old files in `public/themes/{slug}`,
causing a frontend that did not reflect the theme.

### Added

- **`php artisan theme:publish`** — copies `themes/{slug}/assets` into
  `public/themes/{slug}`, preserving directory structure. Forms:
  - `theme:publish` — publish the active theme (falls back to `default`, then
    the first discovered theme; errors with a non-zero exit when none exists);
  - `theme:publish {theme}` — publish one named, valid theme;
  - `theme:publish --all` — publish every valid theme discovered by ThemeManager;
  - `--dry-run` — report what would be copied without writing anything;
  - `--clean` — delete `public/themes/{slug}` first, then republish.
- **`ThemeAssetPublisher`** service (`cms.theme_publisher`) and an immutable
  **`PublishResult`** value object (`theme`, `source`, `destination`, `copied`,
  `skipped`, `deleted`, `errors`, `dry_run`).

### Notes

- **Strict allowlist:** only web-servable file types are copied (`css`, `js`,
  `mjs`, `json`, images, fonts, `txt`, `xml`, `webmanifest`). PHP/Blade/`.env`/
  `.sql`/`.map` and other non-listed files are always skipped.
- **Safety:** `--clean` asserts the destination is exactly `public/themes/{slug}`
  before deleting — `public/themes`, `public/uploads`, and `public` can never be
  removed. Path-traversal slugs are rejected; symlinked source files are skipped;
  assets are copied, never symlinked.

---

## [1.0.0-beta.7.1.11.1] — Hook Context & Extensibility API

Extends the Hooks & Shortcodes Foundation into a more complete, discoverable
extensibility API: a runtime **HookContext** passed to callbacks, a
**HookDefinition** registry documenting hook points, and optional
extension-aware callback metadata. Still **in-process** only — no webhooks, no
external HTTP.

### Added

- **`HookContext`** (`TheNguyen\CMS\Support\Hooks\HookContext`) — an immutable
  data bag passed to hook/filter (and shortcode) callbacks as their final
  argument. `make()`, `get()`, `has()`, `all()`, `with()` (returns a clone),
  plus null-safe runtime accessors `request()`, `user()`, `locale()`,
  `editingLocale()`, `frontendLocale()`, `theme()`, `routeName()`, `panel()`.
  Every accessor degrades to a safe default and never throws; no DB queries.
- **`HookDefinition`** (`TheNguyen\CMS\Support\Hooks\HookDefinition`) — a
  descriptive value object documenting a hook point: `name`, `type`,
  `description`, `arguments`, `returnType`, `since`, `source`, `group`. Static
  `action()` / `filter()` factories.
- **Hook registry on `HookManager`** — `defineAction()`, `defineFilter()`
  (accept a `HookDefinition` or a name + meta array), `definitions()`,
  `definition()`, `definedActions()`, `definedFilters()`, `definitionCount()`.
  Definitions are optional documentation: hooks run with or without them;
  duplicate definitions are last-wins and reported.
- **Extension-aware callback metadata** — `add_action()` / `add_filter()` (and
  `HookManager::addAction/addFilter`) accept an optional 5th `array $meta`
  argument (`source`, `source_slug`, `label`). Safe summaries
  `actionSummary()` / `filterSummary()` expose per-hook callback count,
  distinct priorities, and per-source counts — never callback class names.
- **Helpers** (guarded): `hook_context()`, `define_action()`, `define_filter()`,
  `hook_definition()`, `hook_definitions()`, plus `tn_*` aliases
  (`tn_hook_context`, `tn_define_action`, `tn_define_filter`).
- **Core hook definitions** registered for all 11 core actions and 6 core
  filters (groups: Content, Taxonomy, Media, Rendering, Theme, Shortcode).
- **Health fields** (counts only): `hook_definitions_count`,
  `defined_action_count`, `defined_filter_count`, `hook_callback_count`.

### Changed

- Core hook call sites now pass a `HookContext` as the final argument:
  `cms.content.saving/saved`, `cms.term.saving/saved`, `cms.media.uploaded`,
  `cms.post.rendered` / `cms.page.rendered`, and the `cms.content.*` render
  filters. Backward compatible — `acceptedArgs` slices arguments, so existing
  callbacks that requested fewer arguments keep working unchanged.
- `cms.shortcode.output` filter signature is now
  `(string $output, string $tag, array $attrs, ?string $content, array $context, HookContext $hookContext)`
  — the inner `$content` and `$hookContext` are new. Filters registered with a
  lower `acceptedArgs` are unaffected.

### Notes

- No admin Hook Explorer / Developer Tools UI, no webhooks/external HTTP, no
  cache changes, no widget/theme/routing/slug changes — foundation only.
- `since` on the core definitions is `1.0.0-beta.7.1.11.1`.

---

## [1.0.0-beta.7.1.11] — Hooks & Shortcodes Foundation

A WordPress-inspired **in-process** extension system so plugins and themes can
hook into CMS behaviour and add content shortcodes without touching core. This
is **not** a webhook / external-HTTP system.

### Added

- **`HookManager`** (`cms.hooks`, `Hook` facade) — actions and filters with
  ascending priority (ties keep registration order), `acceptedArgs` slicing,
  `removeAction`/`removeFilter`, `hasAction`/`hasFilter`, and `actions()`/
  `filters()` discovery (counts only, never the callbacks). A throwing callback
  is caught and logged — never crashes a request; filters pass the value through
  unchanged on failure. `captureAction()` buffers echoed output for themes.
- **`ShortcodeManager`** (`cms.shortcodes`, `Shortcode` facade) — `register`,
  `has`, `remove`, `all`, `render`, `strip`. Parses `[tag]`, `[tag a="x" b='y'
  c=z flag]`, and enclosing `[tag]…[/tag]`; unknown tags are left untouched;
  nested enclosing shortcodes render one level deeper, depth-bounded against
  infinite recursion. Callback exceptions are caught (original token preserved);
  output passes through the `cms.shortcode.output` filter.
- **Helpers** (guarded with `function_exists`): `add_action`, `do_action`,
  `add_filter`, `apply_filters`, `add_shortcode`, `do_shortcode`,
  `strip_shortcodes`, `render_hook`, plus `hooks()`/`shortcodes()` accessors and
  namespaced `tn_*` aliases.
- **Built-in shortcodes**: `[button url="…" target="_self|_blank"]Label[/button]`
  (URL sanitized to http/https/mailto/tel/relative else `#`; label escaped;
  `rel="noopener noreferrer"` added for `_blank`), `[year]`, `[site_name]`
  (localized).
- **Core hook points**: content filters `cms.content.title` /
  `cms.content.excerpt` / `cms.content.body` and `cms.content.before_shortcode` /
  `cms.content.after_shortcode`; `cms.post.rendered` / `cms.page.rendered`; save
  actions `cms.content.saving|saved`, `cms.term.saving|saved`,
  `cms.media.uploaded`; and theme hooks `cms.theme.header` / `before_content` /
  `after_content` / `footer` placed in the default theme via `render_hook`.
- **Health fields**: `hooks_ready`, `shortcodes_ready`,
  `registered_shortcode_count` (no callback class names exposed).

### Changed

- Post/page bodies now run through the render-time pipeline in
  `FrontendController::renderContent()` — title/excerpt/body filters, then
  `do_shortcode()` between the before/after filters — applied to the
  already-(write-time)-sanitized body, then re-sanitized by the theme.
- Default theme `layouts/master.blade.php` adds four `render_hook` placeholders.

### Security

- Shortcodes can only invoke PHP-registered callbacks; unknown tags execute
  nothing. No `eval`, no PHP-from-database, no dynamic class instantiation from
  content; attribute parsing is pure string work. Built-in shortcode URLs are
  sanitized. Callback exceptions are reported but never crash the page.

### Notes

- No external HTTP/webhook system, no marketplace/plugin-installer, no cache,
  widget, theme, routing/slug, or editor redesign, and no vendor edits.

---

## [1.0.0-beta.7.1.10.2] — Localized Editing Framework

Finalizes the locale architecture so **every** localized admin editor reads and
writes content through one source — `editing_locale()` — while the interface
language stays on `app()->getLocale()`. beta.7.1.10.1 split the query params;
this phase removes the remaining places where modules inferred the content
locale from `current_locale()` / `defaultCode()`, and makes the editing locale a
persisted per-user preference.

### The locale contract

```text
app()->getLocale()  = admin UI language      (?lang,   users.admin_locale)
editing_locale()    = content editing language (?locale, users.editing_locale)
frontend locale     = public website language  (route/{locale} → user → session)
```

### Added

- **`users.editing_locale`** nullable column — the language a user was last
  editing content in. Third independent member of the locale trio.
- **`editing_locale()` global helper** — the single content-editing-locale
  source; delegates to `LocalePreferenceManager::resolveEditingLocale()` with the
  authenticated user. Modules must not roll their own fallback.
- Per-user editing-locale resolution + persistence: order is now
  `?locale` → `user.editing_locale` → `session(cms.editing_locale)` → default →
  `vi`; an explicit `?locale=` now also writes `users.editing_locale`
  (logged-in), never `admin_locale`/`frontend_locale`.

### Changed

- **Localized content editors now seed/read the editing locale**, not the admin
  UI locale: `WidgetsPage` (widget titles/text), `HomepageLayoutPage` (section
  content), `SettingsPage` (localized settings fields + page/category option
  lists). Widget/homepage/settings page labels still follow the admin UI locale.
- **Shared traits centralized** on `editing_locale()`:
  `HasLocaleSelect::viewingLocale()`, `EditsTranslationLocale::selectedLocale()`,
  package `HasTranslations::resolveRequestedLocale()`, and
  `MenuItemsRelationManager` — no duplicated resolver logic.
- The admin language switcher (`?lang`) and content switchers (`?locale`)
  continue to preserve each other's param (from beta.7.1.10.1).

### Notes

- Locale-editing consistency only. No routing/slug/theme/widget redesign, no
  cache/installer/hook/shortcode/webhook changes, no vendor edits. The one schema
  change is the additive `users.editing_locale` column.
- Theme options are not locale-partitioned in this build, so they need no change;
  the localized "site title" lives in Settings.
- Remaining `defaultCode()` usages are intentional: create-time authoring
  defaults (`writing.default_language`), default-locale fallback display, and the
  default-locale global-settings sync.

---

## [1.0.0-beta.7.1.10.1] — Separate admin UI locale from content editing locale

beta.7.1.10 used a single `?locale=` query param as both the admin **interface**
language signal and the content **editing translation** signal. As a result,
switching the content language being edited (e.g. opening a Vietnamese
translation with `?locale=vi`) also flipped the entire admin UI to Vietnamese —
even when the user's admin language was English. The two concerns are now driven
by **separate query parameters** and resolved independently.

### Added

- **`LocalePreferenceManager::resolveEditingLocale()`** — content editing locale
  resolver: `?locale=` → `session(cms.editing_locale)` → default → `vi`. Never
  reads `?lang`, a user column, or `currentCode()`.
- **`LocalePreferenceManager::persistEditingLocaleFromRequest()`** — remembers an
  explicit `?locale=` choice in `session(cms.editing_locale)` only. Deliberately
  does **not** touch `users.admin_locale` or `users.frontend_locale`.
- **`LocalePreferenceManager::persistAdminLocaleFromRequest()`** — persists an
  explicit `?lang=` admin-UI choice (logged-in only) to session + user column.
- **`cms.editing_locale` session key** for the content editing locale.

### Changed

- **Admin UI locale signal is now `?lang=`** (was `?locale=`).
  `resolveAdminLocale()` order is now `?lang=` → `user.admin_locale` →
  `session(cms.admin_locale)` → default → `vi`. `SetCmsAdminLocale` reads `?lang`
  for the UI and only mirrors `?locale` into the editing session.
- **Admin topbar language switcher** now submits `?lang=` and preserves the
  current `?locale=` editing param (hidden field), so changing the UI language
  keeps the same translation open.
- **Content language switchers** (`HasLocaleSelect` select, package
  `TranslationSwitcher`/`HasTranslations::translationUrlFor()`) now preserve the
  current `?lang=` admin-UI param when switching `?locale=`, so changing the
  translation keeps the same UI language.
- **Content editing readers** route through `resolveEditingLocale()` instead of
  reading `?locale` directly or falling back to `currentCode()`:
  `HasLocaleSelect::viewingLocale()`, `EditsTranslationLocale::selectedLocale()`,
  `MenuItemsRelationManager`, and the package `HasTranslations`.
  `viewingLocale()` no longer falls back to `currentCode()` (which now tracks the
  admin UI locale).

### Notes

- Pure locale-resolver separation patch. No schema, routing, slug-resolution,
  widget, cache, installer, media-manager, theme, or vendor changes.
- Frontend locale behaviour is unchanged (route `{locale}` → `?locale=` →
  `user.frontend_locale` → `session(cms.frontend_locale)` → default).
- `users.admin_locale` and `users.frontend_locale` remain fully independent;
  `?locale` no longer changes the admin UI language, and `?lang` never changes
  the content editing language.

---

## [1.0.0-beta.7.1.10] — Independent admin & frontend locale preferences

Backend (admin) language and frontend (public) language are now **independent,
per-user preferences** instead of everything collapsing to the site default
(`vi`). A logged-in user can run the admin in one language while browsing the
public site in another; both choices persist.

### Added

- **`users.admin_locale` + `users.frontend_locale`** (nullable) — two separate
  columns so the two languages never bleed into each other (Option A; the app
  has no user-meta table). Added to `User::$fillable`.
- **`LocalePreferenceManager`** (`cms.locale_preference`) — single resolver +
  writer for both contexts. Admin order: `?locale=` → `user.admin_locale` →
  `session(cms.admin_locale)` → default. Frontend order: route `{locale}` →
  `?locale=` → `user.frontend_locale` → `session(cms.frontend_locale)` →
  default. Only **active** language codes are accepted; writes are best-effort
  and change-only (`saveQuietly`).
- **Backend language switcher** in the admin topbar (`TOPBAR_END` render hook,
  dependency-free GET form). Renders only when the site has >1 active language.

### Changed

- **`SetCmsAdminLocale`** now drives the admin locale from the logged-in user's
  preference and persists an explicit `?locale=` switch (logged-in only — guests
  on the login screen just get the default). It still aligns `app()->setLocale()`
  so Filament's own strings follow the admin language.
- **Hardcoded `'vi'` removed** from admin list/display reads — now follow
  `current_locale()` (which the middleware pins to the admin's language):
  `PostResource`, `PageResource`, `MenuResource`,
  `MenuItemsRelationManager`, and `WidgetsPage`'s initial edit locale. Create
  fallbacks (`$data['locale'] ?? 'vi'`) now use `defaultCode()`. The widget
  global-sync check intentionally keeps comparing against `default_locale()`.
- **Frontend** remembers a logged-in user's reading language on an explicit
  localized route (`FrontendController::resolveLocale`). The URL `{locale}`
  prefix stays authoritative — this is preference storage only.

### Notes

- No schema redesign (two additive nullable columns), no routing change, no
  widget/cache/installer/theme structural change, no vendor edits.

---

## [1.0.0-beta.7.1.9.2] — Taxonomy list follows the viewing locale

Follow-up on beta.7.1.9.1. That patch routed the taxonomy list through
`displayLocale()` but resolved it to `defaultCode()` — so the Categories/Tags
list still showed the **default** language's labels (e.g. "Tin tức") even while
the admin was viewing/editing in another locale. The list now follows the
locale the admin is actually in.

- **New reusable resolver** `HasLocaleSelect::viewingLocale()` — the READ-side
  counterpart to `defaultAuthoringLocale()`. Resolution: validated `?locale=`
  switcher value (the same param the edit form's language Select sets and the
  package `HasTranslations`/`EditsTranslationLocale` read) → `cms.language`
  `currentCode()` → `defaultCode()`. Deliberately **not** `defaultCode()` alone.
- `AbstractTaxonomyResource` now `use`s `HasLocaleSelect`, and `displayLocale()`
  delegates to `viewingLocale()`. The eager-load, name/slug/parent columns and
  name search all follow the viewing locale through that single accessor;
  `CategoryResource`/`TagResource` inherit it unchanged.
- No schema / routing / widget / cache / installer / theme changes.

---

## [1.0.0-beta.7.1.9.1] — Taxonomy admin locale fix

Patch on beta.7.1.9. The reusable `AbstractTaxonomyResource` had inherited a
hardcoded `'vi'` display locale in five places — `getEloquentQuery()`'s
translation eager-load, plus the name/slug/parent columns and the name search.
On a site whose default locale is not Vietnamese this showed wrong (or
placeholder) labels. All five now resolve through a single
`displayLocale()` helper that follows the configured CMS default locale
(`cms.language` → `defaultCode()`), the same fallback the `Term` accessors use.
No schema, query-shape, or behavior change for vi-default sites.

---

## [1.0.0-beta.7.1.9] — Hierarchical Taxonomy Framework & Term Media

Promotes the flat taxonomy MVP into a production-ready, **reusable hierarchical
taxonomy framework**. Hierarchy is implemented at the **taxonomy level**, never
as a category-only feature, so any future taxonomy (product / documentation /
forum / literary category, or a plugin's own) becomes hierarchical by setting a
single flag — with no further core change. 100% backward compatible. No routing
change, no nested URLs, no widget/cache/installer change, no media-manager
rewrite, no vendor edits.

### Added

- **Taxonomy-level hierarchy.** `Taxonomy::isHierarchical()` is the single
  switch that turns parent/child trees on. Category ships `hierarchical = true`,
  Tag stays `false`; both are driven by the same code. The `parent_id` column
  already existed and is reused as-is.
- **Unlimited-depth parent/child trees** with integrity guards in
  `TaxonomyManager` that reject self-parenting, ancestor loops (re-parenting
  under a descendant), missing parents, soft-deleted parents, and parents from a
  different taxonomy — enforced for the admin UI, importers, and plugins alike.
- **`Term` tree helpers:** `ancestors()`, `descendantIds()`, `depth()`, plus
  `TaxonomyManager::treeOrderedTerms()` for depth-first, depth-tagged ordering
  (drives the indented admin list and loop-safe parent selector — no JS tree).
- **Rich description.** The term description textarea is now the shared
  `RichEditor`; stored HTML is sanitized on every write via the existing
  `HtmlSanitizer` (same guard as post bodies).
- **Featured image for hierarchical terms.** New global `featured_image` column
  on `cms_terms` (shared across locales, like `parent_id`), written by the
  existing Media Picker. Flat taxonomies ignore it.
- **Reusable admin layer.** New `AbstractTaxonomyResource` builds the form and
  table once and shows the parent selector + featured image only when the
  taxonomy is hierarchical. `CategoryResource` and `TagResource` now extend it;
  a future custom taxonomy resource needs only a slug + content type.
- **Theme exposure.** The default theme's archive view now renders the term's
  featured image and sanitized description when present, through the existing
  data pipeline. Fully guarded — themes/terms without them render unchanged.

### Locale

- Hierarchy and featured image are **global**; only labels, slug and description
  are per-locale. Adding a translation never forks `parent_id`.

### Database

- One additive migration: nullable `cms_terms.featured_image`. No table
  redesign, no id migration, no change to `parent_id`.

---

## [1.0.0-beta.7.1.8] — Media Picker Search & Taxonomy Archive Pagination

Two production-blocking fixes for large/real-world content. No schema change,
no cache, no GitHub/URL installer, no widget redesign, no vendor edits.

### Fixed

- **Theme Options (and any) MediaPicker can now find media beyond the first
  page.** The Media Library grid (logo / favicon / featured-image pickers)
  loaded only the newest 50 images and filtered them client-side, so a media
  item beyond that snapshot — common after a WordPress import of hundreds of
  files — was impossible to select. Search now runs **server-side over the
  whole `cms_media` table**, matching `original_filename`, `filename`, `url`,
  `path`, `alt`, `title`, `caption` and `description`, newest first,
  case-insensitively, capped at 50 results per query. The current selection's
  details still render even when it falls outside the snapshot or the active
  search.
- **Category / tag archives render pagination links.** The
  `FrontendController` already paginated term archives (since beta.6.3), but the
  default theme's `archives/index` view iterated the paginator without ever
  calling `->links()`, so only the first page was reachable. The view now
  renders a theme pagination control (`theme::partials.pagination`) when the
  posts are a paginator.

### Added

- **Authenticated image-search endpoint** `filament.admin.cms-media-search`
  (registered via the admin panel's `authenticatedRoutes`), backing the grid's
  server-side search. Returns image media as JSON; limit hard-capped at 100.
- **Theme pagination partial** `themes/default/views/partials/pagination.blade.php`
  plus `.tn-pagination` styles, used by term archives. Query string is preserved
  on links (controller applies `withQueryString()`).
- **`/cms-health` fields** `media_picker_search_ready` and
  `taxonomy_archive_pagination_ready` (booleans).

### Notes

- Archive per-page reads the **existing** `reading.posts_per_page` setting
  ("Số bài viết mỗi trang") — the same one the homepage/blog listing uses.
  Floored at 1 (invalid/≤0 → 10) and hard-capped at 100 so a huge term can never
  load unbounded rows. No separate archive setting was introduced.
- Homepage "latest posts" behaviour is unchanged (it passes a bounded
  Collection; the pagination block is guarded to paginators only).
- The default theme's master layout keeps a commented-out duplicate `<main>`
  block; Blade still compiles the `@yield('content')` inside it, so archive HTML
  appears a second time inside an HTML comment (invisible to users). Pre-existing
  and left untouched.

## [1.0.0-beta.7.1.7] — Strict Locale Tag Rendering (Frontend)

Completes the strict per-locale taxonomy policy by extending it from the admin
editor (beta.7.1.6) and the CategoriesWidget (beta.7.1.5) to the **frontend
single-post template**. No schema change, no routing change, no `WidgetManager`
/ widget UI change, no vendor edits. Category rendering is untouched.

### Fixed

- **Frontend post tags no longer render `Term #id` placeholders or another
  locale's label.** The single-post view printed every attached tag with
  `Term::displayName()`, which falls back to a `Term #id` placeholder when the
  loaded translation set has no entry for the viewed locale. Because the
  controller eager-loaded only the current locale's translations, tags kept
  attached for preservation across a strict-locale admin save (a VI-only tag on
  an EN post, for example) surfaced as `Term #9` / `Term #10`. Tags are now
  rendered strictly per locale.

### Changed

- **`FrontendController::renderContent()` filters post tags by current-locale
  translation.** The `$data['tags']` query now requires a non-empty `name` and
  `slug` translation in the viewed locale (`whereHas('translations', …)`),
  mirroring `CategoriesWidget`. Foreign-locale tags stay attached but are not
  exposed to the theme.
- **`themes/default/views/posts/post.blade.php` uses the strict path.** Each tag
  is rendered with `Term::localeName($locale)` (not `displayName()`) and skipped
  when the name is `null` or `term_url($tag, $locale)` resolves to `#`. No
  fallback labels reach the public frontend.

### Notes

- Relationship preservation is unchanged: saving a post in EN or VI still keeps
  the other locale's tags attached (`PostResource::mergeTermIds`). Strictness is
  a *display* policy applied at render time, not a data change.
- `Term::displayName()` keeps its fallback behaviour for admin contexts; only
  frontend display switched to the strict `localeName()` / `localeSlug()` pair.
- Tests: `tests/Feature/PostTagStrictLocaleTest.php`. These assert the exact
  render predicate rather than driving HTTP, because the frontend route group
  resolves permalink settings at route-registration time, which is incompatible
  with the in-memory test database boot order (same constraint that makes
  `ThemeContentRenderingTest` environment-dependent).

---

## [1.0.0-beta.7.1.6] — Admin Post Taxonomy Strict Locale

Extends the strict per-locale policy from the CategoriesWidget (beta.7.1.5) to
the **admin Post editor**. No schema change, no routing change, no
`WidgetManager` change, no vendor edits. `PageResource` has no taxonomy fields
and was left unchanged.

### Fixed

- **Post editor Categories/Tags now follow the edited locale.** The category
  `CheckboxList` hardcoded `vi` and used `Term::displayName('vi')` (which falls
  back), so editing a post in English still showed Vietnamese category labels
  (`Tin tức`, `Dịch vụ`). Tag chips were pre-filled with `displayName('vi')` for
  the same reason. Both now resolve the locale from the form's `locale` field
  (seeded from the page's `selectedLocale()`) and use the strict
  `Term::localeName($locale)`.

### Changed

- **Strict locale, no silent fallback.** When editing in English, only
  categories/tags that have a non-empty English translation appear; a term
  translated only in Vietnamese is hidden rather than shown with its VI label.
  `PostResource::categoryOptions()` now requires a `$locale` argument and filters
  with `whereHas('translations', locale = $locale)`.
- **Foreign-locale assignments are preserved on save.** Because content terms are
  synced as a full set, a hidden VI-only term would otherwise be dropped when
  saving from the EN editor. `PostResource::termsForForm()` returns such
  assignments under `preserved`, and `mergeTermIds()` accepts them and merges
  them back so no term relationship is lost.

### Notes

- `Term::localeName()` (added in beta.7.1.5) is reused here; no new model helper
  was needed. `displayName()` / `translatedSlug()` keep their fallback behaviour
  for other callers.
- The per-post term split/merge logic moved from `EditPost` into static methods
  on `PostResource` (`termsForForm()`), so both the form and tests share one
  locale-aware mapping. `CreatePost` continues to call `mergeTermIds()` (now with
  an optional `preserved` argument that defaults to empty).

---

## [1.0.0-beta.7.1.5] — CategoriesWidget Strict Per-Locale Display

Reverses the cross-locale fallback introduced in beta.7.1.4. No schema change,
no UI change, no `WidgetManager` change; `term_url()` and
`TaxonomyManager::findTermBySlug()` were inspected and left unchanged.

### Changed

- **CategoriesWidget is now strictly per-locale.** beta.7.1.4 made a category
  translated only in the default language appear on other-locale pages using the
  default language's name/URL. That mixed languages in the list, which is wrong
  for a localized site. The widget now shows **only** categories that have a
  translation (name + slug) in the **current** locale, using that locale's own
  name and URL. A category not translated into the current language is omitted.

### Added

- `Term::localeName(string $locale): ?string` — exact-locale category name with
  **no** fallback (the name counterpart to the existing strict
  `Term::localeSlug()`). `displayName()` / `translatedSlug()` keep their
  fallback behaviour for other callers.

---

## [1.0.0-beta.7.1.4] — CategoriesWidget Locale Fallback

A focused bug fix for the **CategoriesWidget** frontend render. No `WidgetManager`
change, no schema change, no installer/marketplace, no UI redesign. Only the
widget's query/URL logic changed; `Term`, `TaxonomyManager`, and `term_url()`
were inspected and left unchanged.

### Fixed

- **Categories vanished on non-default-locale pages.** The widget filtered
  category terms by `whereHas('translations', locale = current locale)` and built
  links with `term_url($term, $locale)` (which returns `#` when that locale has no
  slug). So a category translated only in the default language (e.g. `vi`) was
  excluded entirely from the `en` sidebar — the widget rendered nothing. It now:
  - includes terms with a slugged translation in **the requested locale OR the
    default locale**, and
  - **falls back to the default-locale URL** when the requested locale has no
    translation (the default-locale URL always resolves via the strict
    `TaxonomyManager::findTermBySlug()`), so links are never dead.
  - The displayed name already falls back via `Term::displayName()`.

### Notes

- The fallback is intentionally limited to the default locale (not "any
  locale") to guarantee every rendered link resolves and to mirror the rest of
  the CMS's default-locale fallback behaviour.
- Posts (RecentPostsWidget) were unaffected in practice only because the demo
  data carried `en` post translations; the URL helpers behave identically.

---

## [1.0.0-beta.7.1.3] — Widget Inline Editor Fix (interaction + locale state)

A UI/logic-only fix for the `/admin/widgets` **inline editor**. No widget
schema, `WidgetManager` API, `widget_area()`/`widget()` contract, GitHub
installer, cache layer, or marketplace was added or changed. No UI redesign —
the beta.7.1.2 scoped layout is untouched.

### Fixed

- **"Edit opens nothing useful" / cannot type into the editor.** The inline
  editor wrapper carried a bare `x-collapse` with no paired `x-show`; Alpine's
  collapse plugin then pins the element to `height:0; overflow:hidden`, so the
  editor mounted but was invisible. Removed the standalone `x-collapse` (the
  Blade `@if` already adds/removes the editor).
- **Inputs unfocusable / untypable while editing.** The widget row is
  `draggable="true"` for drag-and-drop, and the editor (with its inputs) is a
  child of that row — a `draggable` ancestor stops the browser from
  focusing/selecting text in descendant inputs. The row now renders
  `draggable="false"` **only while its own editor is open**, so fields are fully
  editable; all other rows stay draggable. Drop order remains server-authoritative.
- **Switching locale discarded unsaved global settings.** `setEditLocale()`
  rebuilt the entire field state from persisted data, wiping any unsaved
  non-localized (global) edits. It now reloads only the localized title/fields
  for the new locale and preserves in-progress global edits.

### Notes (audit findings — already correct, no change needed)

- The inline editor is **schema-driven, not hardcoded**: fields come from each
  widget's `schema()` of `WidgetField`s (`getEditSchemaProperty()` →
  `WidgetManager::schemaFor()`).
- **Recent Posts `limit` is configurable (1–20, default 5)** via its
  `NumberField` and is applied at render; the 1–20 bound is a safety clamp, not
  a hardcoded count.
- Localized vs global persistence is correct: global → `widgets.settings`,
  localized → `widget_translations.settings`, title localized per locale, and
  `save()` only touches the currently selected locale's translation row.

---

## [1.0.0-beta.7.1.2] — Widget Admin UI Rebuild (scoped CSS)

A release-blocking, UI-only rebuild of the `/admin/widgets` page. No widget
schema, `WidgetManager` API, `widget_area()`/`widget()` contract, GitHub
installer, or cache behaviour was changed — only the two Blade views and their
translations.

### Fixed

- **Raw / unstyled Widgets page.** The page previously laid itself out with
  arbitrary Tailwind utilities (`grid-cols-10`, `md:col-span-3`, `space-y-*`,
  etc.). The Filament admin panel only ships Filament's own CSS bundle (`fi-*`
  classes); those app/package Blade utilities are **not** compiled into it (no
  custom Filament theme is registered via `->viteTheme()`, and the app Vite CSS
  is not injected into the panel), so the layout collapsed to plain stacked
  text. The page now drives its entire layout with **scoped CSS** under a stable
  `tn-widget-*` class prefix, so it renders correctly regardless of which
  Tailwind utilities exist in the bundle.

### Changed

- **`widgets-page.blade.php` rebuilt** with a scoped two-column grid
  (`.tn-widget-layout`, `minmax(280px,360px) / 1fr`, stacking ≤1024px): left =
  searchable, grouped **palette cards** (`.tn-widget-palette-*`) plus collapsible
  **Presets** and **Export / Import** panels; right = collapsible **area cards**
  (`.tn-widget-area`) containing **widget instance rows** (`.tn-widget-instance`)
  and a dashed `.tn-widget-empty` empty state.
- **Inline editor rebuilt** (`partials/widget-editor.blade.php`) with
  `.tn-widget-editor` General/Settings sections, styled fields, and a clear
  Cancel / Save actions row.
- **Drag-and-drop** keeps the native HTML5 implementation (no CDN/npm
  dependency for an offline admin); the dragging state is now styled via
  `.tn-widget-instance.is-dragging`. Server order remains authoritative through
  the existing `moveWidget` Livewire action.
- Full light/dark theming via scoped CSS custom properties; all static strings
  use `tn_trans()` with new keys added to `lang/en.json` and `lang/vi.json`.

### Notes

- Action buttons/icons still use Filament components (`x-filament::button`,
  `x-filament::icon-button`, `x-filament::icon`) because those rely on `fi-*`
  classes that **are** present in the panel bundle.
- Accessibility: collapsible headers are `<button>`s with `aria-expanded` /
  `aria-controls`, action buttons carry labels, and inputs have associated
  labels / `aria-label`s.

---

## [1.0.0-beta.7.1.1] — Widget UI Polish (WordPress-like UX)

A UI/UX-only iteration on the beta.7.1 widget system. No schema, `WidgetManager`
API, `widget_area()`, `widget()`, or preset/export-import contract was broken;
this layers polish and a few additive capabilities on top.

### Added

- **Duplicate a widget.** `WidgetManager::duplicate(int $widgetId)` clones a
  widget in place — same area, same global settings, every per-locale
  translation, inserted directly after the original (its parent title gains a
  " (copy)" suffix). Exposed as a per-row action in the admin.
- **Export by clipboard.** Alongside the JSON download, a **Copy JSON** button
  copies the current widget configuration to the clipboard (with a "Copied!"
  confirmation).
- **Import modes + file upload.** Import now offers **Append** (keep existing,
  the default) or **Replace** (clear the target area first), and accepts either
  pasted JSON or an uploaded `.json` file. `WidgetManager::import($data, 'replace')`
  is the new replace mode.
- **Polished admin UI:** searchable, grouped widget **cards** (icon + name +
  description, hover state) on the left; **collapsible widget areas** on the
  right whose open/closed state is remembered per area in `localStorage`
  (`tncms.widgets.area.{slug}`); widget rows show an enabled/disabled badge and a
  duplicate action; presets render as a **2-up card grid** with a thumbnail
  placeholder and their widget list. Layout is `grid` `1` → `md:grid-cols-10`
  (3/7 split on tablet & desktop, stacked on mobile).
- **Accessibility:** `aria-expanded` / `aria-controls` on area toggles,
  `aria-label`s on the search box, icon buttons, file input, and widget cards
  (which are keyboard-activatable via Enter); decorative icons are
  `aria-hidden`.

### Changed

- Inline editor and area lists animate open/closed with Alpine `x-collapse`.

### Notes

- Drag & drop remains Alpine + native HTML5 drag events (no SortableJS / npm /
  build step), persisted via `reorderWidgets()` / `moveWidget()`. Full keyboard
  drag-reordering is not implemented (rows are reordered with the mouse; all
  other actions are keyboard-accessible).
- `RepeaterField` still isn't editable inline (works via the API / presets /
  import). No vendor edits, no marketplace, no GitHub installer, no fragment cache.

---

## [1.0.0-beta.7.1] — Widget Polish & WordPress-like Widget UI

A UX-first refactor of the Widget Foundation. The database schema
(`cms_widget_areas`, `cms_widgets`, `cms_widget_translations`), the
`WidgetManager`, and the registry API are all preserved — this is polish, not a
rewrite.

### Added

- **WordPress-inspired Widgets admin.** A two-column board: **Available Widgets**
  (left, ~30%) and **Widget Areas** (right, ~70%), stacking on mobile. The left
  column has a live search box, widgets bucketed by **group** (Basic, Content, …),
  an "Add new widgets to" area selector, and Presets / Export-Import panels. The
  right column lists areas as **collapsible** cards.
- **Drag & drop.** The up/down buttons are gone; widgets are reordered within an
  area and **moved between areas** by dragging, persisted via the testable
  Livewire actions `reorderWidgets()` / `moveWidget()`. Implemented with Alpine +
  native HTML5 drag events — no JS build step or third-party library.
- **Inline editing (no modals).** Editing a widget expands its row in place with
  a locale switcher, the universal localized title, and the widget's fields;
  Save / Close inline.
- **Widget Field API.** A fluent field layer — `TextField`, `TextareaField`,
  `ToggleField`, `SelectField`, `NumberField`, `MediaField`, `RichEditorField`,
  `RepeaterField` (all extending `WidgetField`) — e.g.
  `NumberField::make('limit')->default(5)->min(1)->max(20)`,
  `TextField::make('title')->localized()`. Fields normalise to the beta.7 array
  shape, so a widget may declare its `schema()` with field objects **or** plain
  arrays; both work identically. The built-in widgets now use the field API.
- **Widget presets.** `widget()->registerPreset($slug, ['name' => …, 'widgets' =>
  [...]])`, `presets()`, `findPreset()`, `applyPreset($slug, $areaId)`. Applying a
  preset **appends** its widgets to an area and never overwrites existing ones.
  Core ships `blog-sidebar` and `simple-footer` presets.
- **Export / Import.** `widget()->export(?array $areaSlugs)` produces a portable
  JSON of areas, widgets, settings, and per-locale translations; `import($data,
  'merge')` is merge-only — it appends, matches areas by slug (creating unknown
  ones), skips unregistered widget types, and never overwrites or loses
  translations. Exposed in the admin as a JSON download + paste-to-import.

### Changed

- **The Widgets admin now lives in cms-core** at
  `TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage`, registered on the panel by
  `PluginResourceRegistrar` and rendering the package view `cms::filament.pages.widgets-page`.
  The host app no longer needs `app/Filament/Admin/Pages/WidgetsPage.php` (removed).
  A `cms` view namespace was registered for package-owned admin views.
- Built-in widgets gained a `group()` (Basic / Content) for the picker.

### Notes

- Rendering safety is unchanged from beta.7: a broken widget is caught and
  reported, `widget_area()` never throws, and a render failure shows a debug-only
  HTML comment (`APP_DEBUG=true`) or nothing in production.
- Deliberately **not** included: widget HTML fragment cache, marketplace,
  GitHub/URL installer, vendor modifications. `RepeaterField` is wired through the
  API + import/presets but is not yet editable inline in the admin.

---

## [1.0.0-beta.7] — Widget Foundation

### Added

- **Widget system.** A Laravel-native widget foundation (in the spirit of
  WordPress/Botble widgets). Widgets are self-describing render units registered
  from core, themes, or plugins; widget areas (sidebars/slots) are named
  placements a theme exposes. Three tables: `cms_widget_areas`, `cms_widgets`
  (global settings + fallback title), `cms_widget_translations` (per-locale
  title + localized settings). Rendered HTML is never stored.
- **`WidgetManager`** (`cms.widget`, facade `Widget`): `register()`,
  `registered()`, `find()`, `available()`, `schemaFor()`, `registerArea()`,
  `areas()`, `syncAreas()`, `renderArea()`, `renderWidget()`. Invalid widget
  classes are skipped and `report()`ed; a duplicate type is last-wins (reported);
  any failure rendering a single widget is caught and `report()`ed so the
  frontend never 500s (a debug-only HTML comment is emitted in its place when
  `APP_DEBUG=true`, otherwise nothing).
- **Abstract `Widget` base class** (`TheNguyen\CMS\Widgets\Widget`) with a simple
  field schema (`text`, `textarea`, `number`, `toggle`, `select`, `media`,
  `html`; fields may be `localized`).
- **Built-in widgets:** `TextWidget` (escaped text), `HtmlWidget` (sanitized via
  the existing `HtmlSanitizer`), `RecentPostsWidget` (limit clamped 1–20,
  locale-aware `content_url`), `CategoriesWidget` (locale-aware `term_url`,
  optional post count).
- **Core widget areas:** `sidebar-blog`, `sidebar-page`, `footer-1`, `footer-2`,
  `footer-3`, `before-footer`, `after-post`. Themes/plugins may register more.
- **Helpers:** `widget()` (the manager) and `widget_area($slug, $locale)`
  (renders an area to safe HTML; returns `''` for an empty/inactive/unknown area;
  never throws).
- **Admin UI:** Appearance → Widgets (`/admin/widgets`), guarded by the new
  `widgets.manage` permission (granted to super-admin/admin, not editor/author).
  Add a widget to an area, edit its localized title/settings, enable/disable,
  move up/down, and delete. Drag-and-drop reordering is intentionally deferred.
- **Theme/plugin registration.** A theme's `functions.php` may return `widgets`
  (widget class names) and `widget_areas` (`['slug' => …, 'name' => …]`) arrays;
  plugins register widgets from their service providers via
  `widget()->register(MyWidget::class)`. The default theme renders the
  `footer-1/2/3` and `sidebar-blog` areas.
- **`/cms-health`** gains `widgets_ready`, `widget_count`, `widget_area_count`,
  and `active_widget_count` (counts only).

### Changed

- The public content cache is now invalidated when a widget instance, widget
  translation, or widget area is created/updated/deleted (the version bump joins
  the existing content/term/menu/settings hooks). No widget fragment cache yet.
- Default theme footer renders the `footer-1/2/3` widget areas when any are
  populated, falling back to the existing menu-driven footer otherwise; the
  content sidebar partial renders the `sidebar-blog` area.

### Notes

- Settings resolution order at render time: schema defaults → parent global
  settings → the requested locale's translation → the CMS default locale's
  translation. Localized fields never leak across locales when a translation
  exists.
- Not in scope for this release (deliberately deferred): widget HTML fragment
  cache, drag-and-drop reordering, GitHub/URL extension installer.

---

## [1.0.0-beta.6.4.2] — Boot Hardening & Query Insight

### Added

- **DB-free install fast path.** `InstallerManager::isInstalledQuick()` checks
  only the marker file and `TN_CMS_INSTALLED` (no database). `isInstalled()` is
  now `quick OR database-backstop`, so a properly installed site is detected
  with zero queries.
- **Boot hardening.** `CmsServiceProvider::boot()` resolves install state once
  (fast path first) and skips every database-touching boot step (theme view
  registration, active-plugin booting, extension translations) until the CMS is
  installed. A fresh, pre-install boot performs no feature queries and never
  fails on an unmigrated/unreachable database. Installer and core routes always
  register.
- **Per-request query budgets.** New `QueryInsights` middleware on public routes
  counts queries and `report()`s when a page type's budget is exceeded — it
  never alters or fails the response. Defaults (config `cms.performance.query_budget.budgets`):
  homepage 15, content 5, archive 10, category 10, tag 10, default 25.
- **Opt-in query profiler.** With `CMS_DEBUG_QUERIES=true`, public responses
  carry `X-TNCMS-Queries` (count), `X-TNCMS-Time` (ms), and `X-TNCMS-Cache`
  (`HIT`/`MISS`/`BYPASS`/`NONE`). Fully off by default.
- **`/cms-health` performance posture (booleans only)** — `boot_safe`,
  `settings_hot_path`, `language_hot_path`, `extension_registry_cache`,
  `query_budget_ready`, `query_profiler_ready`.

### Changed (performance, behavior-preserving)

- **Hot-path memoization.** `SettingsManager::translationsTableExists()` and
  `LanguageManager` table/default-language resolution are memoized per request,
  removing repeated `Schema::hasTable` and default-language queries on every
  localized read. A typical homepage dropped from ~37 to ~19 queries in local
  measurement.
- **Extension registry memoization.** `ThemeManager` and `ExtensionManager`
  cache their directory scans (valid + invalid) per request, with
  `flushRegistry()` invalidation wired into install/delete (`refreshDiscovery()`)
  and theme activation.
- Public page types are tagged on the request (`cms.page_type`) by
  `FrontendController` so the budget/profiler classify homepage/content/
  archive/category/tag accurately, including catch-all slug resolution.

### Notes

- **Sites installed without the web wizard** (e.g. `artisan migrate --seed`) have
  no marker/env flag; boot uses one guarded DB existence check for them. Set
  `TN_CMS_INSTALLED=true` to keep their boot fully query-free.
- The pre-existing `:memory:` SQLite test harness still cannot boot the package
  for full-request feature tests (a connection-reconnect fragility unrelated to
  this work). New pure unit tests (`QueryInsightsTest`) pass; the boot hardening,
  memoization, budgets, profiler headers, and health booleans were verified
  end-to-end against the real environment (16/16 checks).

---

## [1.0.0-beta.6.4] — Security & Storage Hardening

### Added

- **Server-side media upload validation.** `MediaManager::upload()` is now the
  single enforcement chokepoint for every upload entry point (media library,
  media picker, rich editor). It enforces, in order: a hard deny-list of
  executable/scriptable extensions (`php`, `phtml`, `phar`, `exe`, `bat`, `sh`,
  `js`, `html`, …) that wins even if mis-whitelisted; an allow-list of
  extensions; a real-MIME allow-list read from `finfo` (defeats a PHP payload
  disguised behind a permitted extension); and a size ceiling. Filament's
  client-side `acceptedFileTypes()` is treated as a UX hint only and is never
  relied on for safety. Violations raise a `ValidationException` surfaced inline.
- **`cms.media` config** — `max_upload_size` (`CMS_MEDIA_MAX_UPLOAD_SIZE`, bytes),
  `allowed_extensions` (default `jpg, jpeg, png, gif, webp, avif, pdf`),
  `allowed_mimes`, `denied_extensions` (always-on hard block), and `allow_svg`
  (`CMS_MEDIA_ALLOW_SVG`, default `false`).
- **Opt-in sanitized SVG.** SVG is denied by default. When `cms.media.allow_svg`
  is enabled and `svg` is whitelisted, every upload is run through the new
  dependency-free `SvgSanitizer` (strips `<script>`, `on*` handlers,
  `<foreignObject>`/animation elements, and `javascript:`/`data:` URLs, and
  neutralizes DOCTYPE/ENTITY for XXE) before it is written to disk; unparseable
  SVG is rejected.
- **Public upload tree guards** — `public/uploads/.htaccess` (Apache 2.2 + 2.4)
  and `public/uploads/web.config` (IIS) disable script execution, directory
  listing, and access to dot/sensitive files, applied recursively to
  `uploads/YYYY/MM/…`.
- **Installer DB backstop.** `InstallerManager::isInstalled()` now treats a
  reachable, populated database as a third "installed" signal alongside the
  marker file and `TN_CMS_INSTALLED`, so deleting the marker *and* clearing the
  env flag can no longer re-open the wizard on a live site. DB errors during a
  genuine fresh install are `report()`ed and treated as not-installed.
- **`/cms-health` security posture (booleans only)** — `storage_secure`,
  `media_upload_secure`, `installer_secure`, `cache_secure`, `sanitizer_secure`,
  `public_file_secure`. No paths, extension lists, MIME lists, or other server
  internals are exposed.

### Changed

- **Media library page** derives its client accept-list and size cap from the
  new `cms.media` config (single source of truth), so the picker can never
  advertise a type the server rejects. The page no longer advertises DOC/DOCX/
  ZIP/TXT uploads.

### Audited — no change required

- **HTML sanitizer.** The existing `HtmlSanitizer` (DOMDocument allow-list)
  already blocks every tested stored-XSS vector: `<script>`, `<iframe>`, `<svg>`,
  `<math>`, `<foreignObject>`, `on*` handlers, and `javascript:`/`data:text/html`
  URLs. No change made.
- **Public content cache.** Cache keys are namespaced by version + locale +
  path hash; authenticated, non-GET, and draft/preview responses are never
  cached; query strings are excluded from keys. No poisoning vector found.
- **`storage/{path}` serving.** No route serves raw storage paths; uploads are
  static files under the public web root with slugified filenames (no traversal),
  and the default filesystem disk is private. No change made beyond the guards
  above.

### Notes

- **Behavior change:** existing DOC/DOCX/ZIP/TXT uploads now require an explicit
  opt-in via `cms.media.allowed_extensions` + `cms.media.allowed_mimes`. This is
  a deliberate fail-safe default (Security first); add the types back in config
  if your site needs them.
- The pre-existing `:memory:` SQLite test harness cannot boot the package
  (`CmsServiceProvider::boot()` reads settings before migrations exist), so the
  DB-backed suite was already red before this work. New tests
  (`MediaUploadSecurityTest`, `InstallerLockTest`, `SecurityHealthTest`,
  `SvgSanitizerTest`) are included and the hardening was verified end-to-end
  against the real environment; `SvgSanitizerTest` (no DB) passes today.

---

## [1.0.0-beta.6.3] — Public Path Performance & Query Safety

### Added

- **Public content resolution cache.** New `PublicContentCacheManager`
  (`cms.public_cache`, facade `PublicContentCache`, helper
  `public_content_cache()`) caches the resolved `cms_slugs` reference metadata
  (`reference_type` + `reference_id`) for public URLs, so repeated guest hits to
  the same path skip the slug lookup. It caches metadata only — never HTML — and
  reuses the existing load/render flow. Negative (not-found) results are cached
  too. TTL is `config('cms.cache.public_ttl')` (`CMS_PUBLIC_CACHE_TTL`, default
  `3600`); set it to `0` to disable the public cache entirely. Authenticated,
  non-GET, and preview/draft requests are never cached.
- **Versioned cache invalidation.** Every public cache key embeds a monotonic
  version (`tncms.public.version`); `public_content_cache()->flush()` bumps it,
  orphaning the whole namespace in one write — works on cache drivers without tag
  support (file/database/array). Eloquent `saved`/`deleted` events on Content,
  Term, their translations, Slug, Menu/MenuItem (+ translations), and Setting/
  SettingTranslation flush automatically. Theme activation and permalink/language
  changes persist through Setting, so they invalidate too.
- **`/cms-health` fields** — `public_cache_ready`, `public_cache_enabled`,
  `public_cache_ttl`, `public_cache_version`, and `settings_hot_path_optimized`.
  No keys, paths, or values are exposed.

### Changed

- **Term archives are paginated.** `FrontendController::renderTermArchive()` now
  uses `paginate()` keyed off `reading.posts_per_page` (default 10, floored at 1,
  hard-capped at 100) instead of an unbounded `->get()`, so a category/tag with
  thousands of posts no longer loads them all into memory. The default theme
  archive view renders Previous/Next pagination links (paginator only — the
  homepage latest-posts collection is unaffected).
- **`SettingsManager` hot path.** `settings()->get()` no longer calls
  `Schema::hasTable('cms_settings')` (an `information_schema` query) on every
  call. Table availability is resolved once — trusting the install lock after
  install, falling back to a guarded schema check before install — then memoized
  on the singleton. Reset by `clearCache()`.

### Fixed

- Replaced empty/silent `catch` blocks in the files touched by this phase with
  `report($e)` so swallowed failures are logged.
- **Default theme duplicate content rendering (beta.6.3.1 bugfix).** The default
  theme wrapped an old copy of the content region in an HTML comment
  (`<!-- ... -->`) in `layouts/master.blade.php` and `pages/home.blade.php`.
  Blade compiles `@yield`/`@section` regardless of HTML comments, so the content
  section rendered a **second time** — present in the page source (just visually
  hidden), duplicating links and body text and skewing SEO text density and
  archive tests. The home view's duplicate also re-declared `@section('content')`,
  overriding the refined block. Removed both dead commented blocks so content
  renders exactly once. No layout/cache/controller/route changes.

### Notes
- Follow-up: a full observability pass over the remaining silent `catch` blocks
  across the codebase is still pending (only touched files were updated).
- `ExampleTest::test_the_application_returns_a_successful_response` remains a
  pre-existing failure: it hits `/` without running migrations, so the slug
  resolver queries a non-existent `cms_contents` table. Unrelated to this phase.

---

## [1.0.0-beta.6.2] — Localized Settings Framework

### Added

- **Per-language CMS settings.** A new `cms_settings_translate` table stores one
  value per `(key, locale)` for translatable settings. `cms_settings` is left
  unchanged and remains the permanent global fallback. Localized keys:
  `general.site_name`, `general.site_tagline`, `general.site_description`,
  `seo.default_meta_title`, `seo.default_meta_description`, `maintenance.title`,
  `maintenance.message`.
- **`SettingsManager` localized API** — `getLocalized($key, $locale, $default)`,
  `localized()` (alias), `setLocalized($key, $locale, $value, $type)`,
  `forgetLocalized()`, `isLocalized()`, `rawLocalizedValue()`,
  `backfillLocalizedDefaults()`, and `localizedHealth()`. Resolution order:
  requested locale → CMS default locale → global `cms_settings` → provided
  default. Never throws — a missing table or translation falls through.
- **Helpers** `setting_localized($key, $locale = null, $default = null)` and the
  namespaced alias `cms_setting_localized()`. With no locale they resolve against
  the current request locale.
- **Settings page language switcher.** A "Language" select at the top of
  Settings reloads only the translatable fields for the chosen language; saving a
  locale writes only that locale's rows. Saving the default locale also syncs the
  global `cms_settings` value so plain `setting()` and untranslated locales keep
  resolving to the default-language value. Permalinks stay global (localized
  bases affect routing/sitemap/hreflang and belong to a separate phase).
- **Health metrics** (counts only, never keys/locales/values/paths):
  `localized_settings_ready`, `localized_settings_keys_count`,
  `localized_settings_locales_count`.
- **`LocalizedSettingsTest`** covering independence, helper resolution, the
  fallback chain, per-locale page saves, SEO/maintenance/theme integration, and
  the count-only health output.

### Changed

- **SEO** default meta title/description and the site name/description fallbacks
  now resolve per the SEO context locale via `setting_localized()`.
- **Maintenance** page title/message and site name now render in the current
  locale.
- **Default theme** header and footer read the site name/tagline with
  `setting_localized()`, so they switch with the active language. Plain
  `setting('general.site_name')` continues to work unchanged.

### Notes

- Install/seed backfills the CMS default locale's translation rows from the
  existing global values (idempotent), so the default language shows real values
  immediately. Global values are never removed.

---

## [Unreleased] — Localized menu-item URL fix

### Fixed

- **Menu item URLs are now per-locale.** Custom menu-item URLs lived in the
  single shared `cms_menu_items.url` column, so saving one locale (e.g. the
  English "About us" → `/en/about-us`) overwrote every other locale's URL; the
  Vietnamese menu then linked to the English URL. Custom URLs now live per-locale
  alongside the title in `cms_menu_item_translations.url`, and the admin loads /
  saves only the selected locale's row — saving EN never touches VI and vice
  versa.
- **Entity-linked menu items now resolve the locale-prefixed URL.**
  `MenuItem::resolvedUrl($locale)` read `cms_slugs.full_path` directly, returning
  e.g. `/about-us` for English instead of `/en/about-us`. It now calls
  `content_url()` / `term_url()`, which apply the `/{locale}/` prefix from the
  localized slug. The exact-locale custom URL is read with no cross-locale
  fallback, so one locale's URL can no longer leak into another.

### Added

- Migration `add_url_to_cms_menu_item_translations_table` — nullable per-locale
  `url` column for custom menu items. Entity items leave it null (URL derived
  from the slug). No data backfill required.
- `php artisan tncms:menus:repair-localized-urls` — backfills the **default**
  locale's translation URL from the legacy shared column for existing custom
  items (other locales are never fabricated; entity items self-heal). Supports
  `--dry-run`.

---

## [1.0.0-beta.6.1] — Localized Slugs (verification + tooling)

Per-locale slugs for pages, posts, categories, and tags. **The localized-slug
data model was already in place** (the `*_translations` tables own a per-locale
`slug`, unique per `(locale, slug)`; `cms_slugs` is the per-locale public-URL
resolver keyed by `(reference_type, reference_id, locale)`). This release
verifies that end-to-end behaviour with a full regression suite and adds the
missing repair tooling and UI affordances. **No schema change, no backfill
migration** — the parent `cms_contents`/`cms_terms` tables carry no slug column,
so there is nothing to migrate or de-duplicate.

### Added

- **`tncms:slugs:rebuild` artisan command** + `SlugManager::rebuildAllPublicSlugs()`:
  rebuilds every `cms_slugs` public-URL row from the per-locale translation slugs
  (`cms_content_translations.slug` / `cms_term_translations.slug`), recomputing
  `prefix`/`full_path` from the current permalink bases. Idempotent; preserves the
  bare translation slug verbatim (no uniqueness re-resolution). A repair/backfill
  tool for installs whose public-slug rows are missing or stale; `cms_slugs` is
  otherwise kept in sync on every content/term save.
- **`tests/Feature/LocalizedSlugTest.php`** (18 tests): independent per-locale
  slugs; editing one locale never changes another; `content_url()`/`term_url()`
  per-locale output; frontend resolution of the default-locale and prefixed-locale
  slugs; wrong-locale → 404; same slug allowed across locales; same-locale + reserved
  conflicts auto-increment; localized sitemap, hreflang alternates, and language
  switcher; missing-translation slug does not leak; rebuild repairs missing rows.

### Changed

- **Slug field helper text** on Page/Post/Category/Tag now states the slug is
  language-specific ("changing it affects only the selected language").
- **Version** → `1.0.0-beta.6.1` (`CmsInfo::VERSION`).

### Notes

- **Slugs are locale-specific.** `cms_content_translations` / `cms_term_translations`
  own `title`/`slug`/`excerpt`/`content`/SEO per locale. `vi: home` and `en: home`
  may coexist; a same-locale conflict (or a reserved prefix such as `admin`,
  `install`, `themes`) auto-increments (`home-2`). `cms_slugs` is the public URL
  resolver — `findPublic($path, $locale)` matches `full_path` within the locale, so
  `/en/trang-chu` 404s unless `trang-chu` is genuinely the `en` slug.
- The parent tables have **no** shared/legacy slug column; route resolution and all
  URL helpers already read the translation slug exclusively.

---

## [1.0.0-beta.6] — Web Installer Core

A first-run web installer at `/install`: requirement checks → database/site
config → super-admin → migrate/seed → lock. Simple Blade UI (no Filament, no
admin login). No GitHub/URL installer, no CLI installer, no marketplace, no new
panel, no vendor edits.

### Added

- **`InstallerManager` service** (`cms.installer`), the **`Installer`** facade, and the
  **`installer()`** helper. API: `isInstalled()`, `canRun()`, `markInstalled()`,
  `requirements()`, `requirementsPassed()`, `detectAppUrl()`, `writeEnv()`,
  `testDatabaseConnection()`, `applyRuntimeDatabase()`, `ensureAppKey()`, `runMigrations()`,
  `runSeeders()`, `applyDefaultLanguage()`, `clearCaches()`, `createSuperAdmin()`,
  `frontendUrl()`, `adminUrl()`.
- **Installer routes** (`routes/installer.php`), registered before the frontend catch-all
  and outside the maintenance gate: `GET /install`, `GET /install/requirements`,
  `GET|POST /install/database`, `GET|POST /install/admin`, `GET /install/finish`.
- **Blade installer UI** (`resources/views/install/{layout,welcome,requirements,database,admin,finish}.blade.php`):
  a clean centred, mobile-friendly card with TN CMS branding and a 5-step progress bar.
- **`InstallerSession` middleware** — forces a **file** session for installer requests so
  CSRF/sessions work before the database exists (the app default is the `database` driver).
- **`RedirectIfInstalled` middleware** — once locked, every step except `/finish` redirects
  to `/`, so a live site can never re-run requirements, rewrite `.env`, re-migrate, or create
  another super-admin.
- **Install lock** — the CMS is installed when EITHER the marker file
  `storage/app/tncms-installed` OR `TN_CMS_INSTALLED=true` is present; `markInstalled()`
  writes both.
- **`cms.admin_path` config** (`config/cms.php`, env `ADMIN_PATH`, default `admin`). The
  Filament admin panel path now reads it, so the installer's Admin URL choice is honoured.
- **`/cms-health` fields:** `web_installer_ready` (bool), `cms_installed` (bool),
  `installer_locked` (bool). No installer paths or collected credentials are ever exposed.

### Changed

- `App\Providers\Filament\AdminPanelProvider` reads `config('cms.admin_path')` for the panel
  path (was hardcoded `admin`).
- `CmsInfo::VERSION` → `1.0.0-beta.6`.
- Frontend catch-all reserved prefixes now include `install`.

### Security

- The installer runs only when not installed; locked steps redirect (and POSTs are CSRF
  protected, so a locked POST is rejected before any work runs).
- The database password is verified, written to `.env`, and held only in the server-side
  session — it is never echoed to the UI, logged, or exposed in `/cms-health`. Connection
  errors are scrubbed of the password and truncated.
- `APP_KEY` is generated only when missing (avoids invalidating the active installer session
  mid-flow). Migrations run with `--force`; no destructive commands are used.

### Fixed

> Language Core bugfix pass (within beta.6, no version bump) — hardening the
> language layer before the upcoming localized-slug refactor. See
> `LANGUAGE_SETTINGS_AUDIT.md`.

- **B1 — CMS locale fallback.** Content/term translation fallback no longer reads
  `config('app.locale')` nor a hardcoded `'vi'`; it resolves through
  `app('cms.language')->defaultCode()`. Fixed in `Content::translation()`/
  `resolveTranslation()`, `Term::translation()`/`resolveTranslation()`, the
  write-time locale fallback in `ContentManager`, `TaxonomyManager`, and
  `MenuManager`, so authoring/reading follows the configured CMS default even
  when the framework locale differs.
- **B2 — Language code normalization.** The Languages form now lowercases/trims the
  `code` before validation and constrains its format
  (`^[a-z]{2}(-[a-z]{2})?$`, e.g. `vi`, `en`, `pt-br`). Entering `EN` when `en`
  exists now fails with a friendly validation error instead of a duplicate-key
  `QueryException` after normalization.
- **B3 — Safe language deletion.** `LanguageManager::delete()` refuses (non-destructively)
  to delete the default language, the last remaining language, or any language
  that still owns rows in `cms_content_translations`, `cms_term_translations`,
  `cms_menu_translations`, `cms_menu_item_translations`, or `cms_slugs`. New
  `deletionBlockReason()` / `hasTranslatedData()` expose the reason to the admin
  UI. No cascade delete in this pass (deferred).
- **B4 — Language permission.** `LanguageResource` (view/create/edit/delete) is now
  gated by `languages.manage` instead of `settings.manage`, matching the sync
  actions. The seeded `admin` and `super-admin` roles already hold it.
- **B5 — Route cache after language changes.** Creating, editing, or deleting a language
  clears the route cache (`route:clear`) so the localized route pattern
  (`vi|en|…`) reflects the change, with an admin notification; a failure warns to
  run `php artisan route:clear` manually instead of breaking the request.
- **B8 — Helper locale defaults.** `cms_slug()` and `menu()` no longer default to `'vi'`;
  they resolve the locale via the argument → `current_locale()` → CMS default.
  `frontend_menu()` already resolved through `current_locale()`.

### Notes

- **Language Core known limitation:** several read-API method *signatures* still carry a
  `string $locale = 'vi'` default (e.g. `ContentManager::findBySlug/getTranslation`,
  `MenuManager` read methods, `TaxonomyManager::findTermBySlug`, `SeoManager`, and
  the menu model display helpers). These are always invoked with an explicit
  locale from the fixed call sites today, so they do not manifest at runtime;
  full signature normalization is deferred to the localized-slug refactor.
- **Requirements checked:** PHP ≥ 8.3; extensions `openssl`, `pdo`, `pdo_mysql`, `mbstring`,
  `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `zip`, and `gd` **or** `imagick`;
  writable `.env`/project root, `storage/`, `bootstrap/cache/`, `public/`; `pdo_mysql`
  available; `APP_URL` detectable.
- **Seeders run (in order, missing classes skipped):** `CmsLanguageSeeder`,
  `CmsSettingsSeeder`, `CmsContentSeeder`, `CmsMenuSeeder`, `CmsRolePermissionSeeder` — then
  the super-admin is created and granted the `super-admin` role explicitly.
- **Reset (local dev only):** delete `storage/app/tncms-installed` and remove
  `TN_CMS_INSTALLED` from `.env`, then `php artisan optimize:clear`.
- Out of scope (unchanged): GitHub/URL extension installer, CLI installer, marketplace, auto
  updater, license manager, file editor, AI features, new Filament panel. No vendor edits.

---

## [1.0.0-beta.5.2] — Health & Translation Cleanup

A cleanup/hardening pass over `/cms-health` and the remaining admin translation
gaps, ahead of the upcoming Web Installer Core. No new tables, panels, or
features; no vendor files modified.

### Changed

- **Canonical, de-duplicated `/cms-health` metric names.** The endpoint had been
  emitting both plural and singular aliases for the same value. Each metric now
  has **one canonical name** (singular `*_count`):
  - `themes_count` → removed; use **`theme_count`**.
  - `invalid_themes_count` → removed; use **`invalid_theme_count`**.
  - `plugins_count` → removed; use **`plugin_count`**.
  - `invalid_plugins_count` → removed; use **`invalid_plugin_count`**.
  - `active_plugins_count` → renamed to **`active_plugin_count`**.
  - `languages_count` → renamed to **`language_count`**.
  - `active_languages_count` → renamed to **`active_language_count`**.
  - `roles_count` → renamed to **`role_count`**.
  - `permissions_count` → renamed to **`permission_count`**.
  - `active_plugins` (the slug array) is unchanged. As TN CMS is still beta, the
    removed plural aliases are **not** retained for backward compatibility.
- **Filesystem paths are off by default (opt-in only).** `base_path` and `public_path`
  are emitted **only when `config('cms.health.show_paths') === true`** (env
  `CMS_HEALTH_SHOW_PATHS`, default `false`). The flag is **not** tied to `APP_DEBUG` —
  even with `APP_DEBUG=true` the public health endpoint exposes no server paths unless an
  operator explicitly opts in for local debugging. No other absolute paths (storage,
  plugin, theme, or installer temp paths) are exposed in any mode.

### Added

- **`health_output_clean` (bool)** summary field on `/cms-health`. It is `true` when
  the canonical metric set is de-duplicated, **no** absolute filesystem paths are exposed,
  and the translation-health metrics resolved to valid integers. Enabling
  `CMS_HEALTH_SHOW_PATHS=true` flips it to `false`. Computed defensively — never throws;
  `/cms-health` stays `200`/`ok`.
- **`health_output_debug_paths_enabled` (bool)** field on `/cms-health`, mirroring
  `config('cms.health.show_paths')` (default `false`). Makes the opt-in path exposure
  explicit and machine-readable.
- **`cms.health.show_paths` config** (`config/cms.php`), read from `CMS_HEALTH_SHOW_PATHS`
  (default `false`).
- **Last super-admin notification is now translatable.** The two strings in
  `EditUser::beforeSave()` ("Cannot remove the last super admin" / "Assign the
  super-admin role to another user before removing it here.") now resolve through
  `tn_trans()`, with matching `lang/en.json` and `lang/vi.json` entries. This was the
  only remaining hardcoded English admin string after the beta.5.1 audit.

### Notes

- `core_translation_keys` remains a **deprecated alias** of `core_translation_keys_count`
  (mirrors its value) and **will be removed in a future release** — migrate monitoring to
  the `*_count` name. The translation health metrics (`core_translation_keys_count`,
  `core_translation_missing_keys_count`, `core_translation_untranslated_keys_count`,
  `core_translation_files_count`, `theme_translation_files_count`,
  `plugin_translation_files_count`, `extension_translation_ready`,
  `admin_translation_ready`, `filament_translation_ready`) are finalised and unchanged in
  meaning. Missing JSON returns a safe `0`, invalid JSON never 500s, and language fallback
  still works.
- **Remaining vendor translation limitation:** Filament's own built-in strings (Create /
  Save / Delete / Search / pagination / "No records found") rely on Filament's **shipped**
  per-locale translations, aligned to the CMS locale by `SetCmsAdminLocale`. They are
  localised only for locales Filament publishes (Vietnamese and English both ship). No
  vendor files are modified; TN CMS does not translate vendor internals.
- `CmsInfo::VERSION` → `1.0.0-beta.5.2`; `/cms-health` reports the new version.
- Out of scope (unchanged): Web/CLI/GitHub/URL installers, marketplace, translation editor
  UI, AI translation, `.po`/`.mo` support, extension file editor. No new database tables or
  Filament panel.

---

## [1.0.0-beta.5.1] — Admin Translation Completion

### Added

- **Fully translatable TN CMS Admin UI.** Every core-controlled admin string —
  resource form labels, table columns, helper texts, section/tab headings, Select
  option labels, filters, bulk/record actions, modal headings/descriptions, and
  notifications — now resolves through `tn_trans()` / `core_trans()`. No hardcoded
  English strings remain in TN CMS Core admin pages, resources, components, widgets,
  or admin Blade views.
- **`SetCmsAdminLocale` middleware** (registered on the Filament admin panel). It
  aligns `app()->setLocale()` with the CMS current locale on every admin request so
  **Filament's own built-in strings** (Create / Save / Delete / Search / pagination /
  "No records found") follow the same language as the TN CMS interface. Filament's
  shipped per-locale translations are used as-is — **no vendor files are modified**.
- **Vietnamese validation messages** at `lang/vi/validation.php` (Laravel's standard
  mechanism; the framework already ships the English set). Validation now follows the
  locale, e.g. *"Trường tiêu đề là bắt buộc."*
- **Core JSON dictionaries expanded** to the full admin string set: `lang/en.json` and
  `lang/vi.json` are alphabetically sorted, deduplicated, and key-for-key identical
  (every English key has a Vietnamese value). Placeholder keys use Laravel `:tokens`.
- **`/cms-health` fields:** `admin_translation_ready` (bool), `filament_translation_ready`
  (bool), `core_translation_keys_count` (int — number of keys in the default core JSON
  dictionary, e.g. `427`), `core_translation_missing_keys_count` (int — default keys
  absent from non-default active locales), and `core_translation_untranslated_keys_count`
  (int — non-default active values that are blank or still equal to the source key; the
  English source locale is exempt from the equal-to-key rule). `core_translation_keys`
  is **retained for backward compatibility** and now mirrors `core_translation_keys_count`.
  No filesystem paths or sensitive data are exposed; the computation never throws and
  `/cms-health` stays `200`/`ok` even when a translation file is missing or invalid JSON.
- **`ExtensionTranslationManager` methods:** `coreKeyCount(?locale)`,
  `adminTranslationReady()`, `untranslatedCoreKeyCount()`, and `coreTranslationStats()`
  (returns `keys_count` / `missing_keys_count` / `untranslated_keys_count`; all guarded,
  path-free).
- **Tests:** `tests/Feature/AdminTranslationTest.php` covers VI/EN resolution, placeholder
  replacement, missing-key/locale fallback, invalid/blank-JSON safety, EN↔VI key parity
  (no admin label can return a raw key), and the new manager methods.

### Changed

- `CmsInfo::VERSION` → `1.0.0-beta.5.1`. `/cms-health` reports the new version and fields.

### Notes

- The admin display locale follows the **CMS default language** (the admin has no
  `/{locale}` URL prefix, so `current_locale()` resolves to the default). Switching the
  default language between `vi` and `en` switches the entire admin UI — TN CMS strings,
  Filament built-ins, and validation messages — with no mixed-language screens.
- Theme/plugin interface strings remain in their own `lang/` folders (unchanged from
  beta.5); only core strings live in the core `lang/*.json` files.
- Out of scope (unchanged): GitHub/URL installers, marketplace, translation editor, AI
  translation, `.po`/`.mo` support. No new database tables.

---

## [1.0.0-beta.5] — Extension Translation Framework

### Added

- **JSON interface-translation framework** for CMS core, the active theme, and active
  plugins — separate from content translations. Laravel-style JSON (no `.po`/`.mo`),
  no new table, no new panel.
- **`ExtensionTranslationManager`** service (`cms.extension_translation`), the
  **`ExtensionTranslation`** facade, and the **`extension_translation()`** helper.
  API: `core()`, `theme()`, `plugin()`, `registerActiveTranslationPaths()`,
  `syncTranslationFiles()`, `fileCounts()`.
- **Helpers:** `core_trans()`, `theme_trans()`, `plugin_trans()`, and `tn_trans()`
  (alias of `core_trans()`), with Laravel-style `:key`/`:Key`/`:KEY` replacement and
  an optional explicit locale (defaults to `current_locale()`).
- **Fallback chains:** core → key; theme → core → key; plugin (`lang/` then
  `resources/lang/`) → core → key; each tries the requested locale then the default
  locale. Missing files / invalid JSON never crash — the lookup returns the key.
- **Boot integration:** active theme + active-plugin `lang/` dirs are registered on
  Laravel's translator (`addJsonPath`, additive — core `__()` still works).
- **Sync Translation Files** action on `LanguageResource` (header + per-row), plus a
  `syncTranslationFiles(?array $locales)` service method that creates missing empty
  `{}` files for core/theme/active-plugins without overwriting; `CreateLanguage`
  syncs the new locale automatically (best-effort).
- **Default files:** `lang/{en,vi}.json`, `themes/default/lang/{en,vi}.json`,
  `plugins/hello-world/lang/{en,vi}.json`. The default theme archive and the
  hello-world view now use `theme_trans()` / `plugin_trans()`.
- **Admin/core UI is translation-ready:** TN CMS navigation labels, navigation
  groups (Content, Media, Appearance, Plugins, Users, CMS), model labels, page
  titles, Settings tab labels, and key notifications resolve through
  `tn_trans()`/`core_trans()` and follow the current locale (English fallback
  preserved). Filament vendor internals are intentionally left untranslated.
- **Permission:** `languages.manage` (group *Settings*), seeded to `super-admin` and
  `admin`.
- **Health flags:** `extension_translation_ready`, `core_translation_files_count`,
  `theme_translation_files_count`, `plugin_translation_files_count` (counts only —
  never absolute paths).

### Not implemented

- Auto translation, AI translation, a translation editor UI, `.po`/`.mo`
  import/export, marketplace language packs.

### Roadmap

- v1.0.0-beta.5 — **done.** Next planned: **v1.0.0-beta.6 — GitHub / URL Extension
  Installer**.

---

## [1.0.0-beta.4] — Maintenance Mode Core

### Added

- **Built-in maintenance mode** — site owners no longer need a plugin for a basic
  feature that belongs in core. When enabled, public visitors see a maintenance
  page while the admin panel, login, Livewire, `cms-health`, `robots.txt` and
  `sitemap.xml` stay accessible.
- **`MaintenanceManager`** service (`cms.maintenance`), the **`Maintenance`** facade,
  and the **`maintenance()`** helper. API: `isEnabled()`, `mode()`, `statusCode()`,
  `retryAfterMinutes()`, `excludePaths()`, `allowedIps()`, `settings()`,
  `shouldBypass()`, `isExcludedPath()` (+ the pure static `pathIsExcluded()`),
  `response()`, `render()`. Every value is read through the table-guarded settings
  manager, so it never crashes when settings are missing.
- **`CheckMaintenanceMode`** middleware, applied to the **frontend route group only**
  (`routes/frontend.php`), so admin/Livewire/infra routes are never blocked. The
  whole check is wrapped in try/catch — maintenance mode can never take the site down.
- **Settings keys** (`maintenance.*`, stored in `cms_settings`, autoloaded, kept
  private): `enabled`, `mode` (`theme`|`page`), `page_id`, `title`, `message`,
  `status_code` (`503`|`200`), `retry_after_minutes`, `allow_admin_bypass`,
  `allow_logged_in_bypass`, `allowed_ips`, `exclude_paths`. Seeded by
  `CmsSettingsSeeder` (idempotent).
- **Settings UI** — a **Maintenance** tab on the existing Settings page (no new
  page/panel). Gated by `system.maintenance.manage` (additional to `settings.manage`);
  the tab and its keys are only loaded/saved when the user may manage them.
- **Theme maintenance view support** — `theme::maintenance`
  (`themes/{slug}/views/maintenance.blade.php`); the default theme ships one.
- **Custom page maintenance mode** — render a chosen published CMS page through the
  active theme as the maintenance page (no infinite loop: the gate is not re-run).
- **Core fallback view** `cms.maintenance` (`resources/views/cms/maintenance.blade.php`)
  and a last-resort inline document when no theme is active.
- **Retry-After support** — a `Retry-After` header (minutes → seconds) on 503
  responses when configured, plus an `X-Robots-Tag: noindex, nofollow` header (and a
  `<meta robots>` in the views) so the maintenance page is never indexed.
- **Permissions:** `system.maintenance.manage` and `system.maintenance.bypass` added
  to the registry. The seeder grants both to `super-admin` and `admin`; `editor` /
  `author` get neither. Re-running `db:seed` adds them to existing roles idempotently.
- **Health flags:** `maintenance_ready`, `maintenance_enabled`, `maintenance_mode`
  added to `/cms-health` (allowed IPs are never exposed).

### Bypass rules

- Excluded paths always pass (prefix match for bare segments, exact match for
  filename entries like `robots.txt`).
- `allow_admin_bypass` + `system.maintenance.bypass` (super admins included) → bypass.
- `allow_logged_in_bypass` + any authenticated user → bypass.
- An `allowed_ips` match → bypass.

### Not implemented

- GitHub/URL installer, marketplace, extension file editor, audit logs, 2FA.

### Roadmap

- v1.0.0-beta.4 — **done.** Next planned: **v1.0.0-beta.5 — GitHub / URL Extension
  Installer**.

---

## [1.0.0-beta.3] — Users / Roles / Permissions Core

### Added

- **RBAC database tables:** `cms_roles`, `cms_permissions`, `cms_role_permissions`,
  `cms_role_user` (core package migrations). The host `users` table is unchanged.
- **Models:** `TheNguyen\CMS\Models\Role`, `TheNguyen\CMS\Models\Permission`.
- **`HasCmsRoles` trait** applied to `App\Models\User` — `roles()`, `hasRole()`,
  `hasAnyRole()`, `hasPermission()`, `isSuperAdmin()`.
- **`PermissionManager`** service (`cms.permission`), **`Permission`** facade, and
  the **`cms_can()`** helper. Holds the permission registry, mirrors it to the DB
  (`syncDefaults()`), and resolves authorization (super-admin bypass, configurable
  super-admin emails, and fail-open until the system is seeded).
- **Core permission registry** — 38 permissions across Dashboard, Content, Pages,
  Posts, Taxonomy, Media, Menus, Settings, Themes, Plugins, Users, and System.
  Plugins may register their own permissions at runtime.
- **`CmsRolePermissionSeeder`** — idempotent; seeds the `super-admin`, `admin`,
  `editor`, and `author` roles and assigns `super-admin` to the first user on a
  fresh install.
- **Users admin resource** (`/admin/users`) and **Roles admin resource**
  (`/admin/roles`) under a new **Users** navigation group.
- **Permission guards** on sensitive pages/actions: Settings, Themes (view /
  activate / delete / install), Theme Options, Plugins (view / activate / delete /
  install), and the Pages/Posts/Media/Menus/Taxonomy/Categories/Tags/Languages
  resources.
- **Panel-access gating:** `App\Models\User` now implements `FilamentUser`;
  admin access requires the `admin.access` permission (super admins bypass). This
  also makes the panel reachable in non-local environments.
- **Health flags:** `roles_permissions_ready`, `roles_count`, `permissions_count`,
  `super_admins_count` added to `/cms-health`.
- **Config:** `cms.super_admin_emails` (env `CMS_SUPER_ADMIN_EMAILS`).

### Safety

- The first user / super admin can never be locked out (fail-open before seeding,
  super-admin role bypass, and the configurable super-admin email fallback).
- You cannot delete yourself, cannot delete or unassign the last super admin, and
  system roles cannot be deleted. The super-admin role always keeps all permissions.

### Not implemented

- Audit logs, two-factor auth, user impersonation, teams/multi-tenancy,
  GitHub/URL installer, remote marketplace, CLI installer, license manager, and
  the extension file editor remain out of scope.

### Notes

- Authors are not yet scoped to their own posts (no content ownership column yet),
  so the `author` role can create/edit posts broadly but cannot publish or delete.
- `CmsInfo::VERSION` → `1.0.0-beta.3`.

---

## [1.0.0-beta.2] — Theme/Plugin ZIP Installer

Released: **2026-06-06**

Safe **local ZIP upload** installation for themes and plugins. Everything is
validated in a throwaway temp directory before anything touches `themes/` or
`plugins/`; the archive is never trusted. **No DB migration, no new table, no new
panel.** Installed extensions are **inactive** — the installer never boots,
activates, or executes extension code.

### Added

- **`ExtensionInstaller` service** (`cms.extension_installer`, facade
  `ExtensionInstaller`, helper `extension_installer()`) in
  `packages/thenguyen/cms-core`. API:
  `installPluginFromZip(string $zipPath, bool $overwrite = false): InstallResult`,
  `installThemeFromZip(...): InstallResult`, `isReady(): bool`.
- **`InstallResult` value object** (`success`, `message`, `type`, `slug`,
  `installedPath`, `errors[]`, `warnings[]`). The installer never throws to the
  UI — every outcome is a controlled `InstallResult`.
- **Safe ZIP handling**: rejects non-`.zip`, unreadable, or unopenable archives;
  rejects **path traversal** (`..`), **absolute**/**drive-letter** paths, empty
  entry names, **symlinks** (post-extraction guard), and overly large/too-many-
  entry archives. Extraction always happens in
  `storage/app/tncms-installer/{random}` and the temp dir is **always** cleaned up.
- **Manifest validation** before install: requires `name`, `slug`, `version`,
  `author`; slug must be lowercase slug-like and not reserved (`admin`, `themes`,
  `plugins`, `vendor`, `storage`, `public`, `core`, `app`, `routes`, `config`).
  Supports both ZIP layouts (a wrapping root folder **or** files at the root);
  **multiple manifests → rejected as ambiguous**.
- **Theme structural check**: requires `views/layouts/master.blade.php` unless a
  valid `default` theme exists to inherit from. Plugin providers are **not**
  executed or required at install (a declared provider with no `src/` is a
  non-fatal warning).
- **Overwrite policy**: default off → duplicate slug fails with a clear message.
  With overwrite on, only `themes/{slug}` / `plugins/{slug}` is replaced, and an
  **active** theme/plugin can never be overwritten ("Deactivate … first").
- **Install Plugin admin page** (`/admin/plugins/install`, `InstallPluginPage`) —
  now functional (ZIP upload + overwrite toggle + Install button).
- **Install Theme admin page** (`/admin/themes/install`, `InstallThemePage`,
  Appearance group) — new.
- **Safe Delete** for **inactive** extensions —
  `ExtensionInstaller::deletePlugin(string $slug)` /
  `deleteTheme(string $slug)` (both return `InstallResult`). The slug is
  validated strictly (lowercase slug-like; no `/`, `\`, or `..`), the path is
  resolved **internally** from the configured root, and a `realpath` containment
  check guarantees only `plugins/{slug}` / `themes/{slug}` can ever be removed.
  **Active extensions are refused** ("Deactivate the plugin before deleting it." /
  "Activate another theme before deleting this one."), and the **last remaining
  theme** can never be deleted. UI: a danger **Delete** action on **inactive**
  plugins (confirmation modal) and a **Delete** button on **inactive** theme
  cards (shown only when more than one valid theme exists). No raw exception ever
  reaches the UI.
- **Header actions**: "Install Plugin" on Installed Plugins, "Install Theme" on
  Themes.
- **`/cms-health`** adds `extension_installer_ready`, `plugin_installer_ready`,
  `theme_installer_ready`. Still never 500s.
- `CmsInfo::VERSION` → `1.0.0-beta.2`.

### Notes

- **Not implemented** (deliberately): remote-URL installer, CLI installer,
  marketplace, license manager, plugin/theme file editor, auto-update system,
  Composer package installer.
- **Roadmap:** v1.0.0-beta.2 done. Next planned: v1.0.0-beta.3 — Extension File
  Editor, or CLI/URL installer.

---

## [1.0.0-beta.1] — Plugin Manager UI

Released: **2026-06-06**

The first admin UI over the Extension Framework (shipped headless in 0.9.8): a
**Plugins** navigation group with a fully working **Installed Plugins** page plus
two clearly-labelled placeholders. **No DB migration, no new table** (the active
registry still reuses `cms_settings`), **no new panel**. This phase ships
management UI only — **no** ZIP installer, plugin file editor, marketplace,
remote/CLI installer, or license manager.

### Added

- **Plugins navigation group** (ordered after *Appearance* via the panel's
  `navigationGroups()`).
- **Installed Plugins page** (`/admin/plugins`,
  `App\Filament\Admin\Pages\InstalledPluginsPage`) — a card grid of valid plugins
  from `extension()->plugins()` showing name, description, slug, version, author,
  `requires.tncms`, provider list + count, and an Active/Inactive badge. Invalid
  plugin folders from `extension()->invalidPlugins()` are listed in a separate
  **Invalid plugins** section with their reason (no Activate button).
- **Activate / Deactivate actions** delegating to `extension()->activatePlugin()`
  / `deactivatePlugin()`, each wrapped in try/catch (a broken plugin never
  crashes the admin). Success/danger notifications; an active plugin with a
  missing provider class is flagged with a warning on its card. After a registry
  change the page best-effort runs `optimize:clear` (non-destructive) and shows a
  note that route changes may need `php artisan optimize:clear` or a reload.
- **Install Plugin placeholder** (`/admin/plugins/install`,
  `InstallPluginPage`) — "Coming in a future beta." + "ZIP installation will
  validate `plugin.json` before extracting."
- **Plugin Editor placeholder** (`/admin/plugins/editor`, `PluginEditorPage`) —
  "Coming in a future beta." + "Plugin file editing is security-sensitive and
  will be limited to safe file types."
- **`/cms-health`** adds `plugin_manager_ui_ready` (`InstalledPluginsPage` exists
  && Extension Framework ready); keeps `extension_framework_ready`,
  `plugins_count`, `active_plugins_count`, `invalid_plugins_count`. Still never
  500s.
- `CmsInfo::VERSION` → `1.0.0-beta.1`.

### Notes

- Plugin routes register at application boot from the active-plugin set, so
  activating/deactivating a plugin only changes `/{plugin-route}` resolution on
  the **next** request — hence the cache clear + note. No destructive commands
  are run from the admin.

### Not implemented (still planned)

- Plugin ZIP installer, theme ZIP installer, plugin file editor, theme file
  editor, marketplace, remote URL installer, CLI installer, license manager,
  widgets, page builder.

### Roadmap

- v1.0.0-beta.1 **done**.
- Next planned: **v1.0.0-beta.2 — Theme/Plugin ZIP Installer**.

---

## [0.9.9] — Theme Options Framework

Released: **2026-06-06**

A dynamic theme options engine: the active theme declares an option schema in
its `functions.php`, the core renders an admin form from that schema, stores the
values in `cms_settings`, and theme views read them via a helper. **No DB
migration, no new table** (values reuse `cms_settings`), **no new panel**. This
is a framework phase — **not** a live customizer, theme installer, theme editor,
widgets, page builder, plugin manager UI, or marketplace.

### Added

- **Safe `functions.php` loading.** `ThemeManager::themeConfig(?slug)` loads a
  theme's `functions.php` as a *config-returning* file (must `return` an array)
  in an isolated scope, memoised per request. A missing file, a non-array
  return, or a thrown exception is caught/logged and yields `[]` — it never
  crashes the admin or frontend.
- **Theme option schema.** `ThemeManager::themeOptionsSchema(?slug)` validates +
  normalises `options.sections` from `functions.php`; `hasThemeOptions(?slug)`
  reports whether any valid section exists. Required section keys: `key`,
  `label`, `fields`. Required field keys: `key`, `label`, `type`. Supported field
  types: `text`, `textarea`, `boolean`, `number`, `select`, `image`, `color`.
  Optional field keys: `default`, `helper`, `placeholder`, `options` (select),
  `min`/`max` (number). Slug-like keys are enforced; invalid sections/fields and
  unsupported types are skipped (logged); duplicate field keys keep the **first**
  definition (logged).
- **`ThemeOptionManager` (`cms.theme_option`)** — storage layer with the
  `ThemeOption` facade and `theme_option()` helper. API: `get($key, $default,
  $theme)`, `set($key, $value, $theme)`, `all($theme)`, `schema($theme)`,
  `hasOptions($theme)`. Values are stored in `cms_settings` under the full key
  `theme_options.{slug}.{option}` (group `theme_options`, key `{slug}.{option}`).
  Reads fall back to the schema `default`, then the caller default.
- **Appearance → Theme Options page** (`/admin/theme-options`) — renders a
  dynamic Filament form from the active theme's schema (sections → Filament
  Sections; field types → TextInput / Textarea / Toggle / numeric TextInput /
  Select / `MediaPicker` / `ColorPicker`), saves to `cms_settings`, and clears
  the settings cache. Friendly empty state when the active theme declares no
  options; a warning when there is no active theme. Never 500s.
- **Default theme options** (`themes/default/functions.php`): Site Identity
  (`logo` image, `show_tagline` boolean), Layout (`container_width` select),
  Colors (`primary_color` color), Footer (`footer_text` textarea). `theme.json`
  now declares `supports.theme_options: true`.
- **Default theme usage.** The header shows the `logo` when set (else the site
  name); the tagline shows only when `show_tagline` is true; the footer uses
  `footer_text` when set; the master layout emits `:root { --tncms-primary }`
  from `primary_color` (hex-validated), wired to the theme's `--color-accent`.
- **`/cms-health`** now reports `theme_options_ready`, `active_theme_has_options`,
  and `theme_options_count` (still never 500s).
- `CmsInfo::VERSION` → `0.9.9`.

### Notes

- **Storage format rationale.** `SettingsManager` splits a dotted key on its
  *first* dot only, so a true `theme_options.{slug}` group is not expressible via
  the dotted-key API. The full key `theme_options.{slug}.{option}` lands as group
  `theme_options`, key `{slug}.{option}` — unique per `(group, key)`, no new
  table.
- **Security.** `functions.php` is treated as config (no global side effects are
  executed beyond `return`). The `image` field stores a URL string via the
  existing `MediaPicker`; `primary_color` is hex-validated before being emitted
  into a `<style>` block (invalid values fall back to the default), preventing
  CSS/markup injection.

### Not implemented (still planned)

- Live customizer, theme installer / ZIP upload, theme editor, widgets, page
  builder, plugin manager UI, plugin installer, marketplace, repeater / nested
  option fields.

### Roadmap

- v0.9.9 **done**.
- Next planned: **v1.0.0 — Foundation Stable**.

---

## [0.9.8] — Extension Framework Core

Released: **2026-06-05**

Lays the foundation for plugins, theme extensions, the future Plugin Manager,
Theme Options framework, marketplace, and ZIP installer. **No DB migration, no
new table** (the active-plugin registry reuses `cms_settings`), **no new panel**.
This phase ships the framework only — **no** Plugin Manager UI, installer, ZIP
upload, Theme Options UI, plugin settings UI, widgets, or marketplace.

### Added

- **`ExtensionManager` (`cms.extension`)** — the orchestration layer over
  extensions, with the `Extension` facade and the `extension()` helper. Themes
  delegate to `ThemeManager` (`extension()->themes()` / `invalidThemes()`);
  plugins are discovered and managed here.
- **Plugin discovery + manifest validation.** Plugins live in `plugins/{slug}/`
  with a `plugin.json` (required `name`, `slug`, `version`, `author`; optional
  `description`, `providers`, `requires`). `extension()->plugins()` returns valid
  plugins; `extension()->invalidPlugins()` returns `[{slug, reason}]`. Invalid
  manifests are skipped and never crash the CMS. A `Support\Plugin` value object
  describes each plugin.
- **Active plugin registry.** Stored in `cms_settings` under
  `extensions.active_plugins` (JSON array of slugs). APIs:
  `isPluginActive($slug)`, `activatePlugin($slug)` (validates manifest;
  idempotent), `deactivatePlugin($slug)`, `activePlugins()`,
  `activePluginSlugs()`.
- **Safe plugin boot.** When active, a plugin's service providers (from
  `plugin.json` → `providers`) are registered, its `routes/web.php` is loaded in
  the `web` group **before** the frontend catch-all, its
  `resources/views` are registered under the `{slug}::` view namespace, and its
  `database/migrations` are made discoverable to `php artisan migrate`. Plugin
  classes autoload via a runtime PSR-4 mapping `Plugins\{StudlySlug}\` →
  `plugins/{slug}/src/` (no `composer dump-autoload` needed). **A broken plugin
  is caught, logged, and skipped** — the CMS keeps booting.
- **Hello World sample plugin** (`plugins/hello-world`) — proves discovery,
  activation, provider boot, route loading (`/hello-world`), and the
  `hello-world::` view namespace.
- **`PLUGIN_DEVELOPMENT.md`** — plugin developer guide (structure, `plugin.json`
  schema, namespace convention, providers, routes, views, migrations, activation
  lifecycle, version compatibility, best practices). Cross-linked from README /
  CMS_GUIDE / CMS_ARCHITECTURE.

### Changed

- `CmsServiceProvider` registers the `cms.extension` singleton, ensures the
  `plugins/` directory exists, and boots active plugins between the core web
  routes and the frontend catch-all.
- `/cms-health` adds `extension_framework_ready`, `plugins_count`,
  `active_plugins_count`, `invalid_plugins_count`. Never 500s.
- `CmsInfo::VERSION` → `0.9.8`.

### Notes

- The active-plugin list reuses `cms_settings` — **no migration** was added.
- **Not implemented (still deferred):** Plugin Manager UI, plugin installer /
  ZIP upload, Theme Options framework/UI, plugin settings UI, widgets,
  marketplace, page builder.

### Roadmap

- v0.9.8 — Extension Framework Core — **done.**
- Next planned: **v0.9.9 — Theme Options Framework.**

---

## [0.9.7] — Theme Manager Hardening + Brand Identity

Released: **2026-06-05**

Hardens the core theme system, standardizes the **TN CMS** brand identity, and
adds a dedicated theme developer guide. **No DB migration, no new table, no new
panel.** This is *not* Theme Options (planned v0.9.8), not a theme installer, and
not a theme editor.

### Added

- **Brand identity — TN CMS.** Canonical brand `TN CMS`, website
  <https://tncms.org>, support `support@tncms.org`, author **The Nguyen Media**.
  Exposed as `CmsInfo::BRAND` / `WEBSITE` / `SUPPORT_EMAIL` / `AUTHOR`. Config,
  seeders, SEO defaults, the clear-cache command, and theme/doc metadata now use
  TN CMS. **Existing runtime settings are not overwritten** (e.g. a customized
  `general.site_name`); only defaults/config/docs/theme metadata changed.
- **No-theme frontend state.** When no valid theme exists at all, the frontend
  returns a friendly **503** via `resources/views/errors/no-theme.blade.php`
  ("No active theme found. Please activate a theme in admin.") instead of a raw
  exception or silent mis-render.
- **Theme manifest validation.** `ThemeManager` only discovers a theme when its
  `theme.json` is valid JSON with non-empty `name`, `slug`, `version`, `author`.
  Invalid theme folders are skipped and surfaced on the admin Themes page under
  *Invalid themes* (`ThemeManager::invalidThemes()`).
- **`THEME_DEVELOPMENT.md`** — a theme developer guide (directory structure,
  `theme.json` schema, required/optional views, helpers, SEO/media/menu/language
  expectations, and what not to do). Cross-linked from README / CMS_GUIDE /
  CMS_ARCHITECTURE.
- **Theme value object** gained `authorUri` / `supportEmail` (parsed from
  `author_uri` / `support_email`).

### Changed

- **Switch-only activation (no Deactivate button).** The admin no longer offers
  a standalone Deactivate action — the active theme shows an *Active* badge only,
  and you change themes by clicking **Activate** on another theme (which
  *switches* the active theme). `ThemeManager::activate()` is now
  **transactional/safe**: it remembers the current active slug, validates the
  target manifest, verifies the required views resolve
  (`requiredViewsResolvable()` — `layouts/master`, `pages/page`, `posts/post`,
  `archives/index`, target → default fallback), publishes assets, then sets
  `theme.active`; **nothing mutates until the commit step**, so any failure
  leaves the previously active theme unchanged and the frontend keeps rendering
  with it. `canDeactivate()` now returns `false` (no UI deactivation);
  `deactivate()` is retained for programmatic callers only (switches to another
  valid theme or returns `false`; never sets `theme.active` to `null`).
- **Effective active theme.** `ThemeManager::active()` now returns an *effective*
  theme: the stored `theme.active` if it resolves, else `default`, else the first
  discovered theme; `null` only when no valid theme exists. New
  `activeSlug()` (raw stored value), `canDeactivate()`, and `themeSystemReady()`.
- **Default theme metadata** (`themes/default/theme.json`): author **The Nguyen
  Media**, `author_uri` <https://tncms.org>, `support_email`, `description`,
  `screenshot`, and `supports.theme_options: false` (Theme Options not yet).
- `/cms-health` adds `theme_system_ready`, `active_theme_required` (always
  `true`), `active_theme_effective`, and `invalid_themes_count`; `active_theme`
  now reports the **raw** stored slug (may be `null`). Never 500s.
- `CmsInfo::VERSION` → `0.9.7`.

### Notes

- The earlier `deactivate()` (which set `theme.active = null`) is replaced; the
  frontend's effective-active fallback means a previously-cleared `theme.active`
  still renders the default theme.
- **Not implemented (still deferred):** Theme Options UI, theme ZIP installer,
  theme editor, Widgets, Plugin Manager, Page Builder.

### Roadmap

- v0.9.7 — **done.**
- Next planned: **v0.9.8 — Theme Options Manager.**

---

## [0.9.6] — Settings Polish

Released: **2026-06-04**

Reworks **CMS Settings** into practical, WordPress-like sections and wires the
new keys through the frontend, SEO, media, and URL layers. Uses the existing
`cms_settings` table and `SettingsManager` — **no new settings table, no DB
migration.**

### Added

- **Tabbed Settings page** (`/admin/settings`): **General**, **Reading**,
  **Writing**, **Media**, **SEO**, **Permalinks**. Saves persist to
  `cms_settings`, clear the autoload cache, and notify on success.
- **General / Site Identity:** `general.site_name`, `general.site_tagline`,
  `general.site_description`, `general.admin_email`, `general.timezone`,
  `general.date_format`, `general.time_format`, `general.favicon` (image
  picker). The default theme renders `<link rel="icon">` when a favicon is set
  and shows the tagline under the site brand.
- **Reading:** `reading.homepage_display` (`latest_posts` | `static_page`),
  `reading.homepage_page_id`, `reading.posts_page_id`,
  `reading.posts_per_page`, `reading.feed_items_count`,
  `reading.feed_content_mode`, plus the **search-engine visibility** toggle
  (`seo.noindex_site`). The frontend homepage honours the display mode.
- **Writing:** `writing.default_post_status`, `writing.default_comment_status`,
  `writing.default_category_id`, `writing.default_language`,
  `writing.default_post_format`. New posts pre-fill status / comment status /
  default category; new translations default to `writing.default_language`.
- **Media:** `media.organize_uploads_by_date`, `media.max_upload_size_mb`,
  `media.auto_alt_from_filename`, `media.auto_title_from_filename`, the
  thumbnail/medium/large size fields, and `media.custom_sizes` (repeater).
  `MediaManager::upload()` now honours date-folder organisation and the
  alt/title-from-filename toggles; admin upload fields respect the max size.
- **SEO defaults:** `seo.noindex_site`, `seo.title_separator`,
  `seo.default_meta_title`, `seo.default_meta_description`,
  `seo.default_og_image`, `seo.robots_default`. `SeoManager` resolves
  title/description/OG-image fallbacks from these and returns
  `noindex,nofollow` (and `robots.txt` `Disallow: /`) when the site is
  discouraged.
- **Permalinks:** `permalink.post_base` (default `blog`),
  `permalink.category_base` (default `category`), `permalink.tag_base`
  (default `tag`). A new core **`PermalinkManager`** (`cms.permalink`) +
  `Permalink` facade drives the bases through `content_url()` / `term_url()` /
  `language_switcher()`, `ContentManager` / `TaxonomyManager` slug prefixes,
  the frontend routes, the SEO `hreflang` alternates, and the sitemap.
- **Base-less permalinks (WordPress-style).** Any base may be left **empty**:
  the record then resolves through the generic `/{slug}` (or `/{locale}/{slug}`)
  route. `FrontendController@resolveSlug` looks the path up in `cms_slugs` (the
  public-slug source of truth) and dispatches to a page, post, or term archive.
  The dedicated `/{base}/{slug}` route is registered **only** when the base is
  set, so a non-empty base never exposes a duplicate bare-slug URL.
- **Global per-locale public slug uniqueness.** Within a locale a public slug is
  now unique across **pages, posts, categories and tags** (so `/{slug}` always
  resolves unambiguously). New `SlugManager::uniquePublicSlug()` enforces it
  against `cms_slugs`, auto-incrementing conflicts (`slug-2`, `slug-3`, …) and
  refusing reserved prefixes; `ContentManager` / `TaxonomyManager` route their
  saves through it. New `SlugManager::findPublic()` (path → `cms_slugs` row) and
  `SlugManager::rebuildPublicSlugs()` (recompute every prefix/full_path) support
  the resolver and base changes.

### Changed

- `SeoManager::robots()` / `robotsTxt()` honour `seo.noindex_site`; title and
  description fall back through `seo.default_meta_title` /
  `seo.default_meta_description`; `ogImage()` falls back to
  `seo.default_og_image`.
- Frontend routes register their post/category/tag segments from the configured
  permalink bases; an **empty** base registers no dedicated route and falls
  through to the generic `/{slug}` resolver. **Changing a base requires
  `php artisan route:clear`** to take effect (and `route:cache` is incompatible
  with later base changes until cleared).
- Saving the Settings → Permalinks tab now **rebuilds `cms_slugs`** when a base
  changed, so existing content/terms resolve at the new URLs without re-saving
  each record.
- The catch-all `/{slug}` route now dispatches via `cms_slugs`
  (`@resolveSlug`, names `cms.resolve` / `cms.resolve.localized`) instead of
  always rendering a page; pages still resolve, and base-less posts/terms now
  resolve too. `vendor` was added to the catch-all's reserved-prefix guard.
- `CmsInfo::VERSION` → `0.9.6`. `/cms-health` adds `settings_polish_ready` and
  `settings_sections` (general/reading/writing/media/seo/permalinks).

### Notes

- **Empty permalink bases** are fully supported: the record resolves at
  `/{slug}` (or `/{locale}/{slug}`) via `cms_slugs`. This relies on global
  per-locale slug uniqueness across pages/posts/categories/tags — conflicting
  slugs auto-increment on save. Changing a base rebuilds `cms_slugs` on save and
  needs `php artisan route:clear` (route registration reads the bases).
- Permalink bases are validated: lowercase slug characters, no slashes, not a
  reserved prefix (`admin`, `cms-health`, `livewire`, `filament`, `storage`,
  `uploads`, `themes`, `vendor`, `robots.txt`, `sitemap.xml`, `up`), unique
  among the three when non-empty.
- **Not implemented** (explicitly deferred): resized image generation,
  WebP/AVIF conversion, Theme Options, Widgets, Plugin Manager.
- `reading.homepage_display` is intentionally **not seeded**, so the default
  homepage keeps its existing behaviour (the seeded `trang-chu` page) until an
  admin opts into `latest_posts` / `static_page`.

---

## [0.9.5] — Media UX Polish

Released: **2026-06-03**

A UX/stability patch for the Media Library and media picking. **No DB
migration, no new table** — featured images are still the media URL string in
`cms_contents.featured_image`.

### Added

- **Shared media support helpers.** `App\Filament\Admin\Support\MediaItems`
  grew `imageQuery()`, `normalize(Media): array`, `findByUrl(string): ?array`
  and `searchImages(string, int): array` alongside the existing
  `imageItems()`. One query + one normalisation shape now feeds the Rich
  Editor modal, the Featured Image modal (`MediaLibrarySelect`), and the
  Featured Image preview (`MediaPicker`).
- **Bulk metadata actions** on the Media Library table (`/admin/media`):
  - **Generate alt from filename** — fills Alt **only where it is empty**
    (existing alt is never overwritten), reports how many items changed.
  - **Clear metadata** — sets alt/title/caption/description to `null` for the
    selected media (confirmation required; files untouched).
- **Media edit page actions** (`/admin/media/{id}/edit` header):
  **Generate alt from filename** (when alt is empty) and **Clear metadata**
  (confirmation required), both refreshing the form in place.
- **Featured Image upload note.** The Upload tab shows a clear note —
  *"Uploaded image will be used as the featured image."* — and the file field
  renders a preview before submit.

### Changed

- **`MediaPicker` preview** resolves its metadata through
  `MediaItems::findByUrl()` instead of a local query, so the preview matches
  the modal grid/details exactly.
- **Rich Editor "Insert Media" search** now also matches **caption** and
  **description** (was filename/alt/title only), matching the Featured Image
  modal's search. Placeholder text updated to match.
- **`/cms-health`** adds **`media_ux_ready`** = `media_metadata_ready &&
  featured_image_modal_ready && MediaItems` helper present (never 500s).

### Notes

- Media table search (original_filename/filename/alt/title/caption/description)
  and filters (Images / Documents / Missing alt / Missing title / Missing
  caption) were already present from 0.9.3; per-column sorting (Filename,
  Size, Uploaded) covers the Newest/Oldest/A–Z/Z–A/largest/smallest cases, so
  no custom sort UI was added.
- **Not implemented** (out of scope, unchanged): gallery, multi-select insert,
  image replacement, crop/editor, folder manager, WebP/AVIF conversion,
  S3/CDN.

---

## [0.9.4] — Featured Image Media Modal

Released: **2026-06-02**

A UX/authoring patch: the featured-image **dropdown** on Pages and Posts is
replaced with a WordPress-like **media picker** — browse thumbnails, search,
upload (drag & drop), and set/replace/remove — all without leaving the edit
form. **No DB migration, no new table** — the value is still the media URL
string in `cms_contents.featured_image`.

### Fixed (post-release patch)

- **Featured Image media modal showed no images.** The `MediaLibrarySelect`
  field defined its Alpine init function and styles in an `@assets`/`@once`
  block. That only works for components present on initial page load (like the
  Rich Editor): `@filamentScripts`/`@filamentStyles` render once, so a field
  that first appears inside a **lazily-mounted action modal** never received
  its `<script>`, leaving `x-data="cmsFeaturedImageLibrary(...)"` bound to an
  undefined function — the grid never initialised. (The data itself was always
  correct.) Fixed by making the field self-contained: the Alpine component is
  now defined **inline** in `x-data` and the styles are inlined in the view,
  so both work when the modal is morphed in by Livewire.
- Extracted a shared `App\Filament\Admin\Support\MediaItems::imageItems()`
  helper and used it in both `RichEditor::getMediaItems()` and
  `MediaLibrarySelect`, so the Featured Image modal and the Rich Editor "Insert
  Media" modal now show **identical** image data from one query.

### Added / Changed

- **`MediaPicker` refactored** into a reusable featured-image picker
  (`MediaPicker::make('featured_image', 'Featured image')` → a Filament
  `Group`). Reusable on any form (Page, Post, future Theme Options / Page
  Builder). Replaces the old searchable `Select`.
- **Empty state:** a clear "No image selected" block with an **Add image**
  button and the helper *"Select or upload an image for this content."*
- **Selected state:** image preview plus the metadata resolvable from
  `cms_media` (filename, alt, dimensions) and the URL, with **Replace image**
  and **Remove image** actions. A stored URL not found in `cms_media` still
  previews and is labelled *"External or missing media record."*
- **Media modal** (a native Filament action modal — escape/backdrop close,
  responsive, internal scroll, correct stacking) titled *"Select featured
  image"* with two tabs:
  - **Media Library** — a new `MediaLibrarySelect` field: thumbnail grid
    (newest first, max 50), client-side search over
    filename/alt/title/caption/description, and a details panel (large preview,
    filename, URL, dimensions, alt, title, caption, description). Selecting a
    thumbnail sets the chosen URL.
  - **Upload files** — a `FileUpload` (image-only: jpg/jpeg/png/gif/webp, 8 MB
    max, drag & drop) routed through the existing `MediaManager::upload()`
    (SEO filename + alt default from v0.9.3). The freshly uploaded image is used
    as the featured image on submit (it wins over a library selection).
  - Footer: **Cancel** / **Set featured image**.
- **`/cms-health`** — added `featured_image_modal_ready` (true when
  `media_metadata_ready` and the `MediaPicker`/`MediaLibrarySelect` components
  exist). Never 500s.
- `CmsInfo::VERSION` bumped to **0.9.4**.

### Security

- Only image URLs from `cms_media` (or the already-stored URL) are selected;
  uploads go exclusively through `MediaManager::upload()` (image MIME/type/size
  validated by the `FileUpload`), so the SEO filename, alt default, and
  dimension handling are preserved. No arbitrary HTML/JS is inserted; the
  content-body `HtmlSanitizer` pipeline is untouched. The preview escapes the
  URL/alt with `e()`.

### Not implemented (still deferred)

- Gallery, multi-select, image crop/editor, folder manager, S3/CDN, and a
  `featured_image_media_id` relation — plus all prior deferrals (Plugin
  Manager, Page Builder, Theme Options, Widgets, API layer).

---

## [0.9.3] — Media Metadata Manager

Released: **2026-06-02**

An SEO/UX patch for the Media Library: a new **description** field, an
improved media edit form and table (metadata columns, search, missing-metadata
filters), reusable SEO helpers on the `Media` model, and richer defaults in the
Rich Editor's Insert Media modal. One small **additive** migration; no other
schema changes.

### Added / Changed

- **`cms_media.description`** — new `text` nullable column (migration
  `2026_06_02_000001_add_description_to_cms_media_table`, guarded/idempotent).
  Added to `Media::$fillable`.
- **`Media` SEO helpers** (all null-safe, never 500):
  - `seoAlt(): string` — alt → title → humanized original filename → humanized
    stored filename.
  - `seoTitle(): ?string` — title → alt → null.
  - `seoCaption(): ?string` — caption or null.
  - `seoDescription(): ?string` — description → caption → null.
  - `humanizeFilename()` — strips the extension, turns `-`/`_`/`.` into spaces,
    collapses whitespace, upper-cases the first letter. Does **not** restore
    Vietnamese accents (slugged names stay ASCII, e.g. `nguoi-dep.jpg` →
    `Nguoi dep`).
- **Upload default metadata** — `MediaManager::upload()` now defaults `alt` to
  the humanized original filename when the uploader supplies none.
  User-provided `alt`/`title`/`caption`/`description` are never overwritten;
  `title`/`caption`/`description` stay empty unless provided. `description` is
  now accepted in the upload `$meta`.
- **Media edit form** — restructured into a main **Metadata** column (alt,
  title, caption, **description**, each with help text) plus a sidebar with the
  **preview**, a **copyable URL**, and read-only file info (filenames, type,
  size, dimensions). The file itself still cannot be replaced. `EditMedia`
  persists `description` alongside alt/title/caption.
- **Media table** — added Alt text, Title, and a Caption indicator column;
  global search now spans `original_filename`, `filename`, `alt`, `title`,
  `caption`, `description`; new filters **Missing alt text**, **Missing title**,
  **Missing caption** (alongside Images / Documents).
- **Rich Editor Insert Media modal** — `getMediaItems()` now returns `caption`,
  `description`, and resolved `seo_alt`/`seo_title`. The modal seeds the Alt
  field from `seoAlt()`, Title from `seoTitle()`, pre-fills the Caption input
  from the media caption, and shows the description in the details panel.
  User edits in the modal still win on insert.
- **Featured-image picker** (`MediaPicker`) — the preview image now uses the
  media's `seoAlt()` for its `alt` and shows the title when set (no redesign).
- **`/cms-health`** — added `media_metadata_ready` (true when `cms_media`
  exists and has the `description` column). Never 500s.
- `CmsInfo::VERSION` bumped to **0.9.3**.

### Security

- The sanitizer is **unchanged**. Inserted `<img>` / `<figure class="cms-image">`
  still round-trip through `HtmlSanitizer` on save (img `src/alt/title/width/
  height/loading` allow-listed; `on*`, `style`, `<script>`, unsafe `src`
  stripped). The copyable-URL control escapes the URL into a read-only input
  and copies via an element ref, so nothing is interpolated into JS.

### Not implemented (still deferred)

- Featured Image modal improvements (planned **v0.9.4**), gallery / multi-select,
  folder manager, image replacement, crop/editor, S3/CDN — and all prior
  deferrals (Plugin Manager, Page Builder, Theme Options, Widgets, API layer).

---

## [0.9.2] — Menu Item Translation Polish

Released: **2026-06-02**

A small patch that completes the known 0.9.1 limitation: menu **item** titles
were still edited only in the default locale. They now follow the Menu edit
locale (`?locale=<code>`), matching the rest of the translation UX. **No DB
migration, no new table, no schema change** — `cms_menu_item_translations`
already existed.

### Added / Changed

- **`MenuItemsRelationManager` is now locale-aware.** When editing a menu at
  `/admin/menus/{id}/edit?locale=en`, creating/editing a menu item edits the
  **`en`** item title; at `?locale=vi` it edits the **`vi`** title. The locale
  is read from the parent edit page's `?locale=` query param, validated against
  active languages via `LanguageManager`, and falls back to the default code.
- The relation manager renders **non-lazily** (`$isLazy = false`) and captures
  the locale into a persisted `editLocale` property on `mount()`, so it survives
  the relation manager's subsequent Livewire (create/edit/save) requests, which
  no longer carry the browser query string.
- **Item form:** the title field shows helper text *"Editing menu item title
  for: {language label}"*. Opening the edit modal fills the selected locale's
  title, or leaves it **empty** when that translation is missing so it can be
  created safely. Non-translatable fields (type, reference, url, target,
  parent, css_class, icon, sort_order, is_active) are unchanged.
- **Create/update** write the title to the selected locale's translation only,
  via `MenuManager::createItem` / `updateItem` (which already accept `locale`).
  Saving `en` never overwrites the `vi` title, and vice versa.
- **Table:** the Title column shows the selected locale's title, falling back to
  the default locale title flagged `(default)` when the selected one is missing.
  The URL column resolves slugs for the selected locale.
- **Parent item selector** labels use the selected locale title, falling back to
  the default locale title.
- **Frontend menu rendering** now respects the current request locale:
  `frontend_menu($location)` defaults its locale to `current_locale()` (was
  hard-coded `vi`). The Vietnamese site shows Vietnamese item titles; `/en`
  shows English titles where translations exist, falling back otherwise.
- `CmsInfo::VERSION` bumped to **0.9.2**.

### Notes / Known limitations

- Switching language still **discards unsaved edits** in the open form
  (intentional, prevents cross-locale contamination) — save before switching.
- The linked-content picker (`reference_id` options for page/post/category/tag)
  still lists default-locale labels; this only affects how you *pick* the target,
  not the stored menu item title.

### Not implemented (still deferred)

- AI / auto translation, translation memory/workflow, full admin-UI
  translation — and all prior deferrals (Plugin Manager, Page Builder, Theme
  Options, Widgets, per-domain routing, new tables, new Filament panel).

---

## [0.9.1] — Live Translation Locale Switcher

Released: **2026-06-01**

A small UX patch fixing a dangerous edge in the 0.9.0 translation editor:
switching the **Language** select on an edit form did not load that locale's
translation, so editors could accidentally overwrite the wrong locale. **No
DB migration, no new table, no schema change.**

### Added / Changed

- The admin **Language** selector now **live-loads** the selected locale's
  translation on edit forms. Changing the language redirects to the same edit
  page with `?locale=<code>` and re-mounts the form, so the rich editor
  (TinyMCE, inside `wire:ignore`) re-initialises with the correct content — a
  plain `$set()` could not refresh it reliably.
- **Missing translation → cleared fields.** When the selected locale has no
  translation yet, the translatable fields load empty so a new translation
  can be created without inheriting another locale's content.
- **Non-translatable fields are preserved** (status, published_at,
  featured_image, categories, tags, menu location/status/sort_order, etc.).
- After saving, the editor stays on the same locale (redirect keeps the
  `?locale=` param), so the just-saved translation is shown.
- Applied to **Page, Post, Category, Tag, and Menu** edit forms via two shared
  concerns: `HasLocaleSelect` (the configured select) and
  `EditsTranslationLocale` (locale resolution + redirect). Helper text updated
  to: *"Changing language loads that translation. If it does not exist yet,
  the translation fields will be empty."*
- `CmsInfo::VERSION` bumped to **0.9.1**.

### Notes / Known limitations

- Switching language **discards unsaved edits** in the current form (the
  re-mount is intentional and prevents cross-locale contamination) — save
  before switching.
- `MenuItemsRelationManager` item titles are still edited in the default
  locale (unchanged from 0.9.0).

### Not implemented (still deferred)

- AI / auto translation, translation memory/workflow, full admin-UI
  translation — and all prior deferrals (Plugin Manager, Widgets, Theme
  Options, Page Builder, API Layer).

### Roadmap

- **v0.9.1 — Done.** Next planned: **v1.0.0 — Plugin / Module Manager** (or
  further language polish: translation status indicators, per-locale menu
  items).

---

## [0.9.0] — Language Manager Core

Released: **2026-06-01**

Makes the CMS multi-language aware at the core / admin / frontend level. The
schema was already translation-ready (`*_translations` tables); this phase
adds the language registry, the default/current locale resolution, optional
locale-prefixed URLs, an admin Languages resource + per-translation locale
selectors, a frontend language switcher, hreflang tags, and localized
sitemap entries. **No new Filament panel; no change to the existing
translation schema.**

### Added

- **`cms_languages` table** (migration `2026_06_01_000001`): `code` (unique),
  `locale`, `name`, `native_name`, `flag`, `direction` (ltr/rtl),
  `is_default`, `is_active`, `sort_order`.
- **`Language` model** (`TheNguyen\CMS\Models\Language`) — `active()`,
  `default()`, `ordered()` scopes; `label()` / `displayName()` helpers.
- **`LanguageManager` service** bound as `cms.language` (+ `Language` facade):
  `all()`, `active()`, `default()`, `defaultCode()`, `current()`,
  `currentCode()`, `find()`, `setCurrent()`, `setDefault()`, `create()`,
  `update()`, `delete()`, `isActive()`, `normalizeCode()`,
  `shouldPrefixDefaultLocale()`, `localizedUrl()`, `getPublicLocales()`,
  plus `optionList()` and static `routeLocalePattern()`. Enforces the
  single-default rule and keeps the default language active; defensive before
  the table exists (falls back to `vi`).
- **Helpers:** `language()`, `current_locale()`, `localized_url()`,
  `language_switcher()`. `content_url()` / `term_url()` are now locale-aware
  (`?string $locale = null`, default = current) and return `'#'` when the
  requested locale has no translation. New `Content::localeSlug()` /
  `Term::localeSlug()` (exact-locale slug, no fallback).
- **`CmsLanguageSeeder`** — idempotently seeds `vi` (default) + `en` and the
  `language.default` / `language.prefix_default` settings.
- **Admin → CMS → Languages** (`LanguageResource`): list/create/edit/delete
  with rules — cannot delete or deactivate the default; setting a new default
  unsets the others; unique `code`.
- **Locale selector** on Page / Post / Category / Tag / Menu forms (replaces
  the hidden `vi` locale). Editing loads the default-language translation;
  switching the selector + saving creates/updates that locale's translation.
- **Optional locale-prefixed frontend routes**: `/{locale}`,
  `/{locale}/blog/{slug}`, `/{locale}/category/{slug}`,
  `/{locale}/tag/{slug}`, `/{locale}/{slug}` — `{locale}` constrained to
  **active** codes only. The default language still works unprefixed; the
  catch-all stays last and keeps its reserved-prefix guard.
- **Theme language switcher** in the default theme header (shown when >1
  active language) + `.lang-switcher` styles; `<html lang dir>` now reflect
  the current language.
- **SEO**: hreflang `<link rel="alternate">` tags (incl. `x-default`) in the
  theme SEO partial; canonical uses the current localized URL; `sitemap.xml`
  now emits localized URLs for every published translation.
- `/cms-health` reports `languages_ready`, `languages_count`,
  `active_languages_count`, `default_language`, `current_language`.

### Changed

- `FrontendController` actions are locale-aware: they read `slug`/`locale`
  from the matched route **by name** (Laravel binds route params positionally,
  which is ambiguous when one action serves both prefixed and unprefixed
  routes), resolve + set the current locale, and find content/terms in that
  locale.
- `CmsInfo::VERSION` bumped to **0.9.0**.

### Notes / Known limitations

- **Translation editing is "simpler version"** (per the phase spec): the edit
  form loads the **default** language's translation; switching the locale
  selector does **not** live-reload that locale's existing values — switch
  then enter the translation and save. The default-language editing flow is
  unchanged.
- **No fallback rendering**: a missing translation 404s (no implicit
  fall-through to the default language).
- `MenuItemsRelationManager` still edits item titles in the default locale.

### Not implemented (still deferred)

- AI / auto translation, translation memory, translation workflow,
  import/export; per-domain language routing; complex redirect rules; full
  admin-UI translation — and all prior deferrals (Plugin Manager, Widgets,
  Theme Options, Page Builder, API Layer, Marketplace).

### Roadmap

- **v0.9.0 — Done.** Next planned: **v0.9.1 Language polish** (live locale
  reload in admin, translation status indicators) **or v1.0.0 stable core**,
  depending on results.

---

## [0.8.2] — Editor Polish

Released: **2026-06-01**

A small patch focused on the rich-editor authoring experience. No new
table, no new Filament page/panel, no new route, no DB migration.

### Added

- **Media insert options** — the Insert Media modal's details panel now
  has **Alt text**, **Title**, an **Add caption** checkbox, and a
  **Caption** input (shown when the checkbox is ticked). Selecting an image
  seeds Alt from `media.alt`/filename and Title from `media.title`.
- **Optional figure/figcaption insert** — with **Add caption** unchecked
  the modal inserts a plain `<img …>` (unchanged behaviour); checked, it
  inserts `<figure class="cms-image"><img …><figcaption>…</figcaption></figure>`.
  `title` only when non-empty; `width`/`height` only when known; all
  attributes and caption text escaped.
- **TinyMCE paste cleanup** — `paste_as_text:false`, `paste_data_images:false`,
  `paste_merge_formats:true`, `smart_paste:true`, plus a `paste_postprocess`
  that strips inline `style` attributes (the legacy webkit paste options
  were removed in TinyMCE 6+). Pasting from Word/Google Docs no longer
  drags messy inline styles into the editor.
- **Link behaviour** — `link_default_target:'_self'`,
  `link_assume_external_targets:'https'`, and a `rel_list`
  (None / nofollow / sponsored / noopener noreferrer).
- **Editor content style** — `content_style` approximates the frontend
  (font size, h2/h3 spacing, `img` max-width, `figure.cms-image`,
  table borders, blockquote, pre/code), skin-aware for light/dark.
- **Default theme styles** for editor HTML in
  `themes/default/assets/css/app.css`: `.cms-image` + `figcaption`, plus
  `.entry-content` `img`/`table`/`blockquote`/`pre`/`code`. Republished to
  `public/themes/default`.
- `/cms-health` now reports `editor_polish_ready` (true when
  `rich_editor_ready` **and** `html_sanitizer_ready`; never 500s).

### Changed

- `CmsInfo::VERSION` bumped to **0.8.2**.

### Notes

- **Sanitizer unchanged.** `HtmlSanitizer` already allow-lists `figure`,
  `figcaption`, and the global `class` attribute, and still drops unsafe
  `img src` (`javascript:`), `on*` handlers, `style`, `<script>`, and
  `<iframe>`. Security test confirmed:
  `<figure class="cms-image"><img src="javascript:alert(1)" onerror="alert(1)"><figcaption>X</figcaption></figure>`
  → the unsafe `<img>` is removed and handlers stripped, the `<figure>`/
  `<figcaption>` kept. No weakening.
- **No build step** — plain CSS + inline `content_style`; TinyMCE remains
  self-hosted from `/vendor/tinymce` (no Tiny Cloud / API key).

### Not implemented (still deferred)

- Gallery, multi-select, in-editor upload, image crop/editor, S3/CDN,
  AI writer, SEO analyzer — and all prior deferrals (Language Manager,
  Plugin Manager, Widgets, Theme Options, Page Builder, API Layer).

### Roadmap

- **v0.8.2 — Done.** Next planned: **Language Manager (v0.9.0)**.

---

## [0.8.1] — Media Modal + TinyMCE Insert Media

Released: **2026-06-01**

A small patch on top of the Rich Editor: an **image-only media modal**
inside the editor so authors can pick an image from the Media Library and
insert it as an `<img>` — instead of copy/pasting a URL. No new table, no
new Filament page/panel, no new route.

### Added

- **Insert Media modal** inside `RichEditor`
  (`resources/views/filament/admin/components/rich-editor.blade.php`).
  A new TinyMCE **Insert Media** toolbar button opens an Alpine-driven
  modal (teleported to `<body>`) titled **Select Media** with a search
  box and a thumbnail grid (filename, dimensions, alt). Image-only.
- **Image source** — `RichEditor::getMediaItems()` queries `cms_media`
  (`mime_type LIKE 'image/%'`, newest first, max 50) returning
  `id, url, name, filename, alt, title, width, height`; table-guarded.
  The snapshot is embedded with `@js(...)`; **search is client-side**
  over `name/filename/alt/title`.
- **Insert** — selecting an image (Insert button or double-click) inserts
  into the active editor instance:
  `<img src="{url}" alt="{alt|name}" title="{title}" width="{w}" height="{h}" loading="lazy">`
  (`width`/`height` only when known; attributes escaped). Then it closes
  the modal, refocuses the editor, and syncs Livewire.
- **Empty state** — "No images found. Upload media first." with a link to
  `/admin/media/upload` (new tab).
- `/cms-health` now reports `media_modal_ready` (true when the rich editor
  is ready and `cms_media` exists; never 500s).

### Changed

- TinyMCE toolbar gains `insertmedia`; the existing **Insert media by URL**
  and **Media** (library link) buttons are kept. TinyMCE asset loading is
  unchanged (still self-hosted from `/vendor/tinymce`).
- `CmsInfo::VERSION` bumped to **0.8.1**.

### Notes

- **Security:** the modal only inserts `<img>` with a stored `cms_media`
  URL; content still passes through `ContentManager` → `HtmlSanitizer` on
  save (img `src/alt/title/width/height/loading` allow-listed). No bypass.
- **Multiple editors:** modal state is per Alpine instance and each TinyMCE
  targets its own textarea, so Insert hits the correct editor.

### Not implemented (still deferred)

- Galleries, multi-select, in-editor upload, image crop/editor, S3/CDN,
  AI writer, document picker — and all prior deferrals (Language Manager,
  Plugin Manager, Widgets, Theme Options, Page Builder, API Layer).

---

## [0.8.0] — Rich Editor + Media Insert

Released: **2026-06-01**

Replaces the plain-text page/post **content body** with a **TinyMCE** rich
editor and stores it as **sanitized HTML**. Adds a dependency-free
`HtmlSanitizer`, a reusable `RichEditor` Filament field, and a simple
URL-based media insert. No new Composer package.

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - Full media modal/grid picker, in-editor drag-drop upload, multi-select.
> - Image crop/editor, S3/CDN, AI writer, SEO analyzer.
> - **Plugin Manager**, **Language Manager**, **Widgets**,
>   **Theme Options**, **Page Builder**, **API Layer**.

### Added

#### HTML sanitizer (service + facade + helper)

- `TheNguyen\CMS\Services\HtmlSanitizer` registered as the `cms.html`
  singleton (and `HtmlSanitizer::class` alias). `sanitize(?string):
  string` — DOMDocument-based allow-list sanitizer (no new dependency).
  Allowed tags: `p, br, strong/b, em/i, u, s, h1–h6, ul/ol/li, a,
  blockquote, pre/code, table/thead/tbody/tr/th/td, img, figure/
  figcaption, hr, span, div`. Allowed attrs: global `class`; `a`:
  href/title/target/rel; `img`: src/alt/title/width/height/loading;
  `th/td`: colspan/rowspan. Drops `script`/`style`/`iframe`/… subtrees,
  unwraps unknown tags, strips `on*`/`style`, blocks
  `javascript:`/`vbscript:`/non-image `data:` URLs (incl. control-char
  obfuscation), and adds `rel="noopener noreferrer"` to external
  `target="_blank"` links.
- `TheNguyen\CMS\Facades\Html` facade (accessor `cms.html`).
- `cms_html(?string $html)` helper — sanitizes HTML for `{!! !!}` output;
  legacy tag-less plain text is escaped + line-broken (`nl2br(e())`).

#### Rich editor field

- `App\Filament\Admin\Components\RichEditor` — a Filament `Field`
  (`RichEditor::make('content')`) rendering **self-hosted TinyMCE** via
  `resources/views/filament/admin/components/rich-editor.blade.php`.
  Assets are served from `/vendor/tinymce` (no Tiny Cloud, no API key).
  Livewire-safe (`wire:ignore` + `$wire.get/set` deferred sync). Toolbar:
  headings, bold/italic/underline/strike, link, lists, table, image
  (URL), code view. Light/dark skin follows the admin theme. ~500px tall.

#### Media insert

- Two custom TinyMCE buttons: **Insert media by URL** (prompts for a URL
  → inserts `<img src="…" alt="" loading="lazy">`) and **Media** (opens
  `/admin/media` in a new tab). No modal grid / multi-select / drag-drop.

### Changed

- `PageResource` / `PostResource` content field is now
  `RichEditor::make('content')` instead of a `Textarea` (layout, SEO,
  Publish, MediaPicker, categories/tags unchanged).
- `ContentManager` now depends on `cms.html` and **sanitizes the content
  body on every write** (create/update). Only the body is sanitized.
- `theme::pages.page` / `theme::posts.post` render the body with
  `{!! cms_html($body) !!}` instead of `nl2br(e($body))`.
- `CmsInfo::VERSION` bumped to **0.8.0**.
- `/cms-health` now reports `rich_editor_ready` and `html_sanitizer_ready`
  (guarded — never a 500). `rich_editor_ready` also verifies
  `public/vendor/tinymce/tinymce.min.js` exists.

### Fixed

- **TinyMCE "valid API key required" warning.** Switched TinyMCE from the
  Tiny Cloud CDN to **self-hosted local assets** under
  `public/vendor/tinymce` (TinyMCE 8 community, GPL). The editor now
  initialises with `base_url: '/vendor/tinymce'`, `suffix: '.min'`, and
  `license_key: 'gpl'` — no request to `cdn.tiny.cloud`, no API key, and
  it works offline / on shared hosting. (Installed via
  `npm install tinymce --save-dev`, then runtime assets copied to
  `public/vendor/tinymce`.)

### Notes

- **Security:** stored content is sanitized at save time **and** re-checked
  at render via `cms_html()` (defense in depth). `<script>` and event
  handlers never reach the page.
- **Legacy content:** existing plain-text bodies are not migrated; they
  render via `cms_html()`’s plain-text path (escaped + line breaks).
- **TinyMCE assets:** self-hosted at `public/vendor/tinymce` (copied from
  the `tinymce` npm dev dependency). If the editor fails to load, confirm
  `/vendor/tinymce/tinymce.min.js` returns 200 and re-copy the assets.

---

## [0.7.5] — SEO Core

Released: **2026-06-01**

Adds a **lightweight SEO foundation**: a `SeoManager` that resolves
per-page meta from existing content/taxonomy fields, a theme SEO partial
that renders the tags, and `robots.txt` + `sitemap.xml` endpoints. No SEO
plugin, no analyzer, no AI, no structured data.

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - Advanced SEO plugin / analyzer / AI SEO, JSON-LD structured data.
> - Sitemap index / pagination, per-record robots overrides.
> - **Rich Editor**, **Language Manager**, **Plugin Manager**,
>   **Widgets**, **Theme Options**, **Page Builder**, **API Layer**.

### Added

#### SEO service + facade + helper

- `TheNguyen\CMS\Services\SeoManager` registered as the `cms.seo`
  singleton (and `SeoManager::class` alias). Context setters
  `forHome()`, `forContent(Content, locale)`, `forArchive(Term, type,
  locale)`; resolvers `title()`, `description()`, `keywords()`,
  `canonical()`, `robots()`, `ogTitle()`, `ogDescription()`, `ogType()`,
  `ogImage()`, `twitterCard()`, `twitterTitle()`, `twitterDescription()`,
  `twitterImage()`, `current()`; infra builders `robotsTxt()`,
  `sitemap()`, `sitemapXml()`.
- `TheNguyen\CMS\Facades\Seo` facade (accessor `cms.seo`).
- `seo()` global helper — returns the `SeoManager` for the current page
  context (e.g. `seo()->title()`, `seo()->current()`).

#### SEO resolution rules

- **Title:** `meta_title` → content title → site name (home uses
  `general.site_name`; archives use `Category: {name}` / `Tag: {name}`).
- **Description:** `meta_description` → `excerpt` → first 160 chars of
  content (home uses `general.site_description`; archives use the term
  description).
- **Keywords:** `meta_keywords` only.
- **Robots:** `settings('seo.robots')` → `index,follow`.
- **Fallbacks** when settings are missing: title `TheNguyen CMS`,
  description `TheNguyen CMS website`, robots `index,follow`.

#### Theme SEO partial + master layout

- `themes/default/views/partials/seo.blade.php` renders `<title>`,
  `<meta name="description|keywords|robots">`, `<link rel="canonical">`,
  Open Graph (`og:title|description|type|url|image`) and Twitter
  (`twitter:card|title|description|image`) tags from `seo()`.
- `themes/default/views/layouts/master.blade.php` now
  `@include('theme::partials.seo')` inside `<head>` and no longer emits
  its own `<title>`.

#### Frontend SEO context

- `FrontendController` sets the SEO context per route: `forHome()` on
  `/`; `forContent()` on pages (`og:type` website) and posts (`og:type`
  article, featured image as `og:image`); `forArchive()` on
  category/tag.

#### robots.txt + sitemap.xml

- `TheNguyen\CMS\Http\Controllers\SeoController` with `robots()`
  (text/plain) and `sitemap()` (application/xml). Routes
  `GET /robots.txt` (`cms.robots`) and `GET /sitemap.xml` (`cms.sitemap`)
  registered in `routes/web.php` **before** the frontend catch-all.
- `sitemap.xml` lists the homepage, published pages/posts, categories,
  and tags (W3C `lastmod`). No sitemap index yet.
- `robots.txt` emits `User-agent: * / Allow: /` and a `Sitemap:` line.

### Changed

- `CmsInfo::VERSION` bumped to **0.7.5**.
- `/cms-health` now reports `seo_ready`, `sitemap_ready`, and
  `robots_ready` (all guarded — never a 500).

### Removed

- The stock Laravel `public/robots.txt` static file, so the dynamic
  `/robots.txt` route is authoritative (the webserver otherwise serves
  the physical file before reaching the app).

### Fixed

- **Post edit-save 500** (`array_map(): Argument #2 ($array) must be of
  type array, string given`). `PostResource::mergeTermIds()` now
  normalizes `tag_names` and `category_ids` defensively — a value may
  arrive from Filament/Livewire as an array **or** a raw comma-separated
  string. New `normalizeTagNames()` / `normalizeCategoryIds()` helpers
  split strings, trim, and drop empties instead of crashing.
- **Category/Tag SEO fields not editable.** Added a collapsed **SEO**
  section (Meta title, Meta description) to `CategoryResource` and
  `TagResource`, wired through the existing `cms_term_translations`
  `meta_title` / `meta_description` columns (no schema change). `seo()`
  now prefers a term's `meta_title` / `meta_description` on
  category/tag archives, falling back to `Category: {name}` /
  `Tag: {name}` and the term description.

### Notes

- Only **published** content appears in the sitemap; the homepage is
  always included. The builder is table-guarded — it degrades to just the
  homepage before migrations run.
- The SEO partial owns `<title>`; child templates' `@section('title')`
  are now unused by the layout (left in place, harmless).

---

## [0.7.0] — Frontend Rendering Core

Released: **2026-05-31**

Makes the **public website render** using the active theme. Registers a
`theme::` Blade namespace for the active theme (with default-theme
fallback), adds frontend helpers, a `FrontendController`, public routes,
and wires the default theme's views to real data. Default locale: `vi`.

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - **Rich Editor**, **Theme Options**, **Widgets**, **Customizer**.
> - **Page Builder**, **Language Manager** / multi-language URLs.
> - **Plugin Manager**, advanced SEO, marketplace/installer.

### Added

#### Theme view namespace

- `ThemeManager::registerViews()` registers `View::addNamespace('theme', …)`
  pointing at `themes/{active}/views`, falling back to
  `themes/default/views`. Called from `CmsServiceProvider::boot()`,
  wrapped so a missing/broken theme never breaks boot. Views are now
  loadable as `theme::layouts.master`, `theme::partials.header`,
  `theme::partials.footer`, `theme::pages.page`, `theme::posts.post`,
  `theme::archives.index`.
- `ThemeManager::viewNamespacePaths()` exposes the resolved hint paths.

#### Helpers (`cms-core/src/helpers.php`)

- `theme_asset(string $path)` → `/themes/{active}/{path}`.
- `theme_view(string $view)` → `theme::{view}`.
- `frontend_menu(string $location)` → menu tree for a location (or `[]`).
- `content_url(Content, $locale='vi')` → `/{slug}` (page) or
  `/blog/{slug}` (post).
- `term_url(Term, $locale='vi')` → `/category/{slug}` or `/tag/{slug}`.
- Existing helpers (`settings()`, `cms_slug()`, `menu()`, `theme()`)
  unchanged.

#### Frontend controller + routes

- `TheNguyen\CMS\Http\Controllers\FrontendController` with `home()`,
  `page($slug)`, `post($slug)`, `category($slug)`, `tag($slug)`. Only
  **published** content renders; missing records `abort(404)`; missing
  theme views degrade to a plain fallback response (never a 500).
- `cms-core/routes/frontend.php` (loaded after `web.php` in the provider,
  inside the `web` middleware group):
  - `GET /` → `home` (`cms.home`)
  - `GET /blog/{slug}` → `post` (`cms.post`)
  - `GET /category/{slug}` → `category` (`cms.category`)
  - `GET /tag/{slug}` → `tag` (`cms.tag`)
  - `GET /{slug}` → `page` (`cms.page`), **registered last** with a
    negative-lookahead constraint excluding `admin`, `cms-health`,
    `livewire`, `filament`, `storage`, `uploads`, `themes`, `up`.
- The app's default `routes/web.php` welcome `/` route was removed so the
  theme handles the homepage.

#### Default theme frontend views

- Rewrote `layouts/master`, `partials/header`, `partials/footer`,
  `pages/page`, `posts/post` and added `archives/index` to render real
  data: site name from `settings('general.site_name')`, header/footer
  menus via `frontend_menu()`, page/post title + featured image +
  content, post date/excerpt, and archive post lists linking to
  `/blog/{slug}`. Updated `assets/css/app.css` with a simple responsive
  baseline (no build step); republished to `public/themes/default`.

#### Health

- `/cms-health` now reports `active_theme_views_ready` and
  `frontend_ready` (both guarded — never a 500).

### Changed

- `CmsInfo::VERSION` bumped to **0.7.0**.

### Notes

- Page/post body is rendered with `nl2br(e($body))` — content is escaped
  then line-broken (no rich editor in this phase), so stored plain text
  renders safely.
- Theme views must reference siblings via the namespace
  (`@extends('theme::layouts.master')`, `@include('theme::partials.header')`)
  so they resolve through the active-then-default hint chain.

---

## [0.6.0] — Theme Manager Core

Released: **2026-05-31**

Introduces the **Theme Manager Core**: theme discovery from the
`themes/` directory, theme metadata, activation/deactivation, and asset
publishing into `public/themes/`. The active theme is stored in the
existing `cms_settings` table under `theme.active` — **no new table**.
Managed from a new **Appearance → Themes** admin page. Ships one default
starter theme.

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - **Frontend rendering** — themes are discovered/activated but no
>   public route renders pages/posts with a theme yet.
> - **Theme options** — `theme.json` may declare `theme_options: true`,
>   but there is no options editor.
> - **Widgets**, **Plugin Manager**, **Language Manager**, **Rich
>   Editor**, page builder, theme marketplace, ZIP installer.

### Added

#### Theme structure

- `themes/default/` — a default starter theme containing `theme.json`,
  `screenshot.png`, `functions.php` (returns `[]`), `assets/{css,js,images}`,
  and placeholder `views/{layouts,pages,posts,partials}` Blade files.
  The view/asset files are **placeholders only** — they are not wired to
  any frontend renderer in this phase.

#### Support object

- `TheNguyen\CMS\Support\Theme` — a plain (non-Eloquent) value object
  describing a discovered theme: `name`, `slug`, `version`, `author`,
  `description`, `path`, `screenshot`, `supports`, plus `toArray()`.

#### Service + facade + helper

- `TheNguyen\CMS\Services\ThemeManager` registered as the `cms.theme`
  singleton (and `ThemeManager::class` alias):
  `all()`, `active()`, `find($slug)`, `activate($slug)`, `deactivate()`,
  `themePath($slug)`, `themeAssetsPath($slug)`, `publishAssets($slug)`.
  Activation validates the theme exists, stores `theme.active` in
  settings, and copies `themes/{slug}/assets` → `public/themes/{slug}`
  (copy, **never symlink** — shared-hosting friendly).
- `TheNguyen\CMS\Facades\Theme` facade (accessor `cms.theme`).
- `theme()` global helper — `theme()` returns the manager;
  `theme('default')` returns the `Theme` object (or null);
  `theme()->active()` returns the active theme.

#### Admin UI

- **Appearance → Themes** (`ThemesPage`, a custom Filament page, icon
  `heroicon-o-paint-brush`, no Resource). Renders a card grid: screenshot,
  name, version, author, description, an **Active** badge, and
  **Activate** / **Deactivate** actions. Screenshots are embedded as
  inline data URIs (the `themes/` directory is not web-accessible).

#### Health

- `/cms-health` now reports `active_theme` (resolved from the
  ThemeManager) and `themes_count` (both guarded — never a 500).

### Changed

- `CmsInfo::VERSION` bumped to **0.6.0**.

### Notes

- The active theme lives in `cms_settings` under `theme.active`
  (`is_public`, `autoload`). `deactivate()` sets it to `null`, so
  `theme()->active()` then returns `null`.
- Theme screenshots are stored in the (non-public) theme directory and
  rendered admin-side as base64 data URIs; only `assets/` is copied to
  `public/` on activation.

---

## [0.5.0] — Menu Manager Core

Released: **2026-05-31**

Introduces the **Menu Manager Core** under a new **Appearance** admin
navigation group. Menus are treated as **core CMS data**, not theme data:
a future Theme Manager will declare menu *locations* (header, footer,
sidebar) and map them to menus created here. The Menu Manager is
multi-language-ready from the start (menu and item titles are
translatable, default locale `vi`).

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - **Theme Manager** — no theme system; locations are just string slots.
> - **Frontend rendering** — no public routes render these menus yet.
> - **Drag-and-drop JS menu builder** — items are managed via a simple
>   relation table with a form; ordering is a numeric `sort_order`.
> - **Widgets**, **Customize page**, **Plugin Manager**.

### Added

#### Database — 4 new tables

- **`cms_menus`** — `slug` (unique), `location` (nullable, indexed),
  `status` (`active`/`inactive`), `is_system`, `sort_order`, timestamps,
  soft deletes.
- **`cms_menu_translations`** — `menu_id`, `locale`, `name`,
  `description`. Unique `[menu_id, locale]`, FK cascade on menu delete.
- **`cms_menu_items`** — `menu_id`, `parent_id` (self FK, null on delete),
  `type` (`custom`/`page`/`post`/`category`/`tag`), `reference_type`
  (`content`/`term`/null), `reference_id`, `url`, `target`
  (`_self`/`_blank`), `css_class`, `icon`, `sort_order`, `is_active`,
  timestamps, soft deletes.
- **`cms_menu_item_translations`** — `menu_item_id`, `locale`, `title`.
  Unique `[menu_item_id, locale]`, FK cascade on item delete.

#### Models

- `Menu`, `MenuTranslation`, `MenuItem`, `MenuItemTranslation` in
  `TheNguyen\CMS\Models`. `Menu::displayName()`, `MenuItem::displayTitle()`
  and `MenuItem::resolvedUrl()` (resolves page/post/category/tag links
  from `cms_slugs.full_path`, falls back to the stored URL or `#`).

#### Service + facade

- `TheNguyen\CMS\Services\MenuManager` registered as the `cms.menu`
  singleton (and `MenuManager::class` alias):
  `createMenu`, `updateMenu`, `deleteMenu`, `createItem`, `updateItem`,
  `deleteItem`, `getMenuBySlug`, `getMenuByLocation`, `tree`,
  `reorderItems`. All writes run in DB transactions; menu slugs are
  generated via `SlugManager` and de-duplicated.
- `TheNguyen\CMS\Facades\Menu` facade (accessor `cms.menu`).
- `menu()` global helper — `menu()` returns the manager;
  `menu('header')` resolves by location first, then by slug.

#### Seeder

- `TheNguyen\CMS\Database\Seeders\CmsMenuSeeder` (idempotent): creates a
  **Header Menu** (`header-menu`/`header`) and **Footer Menu**
  (`footer-menu`/`footer`); attaches the sample page (*Trang chủ*) and
  sample post (*Bài viết đầu tiên*) to the header menu when present.

#### Admin UI

- **Appearance → Menus** (`MenuResource`, icon `heroicon-o-bars-3`).
  List shows name, slug, location, status, item count, updated-at, with
  search on name/slug/location. The edit page hosts a
  **MenuItemsRelationManager** for managing items (title, type,
  reference, url, target, parent, css class, icon, sort order, active).
  All item writes route through `MenuManager`.

#### Health

- `/cms-health` now reports `menus_count` and `menu_items_count`
  (both `null`, never a 500, if the tables are missing).

### Changed

- `CmsInfo::VERSION` bumped to **0.5.0**.

### Notes

- Menu item titles live in `cms_menu_item_translations`, not on the item
  row — the relation manager injects/reads `title` via the
  `MenuManager`, mirroring the Content/Term translation pattern.
- `reference_type` is derived from `type` by `MenuManager`
  (`page`/`post` → `content`, `category`/`tag` → `term`, `custom` →
  null); the admin form never sets it directly.

---

## [0.4.1] — Media Picker

Released: **2026-05-31**

Adds a small, reusable **Media Picker** field so editors can attach a
featured image to Pages and Posts by selecting from existing media,
instead of pasting a raw URL. This phase is intentionally narrow — it is
a searchable select plus a live preview and helper links, nothing more.

> **Explicitly NOT implemented in this phase** (still deferred):
>
> - **Editor integration** — the content editor still does not embed or
>   pick media.
> - **Media modal grid** — no visual grid/lightbox picker yet; a
>   searchable select is used.
> - **Multi-image / galleries**, image crop/editor.
> - **Theme Manager**, **Plugin Manager**, S3 / CDN disks.

### Added

- **Reusable media picker field** —
  `app/Filament/Admin/Components/MediaPicker.php`. A static
  `MediaPicker::make('featured_image', 'Featured image')` returns a
  Filament `Group` containing:
  - a searchable, preloaded `Select` listing **image media only**
    (`cms_media` where `mime_type LIKE 'image/%'`), labelled by
    `original_filename`, valued by media `url`;
  - server-side search across `original_filename`, `filename`, `alt`,
    and `title`;
  - a **live preview** of the selected image;
  - helper links that open **Media Library** (`/admin/media`) and
    **Upload Media** (`/admin/media/upload`) in a new tab.
- **Featured image picker for Pages** — `PageResource` Publish section
  now uses `MediaPicker::make()` instead of a raw URL `TextInput`.
- **Featured image picker for Posts** — `PostResource` Publish section
  now uses `MediaPicker::make()` instead of a raw URL `TextInput`.
- **Copyable URL column** added to the `MediaResource` table for quick
  copying of a media URL.

### Changed

- Featured image is still stored as a **URL string** in the existing
  `cms_contents.featured_image` column. No schema change, no migration.

### Notes

- **Clearing the selection** is supported. The picker dehydrates an empty
  selection to an empty string (`''`) rather than `null`, because
  `ContentManager::contentAttributes()` merges with
  `$data['featured_image'] ?? $current->featured_image`; a `null` would
  otherwise fall back to the previously saved URL. Emitting `''` lets a
  cleared image persist **without modifying `ContentManager`**.
- The picker is **app-level Filament UI** only. The Media Core
  (`packages/thenguyen/cms-core`) was not modified.
- **Validation:** the field is nullable and never required. With no media
  uploaded the select simply shows no options plus the helper text
  *"Upload media first, then return here and select it."* — it does not
  crash.

---

## [0.4.0] — Media Core

Released: **2026-05-29**

Introduces the **Media Core**: a minimal, maintainable foundation for
storing and managing uploaded files. This phase is deliberately small —
it provides storage, a model, a service, and a Filament admin surface
for uploading, browsing, previewing, editing metadata, copying URLs,
and deleting files. Nothing else.

> **Explicitly NOT implemented in this phase** (planned for later):
>
> - **Media Picker** — no field/component for attaching media to
>   Pages/Posts yet.
> - **Editor integration** — the content editor does not embed or pick
>   media.
> - **Theme Manager**, **Plugin Manager**.
> - S3 / CDN disks, image crop/editor, WebP/AVIF conversion, galleries.

### Added

#### Database — 2 new tables

Both migrations live in
`packages/thenguyen/cms-core/database/migrations/` and load
automatically:

- `2026_05_29_000001_create_cms_media_table` — `cms_media`: one row per
  uploaded file. Columns: `disk` (default `public`), `folder`,
  `filename`, `original_filename`, `extension`, `mime_type`, `size`,
  `width`, `height`, `path`, `url`, `alt`, `title`, `caption`,
  `uploaded_by`, timestamps, soft deletes. Indexed on `disk`, `folder`,
  `mime_type`, `uploaded_by`.
- `2026_05_29_000002_create_cms_mediables_table` — `cms_mediables`:
  the **future** polymorphic attachment bridge. Columns: `media_id`,
  `mediable_type`, `mediable_id`, `collection`, `sort_order`,
  timestamps. Indexed on each foreign column; unique on
  `(media_id, mediable_type, mediable_id, collection)`. **Structure
  only — not used yet.**

#### Model

- `TheNguyen\CMS\Models\Media` (`cms_media`, soft deletes). Casts
  `size`/`width`/`height` to integer. Helpers: `isImage()` (mime starts
  with `image/`), `humanSize()` (e.g. `1024 → "1 KB"`,
  `1048576 → "1 MB"`), `previewUrl()` (returns the stored `url`).

#### Service

- `TheNguyen\CMS\Services\MediaManager` — bound as the `cms.media`
  singleton with a `MediaManager::class` alias. Methods:
  - `upload(UploadedFile $file, array $meta = []): Media` — stores the
    file under `public/uploads/YYYY/MM` (e.g. `public/uploads/2026/05`),
    generates a collision-free filename while keeping the original name,
    extracts mime/extension/size, reads `width`/`height` for raster
    images via PHP's native `getimagesize()` (SVG skipped safely), and
    records the row.
  - `delete(Media $media): bool` — removes the physical file then soft
    deletes the record.
  - `find(int $id): ?Media`
  - `all(): Collection` — newest first.

#### Facade

- `TheNguyen\CMS\Facades\Media` (accessor `cms.media`).

#### Filament admin

- `App\Filament\Admin\Resources\MediaResource` — navigation group
  **Media**, label **Library**, slug `media` (`/admin/media`). Table
  columns: Preview (image thumbnail for images, document icon for
  non-images), Filename, Type, Size (`humanSize()`), Dimensions
  (`width × height`), Uploaded. Row actions: View, Edit, Delete. Bulk
  delete enabled (both row and bulk delete remove physical files via
  `MediaManager`). Filters: **Images** (`mime like image/%`) and
  **Documents** (everything else).
- `App\Filament\Admin\Pages\MediaUpload` — navigation group **Media**,
  label **Upload**, slug `media/upload` (`/admin/media/upload`). A
  `FileUpload` form (multiple, max 20 files) routes each file through
  `MediaManager::upload()` via `saveUploadedFileUsing()`. Accepted
  types: images, PDF, DOC, DOCX, ZIP, TXT. Success notification on save.
- Media **Edit** page persists only `alt`, `title`, and `caption`;
  the file itself cannot be replaced.

#### Health endpoint

- `/cms-health` now reports `media_count` and `image_count`. Both are
  `null` (no crash) when `cms_media` is missing.

### Notes

- Files are written directly to `public/uploads/YYYY/MM` (consistent
  with `config('cms.paths.uploads')` and the `public_root` deployment
  mode). The `disk` column stores the logical label `public`; no Laravel
  storage symlink is required.
- `cms_mediables` exists purely so the schema is stable for a future
  attachment/Media-Picker phase. Do not write to it yet.

---

## [0.3.0] — Content Core

Released: **2026-05-28**

The third tagged version. Introduces the unified content engine that
backs **Pages** and **Posts**, a taxonomy/term system, slug
normalization, per-locale translations, and a WordPress-like Filament
admin surface. Pages and Posts are first-class core features, not
plugins — they share one set of tables, one service, and one set of
slug rules.

### Added

#### Database — 7 new tables

All migrations live in
`packages/thenguyen/cms-core/database/migrations/` and load
automatically:

- `2026_05_28_000002_create_cms_contents_table` — `cms_contents`:
  unified row per page/post. Columns: `type`, `status`, `author_id`,
  `parent_id`, `template`, `featured_image`, `sort_order`,
  `comment_status`, `published_at`, soft deletes, timestamps. Indexed
  on `type`, `status`, `published_at`, `parent_id`, `author_id`,
  `sort_order`.
- `2026_05_28_000003_create_cms_content_translations_table` —
  `cms_content_translations`: per-locale title, slug, excerpt,
  content, `meta_*`. Foreign key to `cms_contents` (cascade delete).
  Unique on `(locale, slug)` and `(content_id, locale)`.
- `2026_05_28_000004_create_cms_taxonomies_table` — `cms_taxonomies`:
  `(type, content_type, slug, hierarchical, is_core, sort_order)`.
  Unique on `(content_type, slug)`.
- `2026_05_28_000005_create_cms_terms_table` — `cms_terms`: terms with
  optional hierarchy (`parent_id`), `sort_order`, `count`, soft
  deletes. Foreign keys cascade from taxonomy and null on parent
  delete.
- `2026_05_28_000006_create_cms_term_translations_table` —
  `cms_term_translations`: per-locale name/slug/description/meta.
  Unique on `(locale, slug)` and `(term_id, locale)`.
- `2026_05_28_000007_create_cms_content_terms_table` —
  many-to-many bridge with `(content_id, term_id)` unique.
- `2026_05_28_000008_create_cms_slugs_table` — `cms_slugs`: route
  lookup table `(reference_type, reference_id, locale, slug, prefix,
  full_path, is_primary)`. Unique on `(locale, full_path)`.

#### Models — 7 new Eloquent models

- `TheNguyen\CMS\Models\Content` — soft deletes, `translations()`,
  `translation(?string $locale)`, `terms()`, `author()`, `parent()`,
  `children()`. Scopes: `type()`, `pages()`, `posts()`,
  `published()`, `draft()`.
- `TheNguyen\CMS\Models\ContentTranslation` — `belongsTo` Content.
- `TheNguyen\CMS\Models\Taxonomy` — `terms()` relation; scopes
  `forContentType()`, `categories()`, `tags()`.
- `TheNguyen\CMS\Models\Term` — soft deletes; `taxonomy()`,
  `translations()`, `translation()`, `parent()`, `children()`,
  `contents()`.
- `TheNguyen\CMS\Models\TermTranslation` — `belongsTo` Term.
- `TheNguyen\CMS\Models\ContentTerm` — pivot model.
- `TheNguyen\CMS\Models\Slug` — route table model.

#### Services + facades + helper

- `TheNguyen\CMS\Services\SlugManager` bound at `cms.slug`. Methods:
  `generate(string $text, string $locale = 'vi')`,
  `uniqueContentSlug()`, `uniqueTermSlug()`, `makeFullPath()`.
  - Vietnamese: full diacritic + đ/Đ map, then `Str::slug`.
  - Latin: `Str::slug` defaults.
  - CJK / Arabic / Hebrew / Thai / Cyrillic / Greek / Devanagari:
    characters preserved, whitespace collapsed to `-`, unsafe URL
    punctuation stripped. Detection by locale tag prefix **or** by
    script regex in the text itself.
  - Empty slug fallback: `content-{timestamp}` / `term-{timestamp}`.
- `TheNguyen\CMS\Services\ContentManager` bound at `cms.content`.
  `create()`, `update()`, `delete()`, `findBySlug()`,
  `getTranslation()`. All writes happen inside a DB transaction.
- `TheNguyen\CMS\Services\TaxonomyManager` bound at `cms.taxonomy`.
  `ensureCoreTaxonomies()`, `createTerm()`, `updateTerm()`,
  `deleteTerm()`, `findTermBySlug()`. Table-aware: `ensureCore` is a
  no-op if the migration has not run yet, so package discovery never
  crashes.
- `TheNguyen\CMS\Facades\Slug`, `Content`, `Taxonomy` — facades for
  the three services.
- `cms_slug(string $text, string $locale = 'vi'): string` global
  helper added to `packages/thenguyen/cms-core/src/helpers.php`
  alongside the existing `settings()` helper.

#### Seeder

- `TheNguyen\CMS\Database\Seeders\CmsContentSeeder` — idempotent.
  Ensures core taxonomies, then seeds (only if absent): home page
  `Trang chủ` / `trang-chu`, sample post `Bài viết đầu tiên` /
  `bai-viet-dau-tien`, category `Tin tức` / `tin-tuc`, tag
  `Laravel` / `laravel`. Sample post is attached to both terms.

  Run with:
  ```bash
  php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"
  ```

#### Filament admin (under existing `admin` panel)

- `App\Filament\Admin\Resources\PageResource` — model `Content`,
  scoped to `type=page`. URL `/admin/pages`. Navigation group
  `Content`, label `Pages`, icon `heroicon-o-document-text`.
- `App\Filament\Admin\Resources\PostResource` — model `Content`,
  scoped to `type=post`. URL `/admin/posts`. Icon
  `heroicon-o-newspaper`. Includes excerpt field, categories/tags
  multi-select.
- `App\Filament\Admin\Resources\CategoryResource` — model `Term`,
  scoped to `post/category` taxonomy. URL `/admin/categories`.
  Nested under **Posts** via `$navigationParentItem = 'Posts'`.
- `App\Filament\Admin\Resources\TagResource` — model `Term`, scoped
  to `post/tag`. URL `/admin/tags`. Nested under Posts.
- `App\Filament\Admin\Resources\TaxonomyResource` — model
  `Taxonomy`. URL `/admin/taxonomies`. Nested under Posts. Delete
  action is disabled for `is_core` rows.
- Each Filament `ListRecords` page advertises a `CreateAction`
  labelled **"Add New Page / Post / Category / Tag / Taxonomy"** for
  a WordPress-like flow.
- Create / Edit pages route writes through `ContentManager` /
  `TaxonomyManager` (`handleRecordCreation` /
  `handleRecordUpdate`). Edit pages override
  `mutateFormDataBeforeFill` to hydrate the form with the matching
  translation row.

#### Health endpoint

`GET /cms-health` JSON now includes:

- `content_tables_ready: bool` — true only when all 7 content tables
  exist.
- `pages_count`, `posts_count`, `taxonomies_count`, `terms_count`
  (null if `content_tables_ready` is false).

The endpoint never 500s when migrations have not run.

### Changed

- `packages/thenguyen/cms-core/src/Providers/CmsServiceProvider.php`:
  registers three new singletons (`cms.slug`, `cms.content`,
  `cms.taxonomy`) with class aliases. `ContentManager` and
  `TaxonomyManager` receive `SlugManager` via constructor injection.
- `packages/thenguyen/cms-core/routes/web.php` — `/cms-health`
  extended with content-table readiness and counts; defensive against
  missing tables.
- `packages/thenguyen/cms-core/src/helpers.php` — added `cms_slug()`
  helper without disturbing `settings()`.
- `TheNguyen\CMS\Support\CmsInfo::VERSION` bumped to `0.3.0`.
- `CMS_STRUCTURE.md` updated with the Content Core section.
- `CMS_GUIDE.md` updated with content migrate / seed / slug /
  troubleshooting notes (see that file).

### Fixed

- Nothing CMS-shipped in 0.2.0 was broken; this release is additive.

### Notes

- **Multi-language posture.** The database, services, and admin
  services accept any locale today. The default is `vi`. There is
  intentionally **no** language switcher / locale registry yet — that
  is owned by the future Language plugin. Writes performed by the
  admin pass `locale = 'vi'` explicitly.
- **No new Filament panel.** All resources are auto-discovered by the
  existing `admin` panel (`discoverResources(in: app_path('Filament/Admin/Resources'), …)`).
  `Filament::getPanels()` still returns exactly `[admin]`.
- **WP-like sidebar.** Pages is a top-level nav entry. Posts is a
  top-level nav entry. Categories / Tags / Taxonomies appear as
  children of Posts via `$navigationParentItem = 'Posts'`. The "Add
  New" entry is implemented as a header `CreateAction` button on
  each list page rather than a duplicate nav item, which is more
  idiomatic for Filament v5.
- **`featured_image` is a text field.** The Media library lands in a
  later phase. For now the admin captures the URL as plain text.
- **`is_core` deletion guard.** `TaxonomyResource` disables the
  delete action for core taxonomies. The DB constraint
  `(content_type, slug)` unique stays in place even if the guard is
  bypassed at the service layer.
- **Slug uniqueness scope.** Uniqueness is per locale, per kind
  (content or term). The same `vi` slug can exist in both a content
  translation and a term translation; `cms_slugs` keeps them apart
  via `reference_type`.

### Verification commands (run at release)

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsContentSeeder"
php artisan route:list
php artisan about
curl -i http://laravel-cms.demo/cms-health        # 200, content_tables_ready: true
curl -i http://laravel-cms.demo/admin             # 302 -> /admin/login
curl -i http://laravel-cms.demo/admin/pages       # 302 when unauth
curl -i http://laravel-cms.demo/admin/posts       # 302 when unauth
curl -i http://laravel-cms.demo/admin/categories  # 302 when unauth
curl -i http://laravel-cms.demo/admin/tags        # 302 when unauth
curl -i http://laravel-cms.demo/admin/taxonomies  # 302 when unauth
```

Slug spot-checks in tinker:

```php
app('cms.slug')->generate('Thiết kế website chuẩn SEO', 'vi');
// "thiet-ke-website-chuan-seo"
app('cms.slug')->generate('Đặng Văn Lâm', 'vi');
// "dang-van-lam"
app('cms.slug')->generate('こんにちは 世界', 'ja');
// "こんにちは-世界"
```

---

## [0.2.0] — Settings Manager

Released: **2026-05-28**

The second tagged version. Introduces the first stateful CMS subsystem:
a DB-backed settings store with caching, a typed service, a facade, a
global helper, a Filament admin page, an Artisan cache command, and a
seeder of sensible defaults. The Settings Manager is the foundation
later phases (Theme Manager, Module Manager) will read configuration
from.

### Added

#### Database
- New migration
  `packages/thenguyen/cms-core/database/migrations/2026_05_28_000001_create_cms_settings_table.php`
  creating the `cms_settings` table with columns: `id`, `group`
  (nullable, indexed), `key` (indexed), `value` (longText, nullable),
  `type` (default `'string'`), `is_public` (default `false`),
  `autoload` (default `true`), `description` (text, nullable), and
  timestamps. Unique compound index `(group, key)`.
- The migration is auto-loaded by `CmsServiceProvider` via
  `loadMigrationsFrom(__DIR__.'/../../database/migrations')`. No copy
  into the application's `database/migrations` is required.

#### Model
- `TheNguyen\CMS\Models\Setting` Eloquent model bound to
  `cms_settings`. Fillable: `group, key, value, type, is_public,
  autoload, description`. Casts `is_public` and `autoload` to
  `boolean`. Adds `fullKey(): string` helper returning `group.key` (or
  `key` when group is null).

#### Service + facade + helper
- `TheNguyen\CMS\Services\SettingsManager` registered as a singleton
  under the container key `cms.settings`, with an alias to the class
  name. Public API: `get`, `set`, `has`, `forget`, `all`, `group`,
  `clearCache`, `refresh`, `isCached`. All public methods are safe to
  call before the `cms_settings` table exists (they short-circuit via
  `Schema::hasTable`).
- `TheNguyen\CMS\Facades\Settings` with accessor `cms.settings`.
- Global helper `settings(?string $key = null, mixed $default = null)`
  shipped in `packages/thenguyen/cms-core/src/helpers.php` and
  autoloaded via `composer.json` `autoload.files`.

#### Caching
- All `autoload = true` rows are loaded together under the cache key
  `cms.settings.autoload` using `Cache::rememberForever`. The cache is
  invalidated automatically on `set()` and `forget()`.

#### Artisan
- `cms:settings-clear` command
  (`TheNguyen\CMS\Console\Commands\ClearSettingsCacheCommand`) clears
  the settings cache and prints a confirmation. Registered in
  `CmsServiceProvider::registerCommands()` and only added when the app
  is running in console.

#### Seeder
- `TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder` seeds default
  values for `general.site_name`, `general.site_description`,
  `general.admin_email`, `seo.default_title`, `seo.default_description`,
  `admin.brand_name`, `system.deployment_mode`, `theme.active`. Skips
  rows that already exist. Run via:
  ```bash
  php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder"
  ```

#### Filament UI
- New Filament v5 page
  `App\Filament\Admin\Pages\SettingsPage` mounted at
  `/admin/settings`. Navigation group `CMS`, icon
  `heroicon-o-cog-6-tooth`. Sections: General, SEO, Admin, System
  (read-only).
- View `resources/views/filament/admin/pages/settings-page.blade.php`.
- The page is **auto-discovered** by the existing `admin` panel through
  `discoverPages(...)`. No new Filament panel was created and the
  existing `AdminPanelProvider` was not modified.

#### Health endpoint
- `GET /cms-health` JSON now includes `settings_cache_key`,
  `settings_cached`, `settings_loaded`, `site_name`,
  `admin_brand_name`. If the `cms_settings` table is missing the route
  still returns `200` with `settings_loaded: false` instead of 500.

#### Dashboard widget
- `App\Filament\Admin\Widgets\CmsInfoWidget` now exposes the
  settings-backed values: `siteName`, `adminBrandName`,
  `settingsAvailable`, `settingsCached`. The widget continues to render
  when the settings table does not exist.

### Changed

- `packages/thenguyen/cms-core/src/Providers/CmsServiceProvider.php`:
  - Registers `cms.settings` singleton + alias to
    `SettingsManager::class`.
  - Calls `loadMigrationsFrom()` for the package's migrations
    directory.
  - Registers `ClearSettingsCacheCommand` (console only).
- `packages/thenguyen/cms-core/routes/web.php`: `/cms-health` now
  reads from `SettingsManager` defensively.
- `app/Filament/Admin/Widgets/CmsInfoWidget.php` and its view: extended
  to show settings status alongside the existing CMS info.
- `composer.json` `autoload`:
  - Added `TheNguyen\\CMS\\Database\\Seeders\\` PSR-4 entry.
  - Added `files: ["packages/thenguyen/cms-core/src/helpers.php"]`.
- `TheNguyen\CMS\Support\CmsInfo::VERSION` bumped to `0.2.0`.

### Fixed

- Nothing CMS-shipped was broken in 0.1.0 that this release fixes.

### Notes

- **Boot order.** `loadMigrationsFrom()` runs in `boot()`, after the
  singleton is registered in `register()`. This is required so console
  commands (including `migrate`) can see both the migration files and
  the resolved `SettingsManager` instance.
- **`autoload` cache is forever-style.** Settings are not expected to
  change often; the cache is invalidated explicitly on writes. If a
  process bypasses the service (e.g. raw SQL updates), call
  `php artisan cms:settings-clear`.
- **Filament v5 form pattern.** The page declares a `form(Schema)`
  method backed by `Filament\Schemas\Components\Section` containers and
  `Filament\Forms\Components\{TextInput, Textarea}` fields. The blade
  view renders `{{ $this->form }}` inside a `<form wire:submit="save">`
  and a standalone submit button. The disabled system inputs use
  `->dehydrated(false)` so they are not persisted.
- **`/admin/settings` does not create a new panel.** It is a page
  inside the existing `admin` panel discovered by
  `discoverPages(in: app_path('Filament/Admin/Pages'), ...)`.
- **Cache driver.** The framework default cache driver applies. On a
  fresh Laravel 12 install the `file` driver is the default and works
  for this use case. Production deployments using `redis` benefit from
  faster invalidation, but no Redis-specific code paths were added.

### Verification commands (run at release)

```bash
composer dump-autoload
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class="TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder"
php artisan cms:settings-clear
php artisan route:list
php artisan about
curl -i http://laravel-cms.demo/cms-health   # expect 200 application/json
curl -i http://laravel-cms.demo/admin        # expect 302 -> /admin/login
curl -i http://laravel-cms.demo/admin/settings  # expect 302 -> login when unauth
```

In tinker:

```php
settings('general.site_name');                            // string
TheNguyen\CMS\Facades\Settings::get('general.site_name'); // string
TheNguyen\CMS\Facades\Settings::all();                    // array
```

---

## [0.1.0] — Initial Foundation

Released: **2026-05-28**

The first tagged version. Establishes the directory layout, namespaces,
admin panel, and deployment templates that every later phase will build
on. No content-management features ship yet — this release is the
**foundation** that Phase 2+ will fill in.

### Added

#### Framework baseline
- Laravel `^12.0` application bootstrapped.
- Filament `^5.6` installed and pinned.
- PHP `^8.2` (verified on 8.3.30).
- Composer `2.9.8` workflow checked in via `composer.json` scripts.

#### Filament admin panel
- Single panel provider at `app/Providers/Filament/AdminPanelProvider.php`
  exposing:
  - `->default()`
  - `->id('admin')`
  - `->path('admin')`
  - `->login()`
  - `Color::Amber` primary palette
  - resource / page / widget discovery rooted at `app/Filament/Admin/*`
- Admin mounted at `/admin`, login at `/admin/login`, logout at
  `/admin/logout`.
- `CmsInfoWidget` registered on the admin dashboard
  (`app/Filament/Admin/Widgets/CmsInfoWidget.php`) backed by
  `resources/views/filament/admin/widgets/cms-info.blade.php`. Shows
  CMS name, version, Laravel version, PHP version, active theme,
  deployment mode, base path, public path.

#### CMS core package
- New first-party package at `packages/thenguyen/cms-core/`.
- PSR-4 namespace `TheNguyen\CMS\` -> `packages/thenguyen/cms-core/src/`
  registered in the root `composer.json`.
- Service provider `TheNguyen\CMS\Providers\CmsServiceProvider`:
  - Merges `packages/thenguyen/cms-core/config/cms.php` into the
    `cms.*` config namespace.
  - Publishes the config file via the `cms-config` tag.
  - Ensures the runtime directories `modules/`, `themes/`,
    `public/uploads/`, `public/themes/`, `public/vendor/cms/` exist on
    boot.
  - Loads the CMS core routes file from inside the package.
- Registered in `bootstrap/providers.php` alongside the application and
  Filament providers.

#### Configuration
- `packages/thenguyen/cms-core/config/cms.php` introduced with sections:
  - `name`            — `env('CMS_NAME', 'TheNguyen CMS')`
  - `paths.base`      — `base_path()`
  - `paths.public`    — `public_path()`
  - `paths.modules`   — `base_path('modules')`
  - `paths.themes`    — `base_path('themes')`
  - `paths.uploads`   — `public_path('uploads')`
  - `paths.theme_assets` — `public_path('themes')`
  - `paths.cms_assets`   — `public_path('vendor/cms')`
  - `theme.active`    — `env('CMS_ACTIVE_THEME', 'default')`
  - `deployment.mode` — `env('CMS_DEPLOYMENT_MODE', 'public_root')`

#### Support classes
- `TheNguyen\CMS\Support\CmsInfo` with methods:
  `name()`, `version()`, `activeTheme()`, `deploymentMode()`,
  `basePath()`, `publicPath()`. `VERSION` constant pinned at `0.1.0`.
- `TheNguyen\CMS\Support\CmsPath` with methods:
  `base()`, `public()`, `modules()`, `themes()`, `uploads()`,
  `themeAssets()`, `cmsAssets()`.

#### Routes
- `GET /cms-health` (defined in `packages/thenguyen/cms-core/routes/web.php`,
  loaded by the service provider) returning JSON:
  ```json
  {
    "status": "ok",
    "app": "TheNguyen CMS",
    "cms_version": "0.1.0",
    "laravel": "12.43.1",
    "php": "8.3.30",
    "base_path": "...",
    "public_path": "...",
    "active_theme": "default",
    "deployment_mode": "public_root"
  }
  ```

#### Deployment templates
- `deployment/apache/root-public-html.htaccess` — for shared hosting
  where the full project is uploaded into `public_html`. Blocks direct
  access to `.env`, `artisan`, `composer.json`, `composer.lock`,
  `package.json`, `package-lock.json`, `phpunit.xml`, and the
  directories `app`, `bootstrap`, `config`, `database`, `modules`,
  `packages`, `themes`, `vendor`, `storage`, `tests`, `resources`,
  `routes`. Serves real files from `/public` when they exist; otherwise
  rewrites to `/public/index.php`. Sets baseline security headers and
  disables directory listing.
- `deployment/nginx/public-root.conf` — VPS Nginx layout where the
  document root is `/path/to/project/public`. Includes PHP-FPM
  fastcgi block and security-header defaults.
- `deployment/nginx/public-html-rewrite.conf` — VPS Nginx layout where
  the document root is the project root and traffic is rewritten into
  `/public`. Mirrors the Apache deny rules and scopes PHP to
  `^/public/.+\.php$`.

#### Runtime directories
- `modules/.gitkeep`
- `themes/.gitkeep`
- `public/uploads/.gitkeep`
- `public/themes/.gitkeep`
- `public/vendor/cms/.gitkeep`

These ensure the shape of the deployment is identical on a fresh clone,
before any modules or themes exist.

#### Documentation
- `README.md` rewritten as a quick-reference: URLs, credentials, DB,
  `.env` essentials, folder map, all four deployment modes, CMS core API
  surface, roadmap, common commands.
- `CMS_STRUCTURE.md` (this set) — permanent architecture reference.
- `CMS_CHANGELOG.md` (this file) — versioned history.
- `CMS_GUIDE.md` (this set) — install / deploy / operate guide.

### Changed
- `app/Providers/Filament/AdminPanelProvider.php`: added `->default()`
  so the admin panel is registered as the default Filament panel.
  Without this, `route('filament.admin.auth.login')` resolves but
  certain Filament helpers that look up the default panel can fail.
- `app/Providers/Filament/AdminPanelProvider.php`: `CmsInfoWidget`
  added to the `->widgets([...])` array so it renders on the dashboard
  alongside `AccountWidget` and `FilamentInfoWidget`.
- `bootstrap/providers.php`: appended
  `TheNguyen\CMS\Providers\CmsServiceProvider::class`.
- `composer.json` `autoload.psr-4`: added
  `"TheNguyen\\CMS\\": "packages/thenguyen/cms-core/src/"`.

### Fixed
- **Widget property type.** Initial `CmsInfoWidget` declared
  `protected int $columnSpan = 2`, which violates Filament's parent
  declaration (`int|string|array`). `composer dump-autoload` failed via
  the `package:discover` post-hook. Fixed by widening the type to
  `int|string|array` and setting `'full'`.
- **Default panel resolution.** Without `->default()` on the admin
  panel, Filament could not resolve a default panel for some
  request-time helpers, surfacing intermittently as login route
  resolution issues. Adding `->default()` makes `admin` the
  unambiguous default.

### Removed
- **`/hoangnguyen` panel.** Confirmed that **no** secondary Filament
  panel exists in this project. The only registered panel is
  `AdminPanelProvider` at `/admin`. `php artisan route:list` shows zero
  Filament routes outside the `admin` panel.
- **Stray Filament panel providers.** `app/Providers/Filament/` contains
  exactly one file: `AdminPanelProvider.php`. If any auxiliary provider
  is reintroduced later, it must be deliberate and documented here.

### Notes

- **Local environment.** Local URL is `http://laravel-cms.demo` (Laragon
  auto-virtual-host). Database is `laravel-cms` on `127.0.0.1` with
  `root` / empty password.
- **Composer SSL on Windows / Laragon.** The `composer.json` ships with
  ```json
  "config": {
    "cafile": "C:/laragon/etc/ssl/cacert.pem",
    "secure-http": false,
    "disable-tls": true
  }
  ```
  This is a known Laragon-only workaround for Windows SSL trust-store
  issues during `composer install`. **Do not propagate these flags to
  CI or production.** Remove them before publishing the package.
- **`secure-http` / `disable-tls`.** Same caveat as above — these are
  unsafe in any environment that touches the public internet. They are
  acceptable for local Laragon-only dev. See
  [`CMS_GUIDE.md`](CMS_GUIDE.md#ssl--composer-install-fails-on-windows-with-curl-error-60).
- **Public-root verification.** `curl -s -o /dev/null -w "%{http_code}"
  http://laravel-cms.demo/admin` returns `302` (redirect to login) and
  `http://laravel-cms.demo/cms-health` returns `200` with a JSON body.
- **`storage:link`.** `php artisan about` reports
  `public/storage` as `NOT LINKED`. This is intentional for now; the
  media library (Phase 5) will own that decision.
- **`CMS_NAME` env override.** On the dev machine this is set to
  `Laravel CMS` for historical reasons. The package default
  (`TheNguyen CMS`) is preserved in `config/cms.php` so any env that
  does not override `CMS_NAME` will see the correct name.
- **Caches cleared at release.** `php artisan optimize:clear` was run
  as part of the release verification. CI should run this on deploy.

### Verification commands (run at release)

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan about
curl -i http://laravel-cms.demo/admin       # expect 302 -> /admin/login
curl -i http://laravel-cms.demo/cms-health  # expect 200 application/json
```

---

## Future Releases

These entries are placeholders for **unreleased** work and will be filled
in as each phase lands. Each future release follows the same `Added /
Changed / Fixed / Removed / Notes` structure. The authoritative status of
what exists today lives in [`CMS_ARCHITECTURE.md`](CMS_ARCHITECTURE.md);
everything below is **planned, not implemented**.

> Releases `0.1.0` through `0.9.1` are shipped and documented above.
> Versions below are the forward roadmap only.

### [1.0.0] — Plugin / Module Manager *(planned)*

- Module discovery in `modules/{Name}/module.json`.
- Install / enable / disable / uninstall lifecycle.
- Module migrations, seeders, service providers.
- `TheNguyen\CMS\Modules\*` namespace activated.

### [1.1.0] — Widgets / Theme Options *(planned)*

- Widgets system and a theme options / customizer surface
  (`theme.json` `supports.theme_options` / `supports.widgets` enforced).

### [1.2.0] — API Layer *(planned)*

- Versioned JSON API at `/api/v1/*`.
- Sanctum tokens.
- Public read endpoints + authenticated write endpoints.

### [1.3.0] — SaaS / Workspace *(planned)*

- Multi-tenant workspace model.
- Per-workspace settings, themes, modules.

### [1.4.0] — Marketplace / Installer *(planned)*

- Browse / install / update modules and themes from inside the admin.
- ZIP installer and signed update channels.

---

## Maintenance

When tagging a new version:

1. Move the relevant items from the "Future Releases" section up into a
   new tagged release block at the top.
2. Update `TheNguyen\CMS\Support\CmsInfo::VERSION`.
3. Run the verification commands listed in `[0.1.0]`.
4. Update `README.md` and `CMS_GUIDE.md` if URLs, commands, or env keys
   changed.
