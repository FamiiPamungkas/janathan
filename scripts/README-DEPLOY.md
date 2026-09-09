# Janathan » Deployment Guide (Shared Hosting)

This package was produced by `build-deploy.bat` (Windows) or `build-deploy.sh` (Linux) and is ready to upload.
Slim's front controller lives in `public/` and the Apache `.htaccess` there
already routes all requests to it.

## Requirements

- **PHP 8.2+** with extensions: `pdo_sqlite`, `openssl`, `json` (typical cPanel
  "MultiPHP" / Select PHP Version includes these — enable them if not).
- **Apache with mod_rewrite** (cPanel default), or LiteSpeed. No MySQL needed —
  the database is a simple Writable SQLite file.

## 1. Upload

Upload the **whole package folder** (e.g. as `/home/<user>/janathan`) or just
its contents — see step 2 for which mode you're deploying.

## 2. Point the document root at `public/`

- **cPanel:** *Domains* → your domain → change **Document Root** to
  `<app>/public` (e.g. `/home/<user>/janathan/public`).
- **Apache vhost:** set `DocumentRoot` to the `public` folder.
- Never expose the folder that contains `config/app.php` or `database/` over HTTP.

### Alternative: install as a sub-folder (no Document Root control)

If you can't change the document root (e.g. apps dropped into `www/`
on Laragon, or a shared `public_html/`), upload the package into a
sub-folder such as `/janathan`. The `.htaccess` at the package root then:

- disables directory listing,
- serves `/janathan/css...`, `/janathan/js...`, `/janathan/fonts...`,
- routes everything else to the Slim front controller (`/janathan/login`, ...).

This mode is the **default** when the document root points at `public/` — the
committed `config/app.php` has `APP_BASE_PATH=''` (empty). If you deployed the
other way (app in a sub-folder such as `/janathan`), set `APP_BASE_PATH` (e.g.
`/janathan`) in `config/app.php`.

The same applies on Laragon: drop the package at `C:\laragon\www\janathan`
and open `http://<host>:<port>/janathan`, with `APP_BASE_PATH=/janathan` in
`config/app.php`.

## 3. `config/app.php` — ships with the build

`config/app.php` is a **committed source file** shipped as-is by the build
(with `APP_DEBUG=false`, `APP_BASE_PATH=''`). Edit it directly:

- Set `APP_BASE_PATH` to `/janathan` for a sub-folder install, or leave empty
  when the document root points straight at `public/`.
- Keep `APP_DEBUG=false` for production.
- Leave `DB_PATH=database/janathan.sqlite` unless you want the DB elsewhere.
- `MIKROTIK_TIMEOUT` / `MIKROTIK_SOCKET_TIMEOUT` / `MIKROTIK_ATTEMPTS` tune the
  RouterOS connection timeouts.

Nothing is auto-generated — edit the file before going live.

## 4. Make `database/` writable

The web server must be able to create and write the SQLite file in `database/`.
On cPanel, a folder owned by your account is usually writable as-is; if the
app reports a DB error, try `0755`/`0775` (or `0777` as a last resort).

## 5. Admin user — created by the web setup wizard

The package ships **without** a database. On the first browser visit the app
detects this and takes you through the **web setup wizard** (`/setup`), which
creates `database/janathan.sqlite` (all tables), generates and stores `APP_KEY`,
and creates the first admin account from the form you fill in.

The wizard enforces a username matching `[a-zA-Z0-9._-]+` and a password of
4+ characters.

## Re-deploying over an existing installation

Every build assembles a package **without** a database. On a re-deploy that must
keep existing data (saved routers, hotspot users), copy your current `database/`
folder from the previous install over the new package — changing `APP_KEY`
would make all previously stored router passwords undecryptable. (`config/app.php`
ships with the package; edit it directly if you need different settings.)

## Done

Open your site and log in. Add routers under **Routers**, then press
**Connect** on one to start managing its hotspot.