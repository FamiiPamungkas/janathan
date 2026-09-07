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
- **For Docker:** Docker Engine + Docker Compose (see [Deploy with Docker](#deployment-docker)).

## Installation (local development)

1. Clone the repo and install dependencies:

   ```bash
   composer install
   npm install
   ```

2. Review the committed `config/app.php` and adjust it:

   - `APP_BASE_PATH` — leave empty when the document root points straight at
     `public/`; set it (e.g. `/janathan`) for sub-folder installs.

3. Serve the app. Point your web server's document root at `public/` (e.g. a
   Laragon vhost), or for a quick test:

   ```bash
   php -S localhost:8000 -t public
   ```

   The first browser visit runs the web setup wizard (`/` → `/setup`), which
   creates the SQLite database (at `DB_PATH`, default
   `database/janathan.sqlite`), generates and stores `APP_KEY`, and creates the
   first admin account.

4. Build frontend assets (or use watch mode during development):

   ```bash
   npm run build      # one-off: icons + CSS + JS
   npm run dev        # watches CSS/JS and rebuilds on change
   ```

5. Open the app in a browser, log in, and add a router. Then press
   **Connect** on it to start managing its hotspot.

## Deployment (shared hosting)

A Windows batch build is provided:

```bat
build-deploy.bat
```

It assembles a production package at `dist\janathan/` (compiled assets,
production-only Composer deps, no database — the web setup wizard creates it
on the first browser visit).

Upload that folder, point your document root at its `public/` directory, and
ensure `database/` stays writable. The package also supports sub-folder
installs via the included root `.htaccess`. See the full guide in
`scripts/README-DEPLOY.md` (also shipped inside the package).

> **Re-deploying:** the build ships no database. To keep existing data,
> copy your previous `database/` over the new package — changing
> `APP_KEY` breaks all stored router passwords. (`config/app.php` ships with
> the package as a committed source file.)

## Deployment (Docker)

A multi-stage `Dockerfile` builds a single `php:8.3-apache` container with the
compiled frontend assets and production-only Composer dependencies. The web
root is `/app/public` (so `APP_BASE_PATH` stays empty).

Run it with Docker Compose:

```bash
docker-compose up -d --build
```

Then open `http://localhost:8080/`. On the first boot the app has no database,
so browsing to the site takes you through the **web setup wizard** — it creates
`database/janathan.sqlite`, generates and stores `APP_KEY`, and creates the
first admin account.

### Persistence & upgrades

- The SQLite database (including `APP_KEY` and all saved routers) lives in a
  named volume `database` mounted at `/app/database`. It survives container
  rebuilds and `docker-compose down`, so upgrades don't lose data or corrupt
  stored router passwords.
- PHP sessions are held on an ephemeral `tmpfs` at `/app/session` and are
  cleared on restart — as expected for sessions, nothing persisted there.
- To back up, copy `database/janathan.sqlite` out of the volume; to restore,
  place it back. Keep `APP_KEY` intact — changing it breaks stored passwords.

### Useful options

- **Change the host port:** `JANATHAN_PORT=9090 docker-compose up -d --build`
  (default `8080`).
- **Enable debug for local dev:**
  ```bash
  docker-compose build --build-arg APP_DEBUG=true
  docker-compose up -d
  ```
  `APP_DEBUG` defaults to `false`.
- **Rebuild assets/deps** after source changes: `docker-compose up -d --build`.
- The container health-check hits `/` over HTTP; logs are visible with
  `docker-compose logs -f janathan`.

The Windows shared-hosting build (`build-deploy.bat`) is a separate path and is
unaffected by the Docker setup.

## Configuration

All configuration comes from `config/app.php` (a committed source file):

| Variable | Description |
| --- | --- |
| `APP_DEBUG` | Show/hide error details (`true` / `false`) |
| `APP_NAME` | App display name |
| `DB_PATH` | SQLite file path, relative to project root |
| `APP_BASE_PATH` | Sub-path the app is mounted under (empty for docroot-at-`public/`) |
| `MIKROTIK_TIMEOUT` | TCP connect timeout in seconds for RouterOS |
| `MIKROTIK_SOCKET_TIMEOUT` | Read timeout in seconds for a single API reply |
| `MIKROTIK_ATTEMPTS` | Number of TCP connect attempts before giving up |

## App Flow

Log in to the app (`/login`) → manage routers (`/routers`) → press
**Connect** on a router (validates the RouterOS connection and stores
`router_id` in the session) → the dashboard and hotspot pages operate on that
router, opening a fresh RouterOS connection per request using its decrypted
credentials.

## Scripts

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

- Router passwords are encrypted with AES-256-GCM using `APP_KEY` (stored in
  the `settings` database table) and are never rendered to templates or logged.
  Only `RouterRepository::getCredentials()` decrypts them. Treat any credential
  logging in the codebase as dev-only state.
- Every POST requires a CSRF token (`CsrfMiddleware`).
- `APP_KEY` must never change after routers have been saved — stored passwords
  become undecryptable.
- Never expose folders containing `config/app.php` or `database/` over HTTP.

## License

Licensed under the **GNU General Public License v3.0 (or later)** — see
[LICENSE](LICENSE) for the full text. By contributing, you agree your
contributions are licensed under the same terms.

**Want to contribute?** See [CONTRIBUTING.md](CONTRIBUTING.md). Found a
security issue? See [SECURITY.md](SECURITY.md).