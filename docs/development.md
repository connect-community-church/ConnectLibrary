# ConnectLibrary development checks

These checks are safe local/CI quality gates for the ConnectLibrary WordPress plugin. They do not deploy to live or staging WordPress, do not connect to church borrower/member data, and do not call external book metadata APIs.

## Requirements

- PHP 8.1 or newer.
- Composer 2.
- Bash plus either `zip` or `python3` for package verification.

If local PHP or Composer is unavailable, run the commands in a PHP/Composer container that mounts the repository, or use the Docker smoke test documented in `docker/README.md` for WordPress activation verification.

## Commands

From the repository root:

```sh
composer install
composer lint
composer phpcs
composer phase2:quality-gate
composer test
composer build:zip
```

Or run the full CI-style local gate:

```sh
composer check
```

What each command verifies:

- `composer install` installs development-only test and coding-standard tools into `vendor/`.
- `composer lint` runs `php -l` over all repository PHP files except generated/dependency directories.
- `composer phpcs` runs WordPress coding standards against the plugin runtime code.
- `composer phase2:quality-gate` runs `bin/check-phase2-quality-gate.php`, the deterministic Phase 2 privacy/security/accessibility/i18n gate documented in `docs/phase-2-privacy-security-accessibility-i18n-test-plan.md`.
- `composer test` runs the PHPUnit unit smoke tests with WordPress function stubs and synthetic data only.
- `composer build:zip` creates `dist/connectlibrary.zip` using the Build 01 package workflow.

The GitHub Actions workflow in `.github/workflows/ci.yml` runs these same checks on push and pull request. It intentionally has no deployment step and uses no live-site secrets.