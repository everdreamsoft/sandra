# Contributing to Sandra

Thanks for taking an interest. This document covers what you need to know before sending a pull request.

## Development setup

```bash
git clone https://github.com/everdreamsoft/sandra.git
cd sandra
composer install
```

You'll need PHP 8.0+ and access to a MySQL or MariaDB instance for tests that touch the database layer.

## Running tests

```bash
./vendor/bin/phpunit
```

All tests should pass before you open a pull request. If you add new behavior, add a test for it. PHPUnit tests live under `tests/`.

## Branch policy

- `master` is the stable line.
- Feature branches (`feature/short-name`) branch off `master`.
- **Never merge benchmark datasets, large fixtures, or third-party artifacts into `master` or shared feature branches.** Keep them on dedicated branches and point to them via documentation instead.

## Commit messages

Short and present-tense. One-line subject summarizing the change, optional body explaining *why* (not *what* — the diff already shows the *what*).

```
Fix off-by-one in factory traversal
Clarify MCP installation prerequisites
```

## Code style

- PSR-12 for PHP.
- No unused imports, no `var_dump()` or `dd()` left behind.
- Public methods get a one-line docblock when the name isn't already self-describing. No multi-paragraph docblocks.

## Design-impacting changes

If your change touches the core model (concepts, entities, factories, triplets), the MCP tool surface, or the database schema, open an issue first to discuss the approach. Small fixes and docs improvements don't need a prior issue — just send the PR.

## Reporting issues

- Reproduce the problem on a clean clone of `master` before reporting.
- Include PHP version, MySQL/MariaDB version, and the exact command that fails.
- Trim logs to the relevant part.

## License

By contributing, you agree that your contribution is licensed under the MIT License (see [`LICENSE`](LICENSE)).
