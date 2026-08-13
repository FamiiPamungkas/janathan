# AGENTS.md

## Project Overview
A lightweight Mikrotik (RouterOS) management web app, similar in spirit to Mikhmon — hotspot user management, active session monitoring, and basic router administration through a simple web dashboard. Built for minimal footprint and easy deployment on cheap shared hosting or a home-lab VPS.

## Tech Stack
- **Language:** PHP 8.2+
- **Framework:** Slim 4 (routing + PSR-7 middleware + DI container via PHP-DI)
- **Mikrotik communication:** `evilfreelancer/routeros-api-php` (RouterOS API client)
- **Templating:** Twig (`twig/twig` ^3.8)
- **Styling:** Tailwind CSS v4 (`@tailwindcss/cli`) — source in `public/css/index.css`, compiled to `public/css/app.css`
- **Interactivity:** Alpine.js (no build-heavy SPA framework — keep pages server-rendered), bundled with esbuild from `public/js/index.js` to `public/js/app.js`
- **UI library:** Pinemix (pinemix.com) — copy-paste Alpine.js + Tailwind v4 components, not a package
- **Local dev environment:** Laragon
- **Dependency management:** Composer + npm

## Directory Structure
```
/config           - app config, container definitions, env loading
/public           - web root (index.php, compiled CSS, Alpine.js bundle)
  /css/index.css     - Tailwind v4 source (build input)
  /css/app.css       - compiled CSS (gitignored build output)
  /js/index.js       - Alpine.js source (bundled with esbuild)
  /js/app.js         - bundled Alpine.js (gitignored build output)
/src
  /Controllers     - route handlers (HotspotController, UserController, RouterController, DashboardController)
  /Services        - RouterOS connection wrapper, business logic (RouterosClient, HotspotService)
  /Middleware       - auth middleware, error handling
  /Models           - simple data structures/DTOs (no ORM needed — data comes from RouterOS live)
/templates          - Twig templates (layout.twig, partials/, pages/)
/routes             - route definitions (grouped by feature)
.env                 - router IP, API port, credentials (never commit)
composer.json
```

## Coding Conventions
- PSR-12 coding style.
- Controllers stay thin — delegate RouterOS calls to a Service class, never call the RouterOS API client directly from a controller.
- One RouterOS connection per request via DI container (avoid reconnecting per call).
- All router credentials/config load from `.env` (use `vlucas/phpdotenv`) — never hardcode IP/user/pass.
- Twig templates: use `layout.twig` as base, blocks for `content` and `scripts`. Keep logic out of templates — pass pre-formatted data from controllers.
- Alpine.js: keep components small and inline (`x-data` on the element they control). Use it for toggles, modals, polling/live refresh (e.g. active hotspot users) — not for full page state management.
- Prefer HTMX-style partial reload only if introduced later; for now, Alpine + fetch() for any AJAX polling (e.g. refreshing active sessions every N seconds).

