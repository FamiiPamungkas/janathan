# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.10.1] - 2026-10-05

### Fixed

- Add a mobile select-all checkbox bar above the stacked hotspot user cards, matching the desktop table header behavior with indeterminate state and selected/total count.
- Keep the user-list Filter and Reset buttons on one row at narrower desktop widths by using the available filter-grid space.

## [0.10.0] - 2026-10-05

### Added

- Bulk uptime and total data limit editing for selected hotspot users, with independent Keep current, Set limit, and Unlimited options and MB/GB units.
- English and Indonesian labels, input validation, and success/failure counts for bulk limit updates while preserving user-list filters and existing usage counters.

### Fixed

- Correct the Slim error-handler constructor so application errors no longer trigger a secondary fatal TypeError.

## [Unreleased]

### Added

- Choose a hotspot server and set uptime and total data limits when creating or editing users and generating vouchers.
- Show each user's total data limit in the detail modal.

### Fixed

- Fixed profile list text sizing inconsistency: unified metadata values to consistent font-medium/text-sm hierarchy, added font-mono to technical values (rate limit, price, prefix, validity, shared users), and made empty values use smaller text-xs styling matching the users page layout.
- Aligned desktop profile table colors to match users table reference (text-zinc-600 dark:text-zinc-400 for values, profile name retains default color).

### Changed

- Group the data limit amount and MB/GB unit into one control and place the limits above Comment.
- Add a transparent footer to the user detail modal so its scrollbar clears the rounded edge.

## [0.8.0] - 2026-10-02

### Added

- Show each hotspot user's uptime limit in the user detail modal.

### Changed

- Show Rx/Tx bandwidth limits from the user profile instead of the user byte limits.
- Group MAC address with user details and show bytes in/out side by side above uptime in the Statistic section.
- Match the user detail modal scrollbar to the light and dark themes.

## [0.7.0] - 2026-09-21

### Added

- User-list sorting by name, profile, comment, uptime, traffic, expiry, and status, with ascending/descending order controls that persist through user actions.

## [0.6.1] - 2026-09-11

### Fixed

- Profile expiry monitor scheduler now only scans enabled users of the profile, skipping already-disabled rows (`disabled=no` filter) for a faster trip on each run.

## [0.6.0] - 2026-09-10

### Added

- Batch change expire date — select users via checkboxes and set a new expiry date and time for all selected users at once with a date/time picker modal. The picker pre-fills with the first selected user's existing expiry, if any.

## [0.5.1] - 2026-09-10

### Fixed

- User-list filters (`q`, `profile`, `comment`, `status`) are now preserved after reset-counter, delete, edit-save, edit-cancel, and batch operations instead of being silently reset.

## [0.5.0] - 2026-09-09

### Added
- Linux build & deploy script (`build-deploy.sh`) with zip output, `--basepath`, and `--nopause` options.

## [0.4.0] - 2026-09-09

### Added

- User list checkbox selection — replaces comment-based batch operations; supports select-all, individual toggle, and count display.
- Batch delete selected users — delete multiple users at once with an option to include connected users (uptime > 0).
- Batch reset traffic counters — reset bytes in/out for multiple selected users at once.
- User detail modal — eye icon opens a modal showing full user info: account (name, profile, status), password (with show/hide toggle), server, MAC address, traffic limits, uptime, and bytes transferred. Mobile uses a bottom-sheet layout; desktop uses a centered dialog.
- Single-user confirmation modals — reset counter and delete (single) now use in-page modals instead of browser `window.confirm()`.

### Changed

- Comment-based batch operations (delete by comment, print by comment) removed in favor of checkbox-based selection.

## [0.3.0] - 2026-09-07

### Changed

- User-list comment filter groups by the base comment (auto-stamped `exp=` token stripped) and matches with a prefix query.
- Long hotspot user comments wrap onto multiple lines instead of being truncated.

## [0.2.0] - 2026-09-07

### Added
- Display app version (`APP_VERSION`) in the footer.

## [0.1.0] - 2026-09-07

### Added

- Authentication with session-based login and CSRF protection.
- Router management — save, edit, delete, and test Mikrotik connections with AES-256-GCM encrypted credentials.
- Dashboard with live router status, resource usage, and system log.
- Hotspot user management — list, create, edit, enable/disable, delete users.
- Voucher generation, printing, and export (single and batch).
- Bulk user generation from price/validity input.
- Active session monitoring with per-session removal.
- Hotspot IP bindings (by-mac/by-ip), hosts, and cookies management.
- Hotspot profile import and management (price, color, IDR currency).
- Voucher template editor with print preview.
- Localization — English and Indonesian with in-app language switcher.
- Web-based setup wizard for first boot (auto-creates database, APP_KEY, and admin).
- Docker deployment with multi-stage build and persistent volumes.
- Shared hosting deployment via `build-deploy.bat`.
- Phosphor Icons webfont.
- Pinemix UI components.
- Mobile-first responsive layout with dark mode support.
- Twig templating with PSR-7 middleware pipeline (Slim 4 + PHP-DI).
- SQLite storage (no MySQL/ORM required).
- PHPUnit test suite.

### Added
- Remove legacy SQLite `hotspot_profiles` table — profile metadata now lives exclusively in RouterOS `on-login` scripts.

### Fixed
- Restore `applyMetadata()` helper for profile edit-form metadata display (color, price, prefix, validity days, start_on).

### Changed
- Profile metadata now read from RouterOS `on-login` scripts only; SQLite `hotspot_profiles` table no longer created or maintained.
