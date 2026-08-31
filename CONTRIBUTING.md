# Contributing to Janathan

Thanks for your interest in contributing! Here's a quick guide to get started.

## Code of Conduct

Be respectful and constructive. Harassment, discrimination, and personal attacks
are not tolerated. This project aims to stay friendly and inclusive for everyone.

## Quick start (local dev)

1. Clone the repo and install dependencies:

   ```bash
   composer install
   npm install
   ```

2. Copy `.env.example` to `.env` and configure `APP_KEY` (`openssl rand -hex 32`).

3. Create the SQLite DB and admin user:

   ```bash
   php bin/init.php <username> [password]
   ```

4. Build assets and serve:

   ```bash
   npm run build
   php -S localhost:8000 -t public
   ```

See the [README](README.md#installation-local-development) for the full setup.

## Development workflow

- **Mobile-first UI:** every UI change starts as a mobile layout, then enhances
  with `sm:` / `md:` / `lg:` breakpoints. Read the AGENTS.md UI section before
  touching markup.
- **Follow the conventions in `AGENTS.md`** — PSR-12, thin controllers,
  shared logic via traits/DI over copy-paste, credentials only from `.env`.
- Rebuild assets (`npm run build`) after changing markup, and verify in both
  light and dark mode.

## Running tests

```bash
composer test        # runs vendor/bin/phpunit
php -l <file.php>    # syntax check any changed PHP file
```

The test suite currently covers the RouterOS client (`tests/RouterosClientTest.php`).
Add tests alongside any business-logic changes.

## Submitting changes

1. Create a feature branch (`git checkout -b feature/your-change`).
2. Make focused, well-named commits.
3. Run the tests and lint your PHP changes.
4. Open a pull request describing the change, why it's needed, and how you
   verified it.

## Commit message style

Keep messages concise and descriptive, e.g.:

```
-add: Hotspot IP Bindings menu
-fix: sidebar navigation highlight on generated distributions
```

## Licensing

By contributing, you agree that your contributions are licensed under the
project's [GPL-3.0-or-later license](LICENSE).
