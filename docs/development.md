# Development

## Requirements

- PHP 7.4+ (development runs on 8.x; the code floor is 7.4 and is enforced
  by PHPCompatibility in CI)
- Composer, Node 22+, Docker with Compose v2

## Setup

```bash
composer install
npm ci
```

## Checks

```bash
composer test          # PHPUnit (unit + contract + golden + fuzz)
composer lint          # PHPCS (PSR-12 + WP security sniffs + PHP 7.4 floor)
composer lint:fix      # PHPCBF autofix
composer phpstan       # PHPStan level 8
composer check         # all of the above
```

## Migration lab (Docker)

Two WordPress sites, one migration:

```bash
bin/lab-up.sh
# source  http://localhost:8081  (admin / test-only-password)
# target  http://localhost:8082  (admin / test-only-password)
```

The lab mounts this repo into both sites at
`wp-content/plugins/mudrava-migration-backup`, so code changes apply
immediately (no rebuild).

Run the deterministic E2E suite against the lab:

```bash
npx playwright test
```

Or inside the pinned Playwright container (matches CI exactly):

```bash
docker compose --profile e2e run --rm e2e
```

## Test layers

| Layer | Location | What it proves |
|---|---|---|
| Unit | `tests/Unit` | Format primitives: frames, header, root hash, codec, transformer, PathGuard |
| Contract | `tests/Contract` | Sink/Source/Target contracts + a full vertical migration (fake WP -> `.mudrava` -> fresh dest) with semantic verification |
| Golden | `tests/GoldenArchives` | Byte-stable archive fixture; format changes that break compatibility fail loudly |
| Fuzz | `tests/Fuzz` | Reader never crashes / never accepts corrupt frames silently |
| E2E | `tests/E2E` | Real WordPress pair in Docker: UI export -> download -> UI restore -> wp-cli verification |

Regenerate the golden fixture only with intent (it is a compatibility
contract): `php tests/GoldenArchives/regenerate.php` and commit the diff.

## Conventions

- PSR-12 formatting, PSR-4 namespaces (`Mudrava\Migration\*`).
- Every class file defines its own `MUDRAVA_MB_*` constants guard - the
  plugin ships its own autoloader; Composer is dev-only.
- Never suppress a failing test. Never blind-`str_replace` serialized data.
- Never log secrets; `Logger` redacts, tests assert it.
- Errors are stable codes (`MUDRAVA_*`); messages carry no filesystem paths.
