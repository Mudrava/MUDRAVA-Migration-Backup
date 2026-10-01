# Testing

The full test strategy is in [test-plan.md](test-plan.md); this is the
practical guide.

## Running everything

```bash
composer check              # phpcs + phpstan + phpunit (all PHP suites)
bin/lab-up.sh               # Docker two-site lab
npx playwright test         # E2E against the lab
```

## Suites

```bash
vendor/bin/phpunit --testsuite Unit          # format primitives
vendor/bin/phpunit --testsuite Contract      # vertical migration E2E (PHP)
vendor/bin/phpunit --testsuite GoldenArchives # byte-stable fixture
vendor/bin/phpunit --testsuite Fuzz           # reader robustness
```

## Rules

- A bug fix ships with a test that fails without the fix.
- Golden fixture changes = format contract changes = major version +
  doc update. Regenerate with `php tests/GoldenArchives/regenerate.php`
  only on purpose; the sha256 is printed and must be deterministic
  across runs.
- Fuzz corpus mutations must never crash the reader (no fatals, no
  unbounded memory) and must never silently pass: corrupt frames are
  rejected with a `MUDRAVA_*` code.
- E2E is deterministic: fixed seeds, fixed fixture sizes, single
  worker, no network beyond the lab.

## What the vertical migration test proves

`tests/Contract/VerticalMigrationTest` builds a fake WordPress-shaped
site (options with nested serialized URLs, binary cells, a 1 MiB
multi-chunk file, a symlink), exports it, restores into a fresh
destination, and asserts:

- every table/row matches semantically (unserialize + compare, not
  string equality);
- serialized URLs rewrote, nested levels included;
- binary cells are byte-identical;
- the symlink survived as a symlink;
- replaying the import is idempotent;
- `transform_failures === 0`.
