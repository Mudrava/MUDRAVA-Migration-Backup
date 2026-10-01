# Contributing

Thanks for your interest in improving MUDRAVA Migration & Backup.

## Before you start

- Read [docs/architecture.md](docs/architecture.md) and
  [docs/mudrava-format.md](docs/mudrava-format.md). The archive format
  is a compatibility contract; changes need a design discussion first.
- For vulnerabilities, follow [SECURITY.md](SECURITY.md) - do not open
  a public issue.

## Development setup

```bash
composer install
npm install
bin/lab-up.sh        # two-site Docker lab (optional, for E2E)
composer check       # phpcs + phpstan + phpunit
```

## Pull requests

- One logical change per PR; keep diffs reviewable.
- Every behavior change needs a test. Bug fixes need a regression test
  that fails without the fix (red-green).
- `composer check` must pass. Do not suppress, skip, or weaken tests or
  static analysis to get green.
- Update `CHANGELOG.md` under "Unreleased".
- If the archive format changes, regenerate the golden fixture only
  after the format doc is updated and the change is agreed.

## Code style

PSR-12 and PHP 7.4 compatibility, checked locally with PHPCompatibility.
Security WordPress sniffs are enforced by the local lint command.
CI workflows currently run manually.
Comments explain *why*, not *what*. No em-dashes in copy.

## Commit style

Imperative subject ("Add …", "Fix …"), body explains motivation.
Frequent focused commits; never mix formatting with behavior changes.
