# AGENTS.md

## Project Overview
A lightweight Mikrotik (RouterOS) management web app — hotspot user management, active session monitoring, voucher generation/printing, and basic router administration through a web dashboard. Built for minimal footprint and easy deployment on cheap shared hosting or a home-lab VPS.

## Tech Stack
- **Language:** PHP 8.2+ (PSR-4 namespace `Fame1302\Janathan\` → `src/`)
- **Framework:** Slim 4 (routing + PSR-7 middleware + DI container via PHP-DI)
- **Mikrotik communication:** `evilfreelancer/routeros-api-php` (RouterOS API client)
- **Templating:** Twig (`twig/twig` ^3.8)
- **Styling:** Tailwind CSS v4 (`@tailwindcss/cli`) — source in `public/css/index.css`, compiled to `public/css/app.css`
- **Interactivity:** Alpine.js (server-rendered pages, no SPA), bundled with esbuild from `public/js/index.js` to `public/js/app.js`
- **UI library:** Pinemix (pinemix.com) — copy-paste Alpine.js + Tailwind v4 components, not an npm/composer package
- **Icons:** Phosphor Icons webfont (`@phosphor-icons/web`, regular weight) — copied to `public/fonts/phosphor/` by `npm run build:icons`
- **Local dev environment:** Laragon (Windows)
- **Dependency management:** Composer + npm

## Directory Structure
```
/config           - app config (bootstrap.php), container definitions (container.php), env loading
/public           - web root (index.php, compiled CSS/JS)
  /css/index.css     - Tailwind v4 source with @theme block (build input)
  /css/app.css       - compiled CSS (gitignored)
  /js/index.js       - Alpine.js source (bundled with esbuild)
  /js/app.js         - bundled Alpine.js (gitignored)
/src
  /Controllers     - route handlers + RedirectsTrait (thin — delegate to Services)
  /Services        - business logic, RouterOS wrappers, repositories (16 files)
  /Middleware       - AuthMiddleware, CsrfMiddleware, LocaleMiddleware
  /Exceptions       - RouterosConnectionException, RouterosCommandException
  /Support         - Logger (safe error_log utility)
  /Models          - RouterosVersion (value object for version parsing)
/templates          - Twig templates (layout.twig base, partials/, pages/)
/routes             - web.php (single file, grouped by feature)
/resources          - lang/ (en.php, id.php), voucher_template.html (default)
/bin               - init.php, test-connection.php, lint-templates.php, test-dashboard-queries.php, gen-apple-icon.php
/tests             - PHPUnit tests (phpunit.xml configured)
/scripts           - copy-phosphor.mjs, generate-env.php, README-DEPLOY.md
database/          - SQLite storage (gitignored)
build-deploy.bat   - Windows production build script
.env               - never commit (router IP, API port, credentials)
```

## Setup
1. `composer install && npm install`
2. Copy `.env.example` to `.env`. Set:
   - `APP_URL` — base URL of your dev server (e.g. `http://192.168.88.34:8080`)
3. Create the SQLite database + first admin user: `php bin/init.php <username> [password]` (prompts for password if omitted). DB lives at `DB_PATH` (default `database/janathan.sqlite`, gitignored).
4. Point Laragon vhost (or `php -S localhost:8000 -t public`) to `/public`
5. Build assets:
   ```bash
   npm run build      # one-off: icons + CSS + JS (all minified)
   npm run dev        # watches CSS/JS in watch mode
   ```

## Build Commands
- `npm run build` → `build:icons && build:css && build:js` (all minified)
- `npm run build:css` → `npx @tailwindcss/cli -i ./public/css/index.css -o ./public/css/app.css --minify`
- `npm run build:js` → `npx esbuild public/js/index.js --bundle --minify --outfile=public/js/app.js`
- `npm run build:icons` → `node scripts/copy-phosphor.mjs` (copies Phosphor fonts from node_modules to public/fonts/phosphor/)

## Dev Server
- Dev URL comes from `APP_URL` in `.env` — read it from `.env`, never assume a hostname.
- The dev server is run locally on **Windows** (Laragon) and is left running — **do not restart or re-run the project to verify changes**. Verify by reading `APP_URL` from `.env` and WebFetch-ing that URL.
- **PHP syntax checks run on Windows, not WSL:** PHP executes via Laragon on Windows — do NOT first check for or try to install a Linux/WSL PHP. To lint a file, invoke the Laragon PHP binary directly, e.g. `/mnt/c/laragon/bin/php/php-<version>-Win32-vs16-x64/php.exe -l path/to/file.php` (pick whichever version dir currently exists under `/mnt/c/laragon/bin/php/`).

## Coding Conventions
- **PSR-12** coding style. All files use `declare(strict_types=1)`.
- Controllers stay thin — delegate RouterOS calls to a Service class, never call the RouterOS API client directly from a controller.
- **Shared logic via traits, not duplication.** `RedirectsTrait` (all controllers), `ConnectsRouter` (stateless — `HotspotService`, `ProfileService` use it for connect/write/unreachable plumbing). A service that needs another service's logic should depend on it via DI (constructor) and call its public method.
- `readonly class` is used for stateless services (`HotspotService`, `DashboardService`, `ProfileService`).
- Add IDE type hints for untyped destructuring. The service `connect()` returns `[$router, $client]`; at every `[$router, $client] = $this->connect(...)` call site add `/** @var $client RouterosClient */` on the line above so editors can resolve `$client`.
- **One RouterOS connection per request** — `RouterConnectionManager` caches a single `RouterosClient` per router ID and disconnects in `__destruct`. Avoid reconnecting per call.
- **Custom exceptions:** `RouterosConnectionException` (timeout, refused, auth error) vs. `RouterosCommandException` (router rejected a command with `!trap`). Controllers catch `\Throwable` and route to `renderUnreachable()`.
- **Flash messages:** `$this->flash->add('success'|'error', $message)` via `FlashService`, rendered in `layout.twig`.
- All router credentials/config load from `.env` (use `vlucas/phpdotenv`) — never hardcode IP/user/pass.
- **Security:** Router passwords must never be rendered to templates or logged; only `RouterRepository::getCredentials()` may decrypt them. The encryption key is auto-generated on first run and stored in the `app_settings` table — it travels with the database, so re-deploying the code bundle (even with a changed `.env`) does not break stored passwords. Do not delete the database or the key is lost.

## App Flow / Data Model
- SQLite via PDO (no ORM). Tables: `users` (app logins), `routers` (saved MikroTik connections; `password_enc` is AES-256-GCM encrypted with an auto-generated key stored in `app_settings`), `hotspot_profiles`, `voucher_templates`, `app_settings` (key-value store holding the `encryption_key`).
- Flow: log in (`/login`) → manage routers (`/routers`) → "Connect" validates the RouterOS connection and stores `router_id` in the session → dashboard (`/`) opens a fresh RouterOS connection per request using the selected router's decrypted credentials.
- Session-based auth (`AuthMiddleware`) protects all routes except `/login`. Every POST carries a CSRF token (`CsrfMiddleware` + `csrf_token` Twig global).

## Twig Templates
- Use `layout.twig` as base, with `content` and `scripts` blocks. Keep logic out of templates — pass pre-formatted data from controllers.
- Registered Twig functions: `asset()`, `base_path()`, `url_for()`, `path_info()`, `flash()`, `trans()`.
- Registered Twig globals: `app_url`, `locale`, `locales`, `current_user`, `routers`, `active_router`, `csrf_token`.

## UI / Styling (Mobile-First + Pinemix)
- **Mobile-first is mandatory for every UI change:** build the mobile layout first, then enhance with `sm:` / `md:` / `lg:` breakpoints. On mobile, tables become stacked cards (`md:hidden` card list + `hidden md:block` table), stat grids go `grid-cols-2` (or 1) before expanding, and tap targets stay touch-friendly.
- **Use Pinemix (https://pinemix.com) for UI components.** It is a free copy-paste library — NOT an npm package. Copy component markup (including Alpine `x-data`) into Twig templates and adapt the data/ids. Components rely on Tailwind v4 classes, class-based dark mode (`.dark` on `<html>`), and the `@alpinejs/focus` plugin (already bundled).
- Custom colors referenced by Pinemix (e.g. the `secondary` palette) are defined in `@theme` in `public/css/index.css` — add new named colors there, not as arbitrary inline colors.
- Dark mode: class-based via the `dark` variant; Pinemix Dark Mode Toggle persists to `localStorage` key `dark-mode` (`'on' | 'off' | 'system'`). `layout.twig` applies the preference before paint.
- Icons: **Phosphor Icons** webfont. Use `<i class="ph ph-<name>" aria-hidden="true">` — icons inherit `color` via `text-*` classes. Size via font-size classes (`text-xs`/`text-base`/`text-xl`/`text-2xl`), not `size-*`. When adapting Pinemix components with Heroicons/Lucide SVGs, replace with the equivalent Phosphor glyph.
- **Keep control sizing consistent.** Standalone icon-only buttons: fixed `h-8 w-8` + `size-5` icon + `rounded-lg` (align with the 32px app logo). Text buttons: `px-3.5 py-2` (primary/secondary), `px-3 py-1.5` (inline actions).
- After changing any markup, rebuild assets (`npm run build`) and verify in both light and dark mode.

## Localization
- Infrastructure: `TranslationService`, `LocaleMiddleware`, `users.locale` column, navbar language switcher, `trans()` Twig helper.
- Message files: `resources/lang/en.php` and `resources/lang/id.php` (dot-notation keys, ~545 lines each).
- PHP-side: controllers/services use `$this->translator->trans('key')` and `$this->flash->add()` for localized flash messages.

## Scripts
| Script | Purpose |
|--------|---------|
| `php bin/init.php <user> [pw]` | Create/recreate SQLite schema + add admin user |
| `php bin/test-connection.php` | Verify RouterOS API connectivity against `MIKROTIK_*` env vars |
| `php bin/test-dashboard-queries.php` | Benchmark dashboard RouterOS queries |
| `php bin/lint-templates.php` | Syntax-check Twig templates |
| `php bin/gen-apple-icon.php` | Generate apple-touch-icon.png |
| `npm run build` | Compile icons + CSS + JS |
| `npm run dev` | Watch mode for CSS/JS |
| `build-deploy.bat` | Windows production build (see `scripts/README-DEPLOY.md`) |

## Testing
```bash
composer test   # or: vendor/bin/phpunit
```
PHPUnit 10, configured in `phpunit.xml`. Tests live in `tests/`.

## Notes for AI Agents
- This is a lightweight tool by design — resist pulling in Laravel-style abstractions (ORM, queues, service containers beyond basic DI) unless explicitly asked.
- RouterOS API calls are synchronous and can be slow on weak hardware — keep timeouts sane and surface connection errors clearly in the UI.
- `symfony/process` is listed in `composer.json` but appears unused in `src/` — investigate before adding new process-related code.
- **Verify every change:** After completing any task, read `APP_URL` from `.env` and WebFetch it to confirm the page loads without errors.
