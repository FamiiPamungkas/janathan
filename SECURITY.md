# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Instead, report
privately so the issue can be addressed before it is disclosed.

- **GitHub (recommended):** use the [private vulnerability reporting][1]
  feature on this repository (Security → Report a vulnerability).
- **Email:** [fame1302@gmail.com](mailto:fame1302@gmail.com) with the subject
  prefix `[SECURITY]`.

Please include:

- A description of the vulnerability.
- Steps to reproduce (if applicable).
- The affected version(s) and any suggested fix.

You can expect an acknowledgement within a few days and a timeline for the fix.

## Security notes for operators

- **Router passwords** are AES-256-GCM encrypted at rest with `APP_KEY` (stored in
  the `settings` database table) and are never rendered to templates or logged.
  **Never change `APP_KEY`** after routers have been saved — stored passwords
  become undecryptable.
- **Never** expose folders containing `config/app.php` or `database/` over HTTP. Point the
  document root at `public/`.
- Keep PHP and Composer dependencies up to date (`composer update`, `npm audit`).
- Treat any credential logging in the codebase as dev-only state — it must not
  appear in production.

[1]: https://docs.github.com/code-security/security-advisories/working-with-repository-security-advisories/creating-a-repository-security-advisory
