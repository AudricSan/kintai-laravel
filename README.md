# 🕒 Kintai

🌐 **English** · [Français](docs/i18n/README.fr.md) · [日本語](docs/i18n/README.ja.md)

**Open-source shift, attendance and workforce management for multi-store businesses.**

[![Version](https://img.shields.io/github/v/release/AudricSan/Kintai?include_prereleases&label=version&color=purple)](https://github.com/AudricSan/Kintai/releases)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892bf.svg)](https://php.net)
[![Tests](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml/badge.svg)](https://github.com/AudricSan/Kintai/actions/workflows/tests.yml)
[![Laravel](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)

Kintai is the bridge between a spreadsheet and an enterprise ERP: scheduling, clock-in/out, leave, shift swaps, open-shift bidding, daily reports and payroll estimates for retail and hospitality businesses running multiple stores — self-hosted, on your own infrastructure, with your own data.

> **Status: pre-1.0.** Kintai is functional, but the API and data schema may still evolve before a stable `1.0.0`. See [CHANGELOG.md](CHANGELOG.md) and [Releasing a Version (wiki)](https://github.com/AudricSan/Kintai/wiki/Releasing-a-Version) for the version scheme.

---

> **This repository is Kintai on Laravel.** It is the migration of [AudricSan/Kintai](https://github.com/AudricSan/Kintai)
> from its custom MVC core to Laravel 13, with one rule: no feature and no action is lost. See
> [Migration to Laravel](#migration-to-laravel) for where it stands.

## Why Kintai

- **You own your data.** No multi-tenant black box: each deployment is a dedicated instance with its own database. Self-host it on your own server with a `git clone` — no vendor lock-in.
- **Built for real multi-store operations.** Store-level timezones, currencies, break rules, understaffing thresholds, and per-store feature toggles — not a single-location tool stretched to fit.
- **Japan-ready, globally usable.** CJK-safe PDF exports, `姓 名` name ordering, JPY/EUR/USD display, and FR/EN/JA translations out of the box — designed around Japanese retail conventions but usable anywhere.
- **Modular by design.** A Laravel 13 core handles scheduling, users, stores and auth; everything else — daily reports, messaging, shift swaps, payroll — ships as a self-contained, independently toggleable bundle. Disable what you don't need, keep the instance light. See [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/Architecture).
- **Actually tested.** 1,500+ PHPUnit tests across the core, bundles, repositories and controllers, run on every push via GitHub Actions.

---

## Features

**Scheduling & shifts** — CRUD shifts/shift types (multi-store shift types via a pivot table), list/calendar/week/day/Gantt-timeline views, drag & drop, bulk actions, print-friendly timeline export, overlap-conflict detection, Excel import with visual analysis and confidence scoring.

**Time & attendance** — Digital clock-in/out with live timer, admin timeclock editing, automatic wage estimation from shifts/breaks/hourly rates/deductions via a single shared calculator.

**Open shifts & shift swaps** — Employees bid on unassigned open shifts or request a direct swap with a colleague; managers approve/reject with automatic conflict checks.

**Leave & availability** — Weekly availability declarations and leave requests, with manager approval workflow.

**Employee self-service** — Personalized dashboard, iCal feed, self clock-in/out.

**HR paperwork** — Auto-generated hiring reports on employee creation, resignation/offboarding reports, and salary reports with period autofill and PDF export.

**Store photos** — Weekly sales-floor photo submissions and tracking, with automatic same-day consolidation.

**Communication** — Internal threaded messaging (live updates via SSE), notification center with toasts, read/unread state, and optional push notifications (Firebase Cloud Messaging).

**Daily reports** — Store-level KPI reports (sales, customers, labor cost) with a draft → submitted → validated workflow, PDF export (mPDF, CJK fonts), scheduled auto-validation, and email delivery.

**Access control** — Database-backed roles and permissions (RBAC), assignable per store or globally, checked by dedicated middleware on both web and API routes.

**REST API** — Versioned (`/api/v1`), Bearer-token auth, pagination, covering every core resource plus each bundle's own endpoints. Reference: [.wiki/API-Reference.md](.wiki/API-Reference.md).

**Installable PWA** — Add-to-home-screen support with an offline fallback page and a precached app shell for spotty connections.

**Operations** — One-click self-update from GitHub Releases (with progress bar and automatic backup/rollback safety net), SQLite/MySQL backup & restore, token-protected cron endpoints for external schedulers.

Full breakdown in [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/Architecture) and [Database Strategy (wiki)](https://github.com/AudricSan/Kintai/wiki/Database-Strategy).

---

## Roles & workflows

| Role | Scope |
| :--- | :--- |
| Owner | All stores, audit log, global configuration, bundle & role management |
| Store manager/admin | Planning, staff, leave, swaps, reports and stats for their store(s) |
| Staff | Own schedule, clock-in/out, leave, swaps, open shifts, messages |

Fine-grained permissions on top of these roles are configurable per instance — see the RBAC note above.

Typical flow: shifts created or imported → conflicts checked → employees notified → attendance tracked → daily reports validated → payroll estimated.

---

## Getting started

### Requirements

- PHP 8.3+, Composer 2.x
- `pdo_sqlite` or `pdo_mysql`, `mbstring`, `gd`, `intl`

### Local install

```bash
git clone https://github.com/AudricSan/Kintai.git
cd Kintai
composer install
php -S 127.0.0.1:8000 -t public
```

Then open `http://127.0.0.1:8000/install.php` — the web installer creates `config/database.local.php`, runs migrations, creates the admin account, saves the instance language you pick (`APP_LOCALE` in `.env`, changeable later in the instance settings), signs you in and opens the setup assistant (`/admin/setup`): enter a license key or continue on the free plan, then choose the bundles (features) to install — as many as your license allows (4 on the free plan). They are downloaded from the bundle catalogue; any that cannot be installed are listed so you can retry from the catalogue. The installer checks the server requirements (PHP version, extensions, writable folders) before writing anything, and a failed installation is rolled back entirely, so you can simply fix the cause and try again.

**Moving an existing instance** (the pre-Laravel version included) to this one: on the same page choose **Import an existing instance** and give the folder of the installed instance on the same server (for example `D:\xampp\htdocs\Kintai`). Its database, `.env`, uploaded files, backups and installed bundles are taken over and the pending migrations applied; the old folder is never modified (a MySQL database, though, is upgraded in place: back it up first and stop using it from the old instance). Sign in with your usual account, then update the imported bundles to their Laravel versions (3.x) from **Bundles › Catalogue**.

The installer also records the address you install from as the instance's **public URL**, used for the links in emails (password reset). Check it under Owner settings (`/admin/owner-settings`) if users reach Kintai through another address; the `APP_URL` environment variable, when set, takes precedence. Without a public URL, password reset emails are not sent and the Owner sees a warning.

To install without a browser (scripted deployment), `scripts/provision.php` does the same from the command line — SQLite by default, `--db-driver=mysql` plus `--db-*` options otherwise, environment variables as fallback (see the header of the script). Pass `--app-url`, since there is no request address to learn the public URL from. The instance language is English unless you pass `--locale=fr` or `--locale=ja` (same default as the web installer):

```bash
php scripts/provision.php --admin-email=admin@example.com --admin-password='…' --app-url=https://kintai.example.com
```

### Web server

Only `public/` is meant to be served. Point the web server's document root (Apache `DocumentRoot`, nginx `root`) at it — never at the repository root, or `.env`, `storage/app/database.sqlite`, backups and the source code become downloadable. `php -S … -t public` already does this. On Apache, if the server (or a shared host's `www/` folder) points at the repository root anyway, a root `.htaccess` transparently rewrites every request to `public/` — the URL stays clean, nothing outside `public/` becomes reachable, and it works from a sub-folder too (`/Kintai/`). `storage/.htaccess` only denies the technical sub-folders that physically exist (`app`, `backups`, `bundles`, `cache`, `logs`, `uploads`): Apache checks it against the original `/storage/…` URL *before* that rewrite, so a blanket deny would answer 403 for photos and avatars served by the access-controlled `/storage/{path}` route. nginx has no equivalent, so its `root` must be right.

On Apache, `public/.htaccess` redirects every plain-HTTP request to HTTPS (301), except local development hosts (`localhost`, `127.x`, `[::1]`, `*.test`, `*.localhost`, private network addresses) and requests already marked HTTPS by a proxy or host that terminates TLS itself (`X-Forwarded-Proto: https`). Serve the production site over HTTPS with a valid certificate.

### Updating

```bash
php scripts/db-migrate.php --dry-run   # preview pending migrations
php scripts/db-migrate.php             # apply them
```

Owners can also trigger a one-click update from `/admin/update`, choosing a release channel (stable/beta/alpha); it pulls the matching GitHub Release, backs up the database and code first, and shows live progress.

Instances still on 0.x (before Laravel) update the same way: the release archive carries a `src/` → `app/` relay, so the update request finishes on the new code and the site leaves maintenance mode as usual.

### Docker

```bash
git clone https://github.com/AudricSan/Kintai.git
cd Kintai
docker compose up -d
```

Then open `http://localhost:8080/install.php` and install as above (SQLite works out of the box; for MySQL, give the installer your database server). The image runs Apache and PHP 8.3 with every extension Kintai uses, and serves only `public/`. Everything the instance owns lives in the `kintai-storage` volume: the SQLite database, backups, installed bundles, uploaded files, and `.env` and `config/database.local.php` (linked into the volume at start-up). Environment variables set in `docker-compose.yml` (`APP_URL`, `MAIL_*`…) reach PHP.

To update, rebuild or pull a newer image and recreate the container (`docker compose build --pull && docker compose up -d`): pending migrations run at start-up. Do not use the one-click update inside a container, since its files are replaced when the container is recreated. Scheduled tasks run from one line in the host's crontab, every minute: `* * * * * docker compose -f /path/to/docker-compose.yml exec -T -u www-data kintai php artisan schedule:run` (times in `config/schedule.php`), or through the cron URL with a token (see `scripts/cron.php`).

---

## Tech stack

PHP 8.3 on **Laravel 13** (Eloquent, Artisan). SQLite or MySQL, interchangeable via config. Server-rendered PHP views, modular vanilla CSS/JS, no bundler.

```
public/index.php         → Laravel front controller
bootstrap/app.php        → Laravel application
routes/web.php           → Laravel routes; routes/kintai.php → Kintai's routes (being moved over)
app/Core/                → Kintai core: container, router, middleware, repositories, services (being replaced by Laravel's)
app/UI/                  → Web + API controllers, PHP views
app/Contract/            → the bundle contract (stable API for bundles)
public/assets/           → modular CSS/JS, no build step
database/migrations/php/ → Eloquent migrations, SQLite + MySQL from one schema
```

## Migration to Laravel

1. **Done — Kintai runs on the Laravel kernel.** Laravel receives every request and hands it to the Kintai application
   (`app/Http/KintaiBridge.php`). Routes, middlewares, controllers, views, bundles and the bundle contract are
   unchanged; Laravel's database manager is the single connection (Capsule, Eloquent and `DB::` share it).
2. **In progress — Laravel replaces the home-made pieces one by one.**
   - Done: one service container (Kintai's container stores its bindings in Laravel's), Kintai and bundle routes
     registered in Laravel's router (`php artisan route:list`), views rendered by Laravel's view engine (Blade views
     are now possible), and every maintenance script available as an Artisan command (`php artisan list kintai`,
     e.g. `php artisan kintai:cron backup`; the `scripts/*.php` files keep working). Laravel's `auth()`, `Auth::`,
     `@auth` and the Gate (`Gate::allows('shifts.update', $storeId)`, `@can`) answer with Kintai's logged-in user and
     RBAC permissions. Laravel's validator (`validator()`, `Validator::make`, `->validate()`) speaks Kintai's
     validation messages in the user's language, and its errors are answered like Kintai's own (422 JSON, or back to
     the form with the input kept). Every core view is a Blade template (`app/UI/View/**/*.blade.php`); a bundle's
     plain PHP views keep working, and the old `src/UI/View/_partials/*.php` paths still include the shared partials.
   - Kintai's middlewares (global ones from `config/middleware.php`, then those of each route) run in Laravel's
     middleware stack, in the same order (`php artisan route:list -v` lists them); an exception still crosses them
     up to Kintai's error pages. Laravel's own global middlewares (CORS, POST size, input trimming) stay off.
   - Kept on purpose: the PHP session and Kintai's CSRF token, so that nobody is logged out by the update and
     bundles keep reading `$_SESSION`.
   - The official bundles live in [kintai-laravel-bundles](https://github.com/AudricSan/kintai-laravel-bundles)
     (one folder per bundle, Blade views, bundle contract 1.31), which the official registry points to. Their tests
     run against this repository.

Every step keeps the full PHPUnit suite green and is checked against the original application: every GET page and
every form, logged in as the Owner, a manager and an employee, with and without bundles, must give the same response and leave the same database.

Deeper dives: [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/Architecture) · [Database Strategy (wiki)](https://github.com/AudricSan/Kintai/wiki/Database-Strategy) · [Multi-Tenancy (wiki)](https://github.com/AudricSan/Kintai/wiki/Multi-Tenancy)

---

## Contributing

Contributions are welcome — bug reports, feature ideas, and pull requests alike.

```bash
composer install
./vendor/bin/phpunit
```

Read [CONTRIBUTING.md](CONTRIBUTING.md) for code conventions (strict types, constructor injection, git workflow) before opening a PR, and [SECURITY.md](SECURITY.md) to report a vulnerability privately rather than via a public issue.

---

## Roadmap

**Next**
- [ ] Native mobile app for employees and managers — the push-notification API (device tokens, Firebase Cloud Messaging relay) is already in place to support it
- [ ] Visual conflict overlay directly on the scheduling timeline
- [ ] Targeted tests on payroll, swaps, open shifts and notifications

**Later**
- [ ] Payroll CSV/XML export
- [ ] Outbound webhooks (Slack, LINE, Teams)
- [ ] Additional web UI languages (currently FR/EN/JA)
- [ ] Multi-store reporting and analytics dashboard

Full history in [CHANGELOG.md](CHANGELOG.md).

---

## License

[GNU Affero General Public License v3.0](LICENSE). You're free to use, modify and redistribute Kintai, including commercially — but any modified version you run as a network service must make its source available. See [LICENSE](LICENSE) for the full text.
