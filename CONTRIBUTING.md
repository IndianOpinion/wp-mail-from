# Contributing

Thank you for helping. This is a small plugin that does one job; changes
that keep it small and correct are the most welcome.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/wp-mail-from.git
cd wp-mail-from
composer install
```

No WordPress install is needed for the tests: they run against a small stub
layer in `tests/stubs/`.

## Checks

Run everything CI runs:

```sh
composer check
```

Or one at a time:

```sh
composer lint      # php -l on every file
composer phpcs     # PSR-12 plus a few security sniffs
composer phpstan   # PHPStan level 8 with WordPress stubs
composer test      # PHPUnit 10
```

CI runs the same checks on PHP 8.1, 8.2 and 8.3. Write code that runs on
8.1: no PHP 8.2 or later features.

## Tests

- Every behaviour in [docs/PRD.md](docs/PRD.md) section 4 has a case number
  (C1 to C65) and every acceptance test a T number. Name the T number and
  cases in the test's docblock.
- A bug fix comes with a test that fails without the fix.
- If you add a WordPress function call, add it to `tests/stubs/functions.php`
  with the behaviour the plugin relies on, not a copy of core.

## Style

- PSR-12, `declare(strict_types=1);` in every PHP file.
- Escape every output, check capability and nonce on anything admin-facing,
  validate every input. No `eval`, no shell, no `@`.
- Every default gets a filter or an action. Document new hooks in the README.
- British spelling, short sentences, no em dashes in prose.

## Pull requests

- One change per pull request, with a short description of what and why.
- Update `CHANGELOG.md` under "Unreleased".
- Update the README and `docs/ARCHITECTURE.md` when behaviour or a decision
  changes.
- All checks must pass. A maintainer reviews and merges.
- A sign-off line (DCO) is not required.

## Reporting security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