## UI / Styling (Mobile-First + Pinemix)
- **Mobile-first is mandatory for every UI change:** build the mobile layout first, then enhance with `sm:` / `md:` / `lg:` breakpoints. On mobile, tables become stacked cards (`md:hidden` card list + `hidden md:block` table), stat grids go `grid-cols-2` (or 1) before expanding, and tap targets stay touch-friendly.
- **Use Pinemix (https://pinemix.com) for UI components.** Pinemix is a free copy-paste library of Alpine.js + Tailwind v4 components — it is NOT an npm/composer package. Copy the component markup (including its Alpine `x-data`) into the relevant Twig template and adapt the data/ids. Components rely on Tailwind v4 classes, class-based dark mode (`.dark` on `<html>`), and the `@alpinejs/focus` plugin (already bundled).
- Custom colors referenced by Pinemix (e.g. the `secondary` palette used by Table Sorting) are defined in `@theme` in `public/css/index.css` — add any new named colors there, don't use arbitrary inline colors for shared tokens.
- Dark mode: class-based via the `dark` variant; use the Pinemix Dark Mode Toggle pattern which persists to `localStorage` under key `dark-mode` (`'on' | 'off' | 'system'`). `layout.twig` applies the saved preference before paint to avoid flash.
- Icons: inline SVGs (Heroicons / Lucide style, as used in Pinemix components) — no icon package.
- **Keep control sizing consistent and visually balanced.** Standalone icon-only buttons (hamburger, close, theme) should be fixed-size squares (`h-8 w-8` + centered `size-5` icon, `rounded-lg`) so they align with adjacent fixed-size elements (e.g. the 32px app logo in the navbar) — never size them from padding (`p-2` + `size-5` = 36px) next to 32px neighbors. Match the size of whatever the button sits beside, and use one radius (`rounded-lg`) for all small buttons/inputs. Text buttons use `px-3.5 py-2` (primary/secondary) and `px-3 py-1.5` (inline actions) — don't invent new paddings for the same role.
- After changing any markup, rebuild assets (`npm run build`) and verify the page renders cleanly in both light and dark mode.

## Setup
1. `composer install`
2. Copy `.env.example` to `.env`. Set `APP_KEY` (generate with `openssl rand -hex 32`) — it encrypts router passwords at rest. `MIKROTIK_*` vars are only used by `bin/test-connection.php`.
3. Create the SQLite database + first admin user: `php bin/init.php <username> [password]` (prompts for a password if omitted). The DB file lives at `DB_PATH` (default `database/janathan.sqlite`, gitignored).
4. Point Laragon vhost (or `php -S localhost:8000 -t public`) to `/public`
5. Run asset build (or use watch mode for dev):
   - CSS: `npm run build:css` (`npx @tailwindcss/cli -i ./public/css/index.css -o ./public/css/app.css`)
   - JS: `npm run build:js` (`npx esbuild public/js/index.js --bundle --outfile=public/js/app.js`)
   - `npm run dev` runs both in watch mode

## App Flow / Data Model
- SQLite via PDO (no ORM). Tables: `users` (app logins) and `routers` (saved MikroTik connections; `password_enc` is AES-256-GCM encrypted with `APP_KEY` via `CryptoService`).
- Flow: log in to the app (`/login`) → manage routers (`/routers`) → "Connect" on a router validates the RouterOS connection and stores `router_id` in the session → dashboard (`/`) opens a fresh RouterOS connection per request using the selected router's decrypted credentials.
- Session-based auth (`AuthMiddleware`) protects all routes except `/login`. Every POST carries a CSRF token (`CsrfMiddleware` + `csrf_token` Twig global).
- Router passwords must never be rendered to templates or logged; only `RouterRepository::getCredentials()` may decrypt them.

## Dev Server
- Dev URL comes from `APP_URL` in `.env` (e.g. `http://192.168.88.34:8080`) — it changes per machine, so read it from `.env` rather than assuming a hostname.

## Testing Router Connectivity
Before building features, verify the RouterOS API connection works standalone (`bin/test-connection.php` or similar) — confirm login, and a simple `/system/resource/print` call succeeds — before wiring it into Slim routes.

## Notes for AI Agents
- This is a lightweight tool by design — resist pulling in Laravel-style abstractions (ORM, queues, service containers beyond basic DI) unless explicitly asked.
- RouterOS API calls are synchronous and can be slow on weak hardware — keep timeouts sane and surface connection errors clearly in the UI rather than failing silently.
- Never log or expose router credentials in error messages or client-side code. Exception: during local development only, temporary `error_log()` debugging of credentials (e.g. in `RouterController::connect`) is acceptable — but it must be removed before production. Treat the presence of credential logging in the codebase as a dev-only state.
- **Verify every change:** After completing any task, read `APP_URL` from `.env` and WebFetch it (e.g. `http://192.168.88.34:8080/login`) to confirm the page loads without errors.
