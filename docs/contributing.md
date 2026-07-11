---
title: Contributing
---

# Contributing

The authoritative contribution guide lives at `CONTRIBUTING.md` in the package root. This page summarizes what a code contributor to `artisanpack-ui/google` specifically needs to know.

## Development setup

Fork and clone the package repository, then pull it into an ArtisanPack UI dev app via a Composer path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../artisanpack-ui-google",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "artisanpack-ui/google": "@dev"
    }
}
```

The `artisanpack-ui-dev` app in this repository is already wired up this way — symlinks live under `packages/google/`.

Install package dependencies:

```bash
cd packages/google
composer install
```

## Running tests

```bash
composer test          # runs Pest
```

Filter to a single file or test:

```bash
./vendor/bin/pest tests/Feature/OAuth/OAuthManagerTest.php
./vendor/bin/pest --filter="handles the callback"
```

The test suite uses Orchestra Testbench with an in-memory SQLite database.

## Code style

```bash
composer lint          # php-cs-fixer --dry-run + phpcs
composer fix           # php-cs-fixer fix
composer cs            # phpcs only
composer cs:fix        # phpcbf (auto-fix what phpcs can)
```

The package follows the ArtisanPack UI code style — WordPress-style spacing (`if ( $condition )`, `[ 'key' => 'value' ]`), Yoda conditions, aligned operators, trailing commas in multiline. `composer fix` handles ~70% of it; `composer cs` catches the rest.

Configuration:

- `.php-cs-fixer.dist.php` — PHP-CS-Fixer rules, including custom `spaces_inside_parenthesis` / `spaces_inside_brackets` fixers.
- `phpcs.xml` — PHPCS rules for what PHP-CS-Fixer can't enforce (disallowed functions, PHP tag placement).

Run both before opening a merge request.

## Branch strategy

- `main` — release-ready code. Never commit directly.
- `feature/*`, `fix/*`, `chore/*` — branch prefixes for the type of change.
- Release branches: `release/x.y.z`.

Merge requests target `main`. Squash-merge is the default so `main` stays linear.

## Commit messages

Conventional commits, matching the ArtisanPack UI convention:

```
feat: add reauthorize endpoint
fix: preserve refresh_token on incremental consent
docs: clarify APP_KEY rotation behavior
chore: bump orchestra/testbench to ^10.2
```

Types: `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `ci`.

## PRs must include

1. **A test** — every behavior change gets a Pest test (unit or feature). Bug fixes get a regression test.
2. **Docs updates** — if you're touching public API, changing config, or shifting behavior in a way callers can observe, update the corresponding page under `/docs`.
3. **Changelog entry** — add a line to `CHANGELOG.md` under `## Unreleased`.
4. **Passing CI** — `composer lint` and `composer test` must be green.

## What to work on

Good first issues:

- Improving test coverage for edge cases in the drivers.
- Documenting fields you had to figure out from source.
- Adding more scope validation (e.g., warning when a registered scope doesn't look like a Google scope URL).

Bigger scoped work:

- Additional configuration drivers (Vault, HashiCorp Consul, AWS Parameter Store).
- Multi-connection-per-user support (right now `user_id` is `unique`; users can only connect one Google account).
- First-class multi-tenant helpers instead of the manual driver rebind pattern.

## Getting help

- GitHub issues on the [artisanpack-ui/google repo](https://github.com/ArtisanPack-UI/google).
- The maintainer email address is in `composer.json`.

## Code of conduct

See the top-level `CONTRIBUTING.md` for the code of conduct that covers every ArtisanPack UI project. In short: don't be a jerk.
