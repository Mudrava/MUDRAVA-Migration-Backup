# PHP 7.4 validation — 2026-09-30

Host: macOS; container image `php:7.4-cli` = PHP 7.4.33.
Checkout mounted **read-only**; the worktree's own `vendor/` is never
mutated. PHPUnit 9.6.37 (constraint `>=7.3`) runs directly.

## Lint (shipped + changed sources)

    docker run --rm -v "$PWD:/w:ro" php:7.4-cli \
      bash -c 'for f in includes/Migration/JobRunner.php \
        includes/Rollback/ImportJournal.php includes/Support/ErrorCode.php \
        tests/Contract/WordPressJobRunnerTest.php tests/Unit/ImportJournalTest.php; \
        do php -l "$f"; done'

Result: `php=7.4.33 lint_fail=0`.

## Full PHPUnit suite (isolated vendor)

The only PHP 8.x-only syntax in the dependency tree is
`doctrine/instantiator` (typed class constants + trailing comma in a
parameter list) — the same blocker the 2026-09-29 report hit. To run the
suite without touching the checkout's `vendor/`:

1. `cp -R vendor /tmp/php74-vendor` (throwaway copy outside the repo).
2. Patch only the copy: `private const string` -> `private const`
   (2 lines) and drop the trailing comma before `): self` in
   `UnexpectedValueException`. Both files then `php -l` clean on 7.4.
3. Mount the patched copy over a read-only checkout:

    docker run --rm -v "$PWD:/w:ro" -v /tmp/php74-vendor:/w/vendor \
      -w /w php:7.4-cli php -d memory_limit=1G \
      vendor/bin/phpunit --no-coverage

Result:

    OK (553 tests, 2510 assertions)
    Time: 00:03.433, Memory: 101.02 MB

The `file_put_contents(.phpunit.result.cache): Read-only file system`
warning is expected (read-only mount) and does not affect the result.
The plugin's own `includes/` and `tests/` are PHP 7.4 clean; only the
test-framework dependency needed the throwaway patch.

## Re-run after BUG-03 + task 3 (final branch state)

Same isolated setup, final validation source
at the task-3 commit (560 tests incl. the new
`InventoryDriftGuardTest` and the two PK-less limitation pins):

    OK (560 tests, 2558 assertions)
    Time: 00:03.756, Memory: 63.14 MB

`php -l` on 7.4.33 for every changed shipped file (`Exporter.php`,
`JobRunner.php`, `ImportJournal.php`, `AdminPage.php`): no syntax
errors.
