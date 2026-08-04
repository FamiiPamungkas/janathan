# AGENTS.md

## Project Overview
A lightweight Mikrotik (RouterOS) management web app, similar in spirit to Mikhmon — hotspot user management, active session monitoring, and basic router administration through a simple web dashboard. Built for minimal footprint and easy deployment on cheap shared hosting or a home-lab VPS.

## Tech Stack
- **Language:** PHP 8.2+
- **Framework:** Slim 4 (routing + PSR-7 middleware + DI container via PHP-DI)
- **Mikrotik communication:** `evilfreelancer/routeros-api-php` (RouterOS API client)
- **Templating:** Twig (`twig/twig` ^3.8)
- **Styling:** Tailwind CSS
- **Interactivity:** Alpine.js (no build-heavy SPA framework — keep pages server-rendered)
- **Local dev environment:** Laragon
- **Dependency management:** Composer

## Directory Structure
```
/config           - app config, container definitions, env loading
/public           - web root (index.php, compiled CSS, Alpine.js include)
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

## Setup
1. `composer install`
2. Copy `.env.example` to `.env`, fill in `MIKROTIK_HOST`, `MIKROTIK_USER`, `MIKROTIK_PASS`, `MIKROTIK_PORT` (default 8728, or 8729 for API-SSL)
3. Point Laragon vhost (or `php -S localhost:8000 -t public`) to `/public`
4. Run Tailwind build (or use Tailwind CDN for early dev) — `npx tailwindcss -i ./src/input.css -o ./public/css/app.css --watch`

## Dev Server
- Local URL: `http://MSI-FPamungkas.local:8080`

## Testing Router Connectivity
Before building features, verify the RouterOS API connection works standalone (`bin/test-connection.php` or similar) — confirm login, and a simple `/system/resource/print` call succeeds — before wiring it into Slim routes.

## Notes for AI Agents
- This is a lightweight tool by design — resist pulling in Laravel-style abstractions (ORM, queues, service containers beyond basic DI) unless explicitly asked.
- RouterOS API calls are synchronous and can be slow on weak hardware — keep timeouts sane and surface connection errors clearly in the UI rather than failing silently.
- Never log or expose router credentials in error messages or client-side code.
- **Verify every change:** After completing any task, fetch `http://MSI-FPamungkas.local:8080` using WebFetch to confirm the page loads without errors.
