# TNCMS Core

**TNCMS** (TheNguyenCMS) is an open-source, Laravel-based content management
system with a single Filament admin panel. This repository is the **Core** — the
CMS engine plus one demo plugin and the default theme.

- Website: <https://tncms.org>
- License: [MIT](LICENSE)
- Status: **beta** (`1.0.0-beta.x`) — evaluate before production use.

The PHP namespace is `TheNguyen\CMS`; the Core package lives at
`packages/thenguyen/cms-core` and the admin panel is mounted at `/admin`.

---

## What's in Core

Bundled with TNCMS Core:

- **TNCMS Core** — the CMS engine (`packages/thenguyen/cms-core`), routing,
  Filament admin, installer, and Core upgrade workflow.
- **`plugins/hello-world`** — a minimal demo plugin showing the extension API.
- **`themes/default`** — the default frontend theme.

**Not** bundled with Core (separately distributed extensions):

- Page Builder, Ecommerce, Knowledge Library, Monetization, Travel Booking, POS,
  and other optional/commercial extensions.

TNCMS supports separately distributed free, community, and commercial
extensions. **Extensions are not part of Core and may be distributed under their
own licenses** — refer to each extension's own repository, package, and license
terms. Nothing here bundles those products or licenses them under Core's MIT.

---

## Requirements

- PHP **8.3+** with the extensions required by Laravel 12 (e.g. `mbstring`,
  `openssl`, `pdo`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`).
- A supported database (MySQL/MariaDB recommended).
- A web server whose document root points at the application `public/` directory.
- Writable `storage/` and `bootstrap/cache/`.

Developer builds additionally need **Composer** and **Node.js + npm**. Ordinary
shared-hosting installation does **not** (see below).

---

## Installation

There are three distinct distributions. Pick the one that matches your role.

### 1. Shared-hosting install (ordinary users) — no command line

Download `tncms-<version>-install.zip` from the GitHub Release, then:

1. Upload and extract it on your host.
2. Point your document root at the extracted `public/` directory.
3. Create an empty database.
4. Visit the site in a browser — the `/install` wizard runs automatically
   (welcome → requirements → database/site → super-admin → finish).

The install package ships prebuilt (`vendor/`, compiled assets, published theme
and Filament assets). **You do not need Composer, npm, Node, Artisan, or a
command line.**

> ⚠️ Do **not** use the install ZIP to update an existing TNCMS site — it is for
> fresh installations only.

### 2. Developer install (from source)

```bash
git clone https://github.com/tncms/core.git tncms-core
cd tncms-core
composer install
cp .env.example .env
php artisan key:generate
# configure DB credentials in .env
php artisan migrate
npm ci
npm run build
```

Then serve the app with `public/` as the document root. Developer source
installation is **not** zero-CLI.

---

## Upgrading an existing site

Download `tncms-<version>-upgrade.zip` and apply it through the authenticated
**`/upgrade`** wizard (Super Admin). It verifies the package, checks the system,
takes and verifies a full backup, then applies Core as a clean replace with
obsolete-file removal, migrations, cache refresh, and health checks — rolling
back automatically on failure.

> ⚠️ Do **not** manually extract the upgrade ZIP over a live installation, and do
> **not** extract the install ZIP over an existing site. Use `/upgrade`.

Remote/one-click updates are not part of this release.

---

## Verifying downloads

Every release asset ships a `.sha256` sidecar, and the release includes a
`release-manifest.json` listing the certified SHA-256 of each artifact. Verify
before installing:

```bash
sha256sum -c tncms-<version>-install.zip.sha256
```

The GitHub-generated "Source code" archives are convenience artifacts and will
not byte-match the custom `tncms-<version>-source.zip`.

---

## Documentation

In-repository developer documentation lives under the Core package
(`packages/thenguyen/cms-core`) and the shipped docs. Broader documentation is
published at <https://tncms.org> as it becomes available.

## Security

Please report vulnerabilities responsibly — see [SECURITY.md](SECURITY.md). Do
not disclose exploitable details in public issues.

## Contributing

Contributions to Core are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). For
help and where to ask questions, see [SUPPORT.md](SUPPORT.md).

## License

TNCMS Core is released under the [MIT License](LICENSE). Bundled third-party
components retain their own licenses.
