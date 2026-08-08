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
- After changing any markup, rebuild assets (`npm run build`) and verify the page renders cleanly in both light and dark mode.

## Setup
1. `composer install`
2. Copy `.env.example` to `.env`, fill in `MIKROTIK_HOST`, `MIKROTIK_USER`, `MIKROTIK_PASS`, `MIKROTIK_PORT` (default 8728, or 8729 for API-SSL)
3. Point Laragon vhost (or `php -S localhost:8000 -t public`) to `/public`
4. Run asset build (or use watch mode for dev):
   - CSS: `npm run build:css` (`npx @tailwindcss/cli -i ./public/css/index.css -o ./public/css/app.css`)
   - JS: `npm run build:js` (`npx esbuild public/js/index.js --bundle --outfile=public/js/app.js`)
   - `npm run dev` runs both in watch mode

## Dev Server
- Local URL: `http://MSI-FPamungkas.local:8080`

## Testing Router Connectivity
Before building features, verify the RouterOS API connection works standalone (`bin/test-connection.php` or similar) — confirm login, and a simple `/system/resource/print` call succeeds — before wiring it into Slim routes.

## Notes for AI Agents
- This is a lightweight tool by design — resist pulling in Laravel-style abstractions (ORM, queues, service containers beyond basic DI) unless explicitly asked.
- RouterOS API calls are synchronous and can be slow on weak hardware — keep timeouts sane and surface connection errors clearly in the UI rather than failing silently.
- Never log or expose router credentials in error messages or client-side code.
- **Verify every change:** After completing any task, fetch `http://MSI-FPamungkas.local:8080` using WebFetch to confirm the page loads without errors.
