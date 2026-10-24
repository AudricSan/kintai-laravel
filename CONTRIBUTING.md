# Contributing to Kintai

🌐 **English** · [Français](docs/i18n/CONTRIBUTING.fr.md) · [日本語](docs/i18n/CONTRIBUTING.ja.md)

Thank you for your interest in contributing!

## Before You Start

Kintai is licensed under the **GNU Affero General Public License v3.0** (AGPL-3.0).
By contributing, you agree that your contributions will be licensed under the same terms.

## How to Contribute

### Reporting Bugs

Open an issue using the **Bug Report** template. Include:
- PHP version and OS
- Steps to reproduce
- Expected vs. actual behavior
- Relevant logs from `storage/logs/`

### Suggesting Features

Open an issue using the **Feature Request** template. Explain the use case and why it fits the project's scope — see [Product Vision (wiki)](https://github.com/AudricSan/Kintai/wiki/Product-Vision) for the product direction.

### Submitting a Pull Request

1. Fork the repository
2. Create a branch: `git checkout -b feat/my-feature` or `fix/my-bug`
3. Make your changes following the conventions below
4. Run the tests: `./vendor/bin/phpunit`
5. Open a pull request against the `develop` branch (the default integration branch for day-to-day work — merging into it does **not** publish a release). `main`, `alpha`, and `beta` are protected release-channel branches: merging into any of them automatically tags and publishes a GitHub Release, so they're only updated deliberately by promoting `develop` forward (see [Releasing a Version (wiki)](https://github.com/AudricSan/Kintai/wiki/Releasing-a-Version))

## Code Conventions

- PHP 8.3+, strict types (`declare(strict_types=1)`) in every file
- Namespace root: `kintai\` → `src/`
- Controllers: `final class`, constructor injection, signature `method(Request $request): Response` — route parameters are read via `$request->param('name')`, never as method arguments
- Persistence goes through repository interfaces (`src/Core/Repositories/*Interface.php`) bound in `RepositoryServiceProvider` — controllers and services never touch Eloquent models directly
- HTTP-level errors use the exception hierarchy in `src/Core/Exceptions/`
- New DB tables: add one Eloquent migration in `database/migrations/php/` — it must work for both SQLite and MySQL (no per-driver files)
- SQLite here does not enforce `ON DELETE CASCADE` — delete dependent rows explicitly in repository code
- Comments in code are written in French; everything else (commit messages, PR descriptions, docs) in English
- No external framework dependencies beyond `illuminate/database` (Eloquent, used as an ORM only)
- Never use inline `style="..."` in views — extend a CSS module under `public/assets/css/src/` instead
- No inline event handlers (`onclick=`, `onchange=`, `onsubmit=`, `oninput=`…), no `javascript:` links and no `<script>` without a nonce in views or components — the Content-Security-Policy (`script-src 'self' 'nonce-…'`) blocks them silently. Use `data-on-click`/`data-on-change`/`data-args`, `data-submit-on-change`, `data-confirm`, `data-stop-propagation` (`public/assets/js/modules/csp-actions.js`) and `<script nonce="<?= csp_nonce() ?>">`; `NoInlineScriptGuardTest` fails on a violation. Bundle authors: see the wiki page [Creating a Bundle](https://github.com/AudricSan/Kintai/wiki/Creating-a-Bundle) ("Content Security Policy")
- Filter bars apply instantly — no "Filter" submit button. Text inputs debounce-submit their form on `input` (see the generic wiring in `public/assets/js/app.js` targeting `form.filter-bar`/`form.shifts-filters`), `select`/date fields submit `onchange`. This applies to every filter, including name/text search fields

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

CI also runs a fresh-install smoke test (`install-smoke` job, `scripts/ci/install-smoke-test.sh`): the real web installer on an empty SQLite database, then a login with the account it created. It writes into the checkout, so it refuses to run outside CI (`CI=true`) or where an installation already exists — run it locally only in a throwaway clone.

A second job, `provision-smoke` (`scripts/ci/provision-smoke-test.sh`), does the same for the command-line installer, `scripts/provision.php`, including its refusals (invalid input, already installed, existing account). Same safeguards.

Every new or changed feature should come with PHPUnit tests under `tests/Unit/` (and `tests/Integration/` where relevant).

## Documentation languages

README, CHANGELOG, CONTRIBUTING and SECURITY ship in English, French (`.fr.md`) and Japanese (`.ja.md`) — the translations live in `docs/i18n/` — and the [wiki](https://github.com/AudricSan/Kintai/wiki) (the long-form documentation) ships in the same three languages. English is the source of truth — if you change an English doc, updating the translations is appreciated but not required to get a PR merged. `php scripts/check-translations.php` reports missing or stale translations (non-blocking, runs in CI on every push).

## Where to look first

- [Architecture (wiki)](https://github.com/AudricSan/Kintai/wiki/Architecture) — how the framework is put together
- [Database Strategy (wiki)](https://github.com/AudricSan/Kintai/wiki/Database-Strategy) — models, repositories, migrations
- [CHANGELOG.md](CHANGELOG.md) — what's been done recently
