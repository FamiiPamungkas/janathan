# Janathan

A lightweight Mikrotik (RouterOS) hotspot management web app — hotspot user
management, active session monitoring, voucher generation/printing, and basic
router administration through a simple web dashboard. Built for a minimal
footprint and easy deployment on cheap shared hosting or a home-lab VPS.

## Features

- **Authentication** — single app-wide admin login (session-based), with CSRF
  protection on every POST request.
- **Router management** — save multiple Mikrotik connections (credentials are
  AES-256-GCM encrypted at rest with `APP_KEY`), connect/disconnect, and test
  connectivity before use.
- **Dashboard** — live router status, resource usage and system log, refreshed
  per request over a fresh RouterOS API connection.
- **Hotspot users** — list, create, edit, enable/disable and delete users;
  print single or multiple vouchers; export users; bulk-delete by comment;
  and generate batches of users sized from price/validity.
- **Active sessions** — live view of active hotspot users with per-user
  controls (remove session), plus auto-refresh.
- **IP bindings, hosts & cookies** — manage hotspot IP bindings (by-mac/by-ip),
  review registered hosts, and manage hotspot cookies.
- **Profiles** — import and manage hotspot user profiles (price, color, IDR
  currency), used by the voucher generator.
- **Voucher templates** — create printable voucher layouts and preview them
  against live profile data.
- **Localization** — `en` and `id` UI languages with an in-app switcher
  (persisted per user).
- **Print-friendly vouchers** — server-rendered voucher cards you can print
  directly from the browser.

## Tech Stack

- **PHP 8.2+** with **Slim 4** (routing + PSR-7 middleware) and **PHP-DI**
  (dependency injection).
- **RouterOS API** via `evilfreelancer/routeros-api-php`.
- **Twig** templates.
- **SQLite** (PDO) — no MySQL/ORM; the app's own data (users, saved routers,
  profiles, templates) lives in a single file.
- **Tailwind CSS v4** + **Alpine.js** (server-rendered pages, no SPA).
- **Phosphor Icons** webfont.
- **Pinemix** UI components (copy-paste Alpine.js + Tailwind patterns).

## Requirements

- PHP 8.2+ with `pdo_sqlite`, `openssl` and `json` extensions.
- Composer.
- Node/npm (only needed for building assets / local dev).
- For shared hosting: Apache with mod_rewrite (or LiteSpeed); the optional
  root `.htaccess` enables sub-folder installs.

## Installation (local development)

1. Clone the repo and install dependencies:

   ```bash
   composer install
   npm install
   ```

2. Copy `.env.example` to `.env` and configure it:

   ```bash
   cp .env.example .env
   ```

   - `APP_KEY` — generate with `openssl rand -hex 32`. It encrypts router
     passwords at rest — changing it later makes stored passwords undecryptable.
   - `APP_URL` — base URL of your dev server (e.g. `http://192.168.88.34:8080`).
   - `APP_BASE_PATH` — leave empty when the document root points straight at
     `public/`; set it (e.g. `/janathan`) for sub-folder installs.

3. Create the SQLite database and first admin user:

   ```bash
   php bin/init.php <username> [password]
   ```

   (Prompts for a password when omitted. The DB file lives at `DB_PATH`,
   default `database/janathan.sqlite`.)

4. Serve the app. Point your web server's document root at `public/` (e.g. a
   Laragon vhost), or for a quick test:

   ```bash
   php -S localhost:8000 -t public
   ```

5. Build frontend assets (or use watch mode during development):

   ```bash
   npm run build      # one-off: icons + CSS + JS
   npm run dev        # watches CSS/JS and rebuilds on change
   ```

6. Open `APP_URL` in a browser, log in, and add a router. Then press
   **Connect** on it to start managing its hotspot.

## Deployment (shared hosting)

A Windows batch build is provided:

```bat
build-deploy.bat /url https://example.com
```

It assembles a production package at `dist\janathan/` (compiled assets,
production-only Composer deps, a freshly minted `APP_KEY`, an initialized
SQLite database with an admin user). Options: `/base <path>`, `/url <url>`,
`/init <user> <pw>`, `/no-prompt`, `/nopause`.

Upload that folder, point your document root at its `public/` directory, and
ensure `database/` stays writable. The package also supports sub-folder
installs via the included root `.htaccess`. See the full guide in
`scripts/README-DEPLOY.md` (also shipped inside the package).

> **Re-deploying:** every build mints a fresh `APP_KEY` and empty database. To
> keep existing data, copy your previous `.env` and `database/` over the new
> package — changing `APP_KEY` breaks all stored router passwords.

## Configuration

All configuration comes from `.env`:

| Variable | Description |
| --- | --- |
| `APP_ENV` | `development` / `production` |
| `APP_DEBUG` | Show/hide error details (`true` / `false`) |
| `APP_NAME` | App display name |
| `APP_KEY` | Hex key (32 bytes) for AES-GCM encryption of router passwords |
| `DB_PATH` | SQLite file path, relative to project root |
| `APP_URL` | Base URL of the dev server or production site |
| `APP_BASE_PATH` | Sub-path the app is mounted under (empty for docroot-at-`public/`) |
| `MIKROTIK_*` | Only used by `bin/test-connection.php` (host, port, ssl, timeouts, attempts) |

## App Flow

Log in to the app (`/login`) → manage routers (`/routers`) → press
**Connect** on a router (validates the RouterOS connection and stores
`router_id` in the session) → the dashboard and hotspot pages operate on that
router, opening a fresh RouterOS connection per request using its decrypted
credentials. `MIKROTIK_*` env vars are only used by the standalone connectivity
tester.

## Scripts

- `php bin/init.php` — create/recreate the SQLite schema and add an admin user
  (also usable to add more admins later).
- `php bin/test-connection.php` — verify RouterOS API connectivity against the
  `MIKROTIK_*` env vars.
- `php bin/test-dashboard-queries.php` — exercise dashboard RouterOS queries.
- `php bin/lint-templates.php` — syntax-check Twig templates.
- `php bin/gen-apple-icon.php` — generate the apple-touch-icon.
- `npm run build` / `npm run dev` — compile Tailwind CSS, bundle Alpine.js and
  copy Phosphor icons.

## Testing

```bash
composer test   # if configured, otherwise: vendor/bin/phpunit
```

## Security Notes

- Router passwords are encrypted with AES-256-GCM using `APP_KEY` and are
  never rendered to templates or logged. Only `RouterRepository::getCredentials()`
  decrypts them. Treat any credential logging in the codebase as dev-only state.
- Every POST requires a CSRF token (`CsrfMiddleware`).
- `APP_KEY` must never change after routers have been saved — stored passwords
  become undecryptable.
- Never expose folders containing `.env` or `database/` over HTTP.

## License

Licensed under the **GNU General Public License v3.0 (or later)** — see
[LICENSE](LICENSE) for the full text. By contributing, you agree your
contributions are licensed under the same terms.

**Want to contribute?** See [CONTRIBUTING.md](CONTRIBUTING.md). Found a
security issue? See [SECURITY.md](SECURITY.md).