# Test Plan - MUDRAVA Migration & Backup

## Definition of «полностью покрыт»

> Каждая публичная функция, состояние state machine, format record,
> failure/recovery transition и поддерживаемый environment class имеют
> автоматизированный test contract.

Манипулировать coverage ради цифр запрещено. Исключения документируются.

## Цели

```text
Critical core (format/parser/state machine): 100% meaningful branch coverage
Overall PHP: >= 95% line, >= 90% branch (engineering goals, not WP.org gates)
Regression bugs: 100% требуют постоянный regression test
Release: zero known P0/P1, zero unexplained flaky tests
```

## Пирамида

1. **Unit** (PHPUnit): framing, codec, crypto, path guard, row encoding,
   URL rewrite (serialized/JSON/plain), checkpointing, error mapping.
2. **Contract**: `BackupSink`/`BackupSource` implementations против одного
   набора contract-тестов.
3. **Golden archives** (`tests/GoldenArchives/`): бинарные эталонные архивы
   каждой опубликованной версии формата; reader обязан читать старые.
4. **Fuzz/property**: случайные байты в frames, обрывы в произвольных точках,
   перестановки частей, mutation-тесты манифеста. Инварианты: reader либо
   даёт корректный результат, либо детерминированную ошибку. Никаких fatal.
5. **Integration** (Docker WP matrix): реальные миграции source→target.
6. **E2E** (Playwright Test): UI export→download→upload→import→verify.
7. **Performance/benchmark**: bounded RAM, throughput, 100 GB…2 TB evidence.

## Acceptance matrix (обязательные кейсы)

| Case | Expected |
|:--|:--|
| Fresh → fresh | canonical content equal |
| Existing → overwrite | старое корректно заменено |
| Same domain clone | no corruption |
| Domain A → B | URLs + serialized корректны |
| HTTP → HTTPS | mixed references исправлены |
| `/subdir` → `/` | paths/URLs correct |
| Custom DB prefix | полный restore |
| Serialized PHP / nested | deserialize succeeds, длины целые |
| JSON values | valid JSON |
| BLOB/binary | byte equality |
| Unicode/emoji/uk/ka/ja | no encoding loss |
| WooCommerce-like dataset | integrity |
| ACF/builder-like meta | integrity |
| Single file > 4 GB | streaming works |
| Millions-of-files profile | bounded RAM |
| 100 GB / 500 GB / 1 TB | bounded RAM, verified |
| 2 TB benchmark | deferred; required before claiming tested 2 TB support |
| 64/128 MB PHP memory | no unbounded memory |
| Short request timeout | resume |
| `upload_max_filesize=2M` | chunk route работает |
| Disabled `exec` | pure-PHP путь |
| No symlink permission | graceful |
| Custom `wp-content` | correct mapping |
| Permission failure | preflight fail safely |
| Disk full export/import | checkpoint + явная ошибка, no silent partial |
| Browser closed mid-transfer | resumes |
| PHP worker killed | resumes from durable checkpoint |
| DB connection lost mid-batch | safe retry |
| TCP reset / 429 / 503 / 504 | bounded retry/backoff/resume |
| Corrupted frame | integrity failure |
| Truncated archive | детерминированный диагноз + valid prefix |
| Wrong password | чистое отвержение |
| Missing split part | названа точная часть |
| Reordered/duplicate parts | реконструкция/idempotent skip |
| Traversal/absolute/unsafe symlink | blocked |
| Restore twice | детерминированно |
| Export→import→export | semantic canonical equality |
| Concurrent migration | lock/queue policy |
| WP cron absent | browser runner двигает job |
| Object cache (Redis-like) | caches invalidated |

## Fault injection (docker/fault-injection/)

```text
latency, bandwidth throttle, TCP reset, 429, 500, 502, 503, 504,
partial request/response body, DNS failure, DB disconnect, process kill,
disk full, read-only filesystem
```

Resumability-тест не «мокает success»: реально `docker kill` контейнер на
frame >= X, рестарт, reconnect UI, Resume, финальная canonical-сверка.

## Fixtures (детерминированный генератор `bin/make-fixture.php`)

```text
fixture-small        100 posts, 100 media
fixture-serialized   nested arrays/objects/URLs
fixture-unicode      українська, ქართული, 日本語, emoji
fixture-commerce     products/orders/users/custom tables
fixture-files        tiny, many-files, incompressible, compressible, >4GB
fixture-dirty        broken permissions, symlinks, orphan tables, prefixes
```

Большие тесты: sparse fixtures для boundary + **actual-byte payload** для
честного throughput. Sparse 2 TB сам по себе ничего не доказывает.

## Semantic verifier (финал каждой миграции)

```text
tables count, rows count, schema fingerprint, selected row hashes,
file count, total logical bytes, file hashes, critical WP options,
plugin/theme set, home/siteurl, media sample requests,
front-end health, wp-admin health, REST health
```

Нормализуются перед сравнением: archive UUID, timestamps, destination URL,
transients, sessions, environment paths. Затем canonical snapshot diff = пуст.

## CI pipeline

```text
composer validate → npm ci → PHP syntax → PHPCS (WPCS) → PHPStan
→ unit → golden format → fuzz/property → WP integration matrix
→ Docker source→target migrations → Playwright E2E → Plugin Check
→ dist reproducibility → security scan → release candidate
```

Matrix: PHP 7.4/8.0/8.1/8.2/8.3/8.4 × MariaDB 10.11/current × MySQL 8.0/current.
Recommended runtime: PHP 8.3+, MariaDB 10.11+/MySQL 8.0+ (актуальная WP
recommendation, проверено 2026-09-25). Legacy - для installed base.

## Ритм

- PR: полная быстрая пирамида.
- Nightly: fixtures S/M, fault matrix, kill/restart matrix, DB matrix, corruption matrix.
- Weekly: 10–100 GB actual-byte, real shared hosts, cloud backends.
- Extended scale validation: 500 GB / 1 TB / 2 TB end-to-end + evidence report
  (hardware, PHP, DB, FS, bytes, duration, throughput, peak RAM, retries, integrity).

## Playwright

- MCP (реальный браузер, extension mode) - exploratory, authenticated real-host
  discovery. Каждый найденный баг → коммитный Playwright/PHP regression test.
- Playwright Test - deterministic CI suite (chromium основной, firefox/webkit
  периодически). Пин: 1.63.0 + `mcr.microsoft.com/playwright:v1.63.0-noble`,
  `ipc: host`.

## Реальные хосты (compatibility lab)

| Класс | Кол-во |
|:--|--:|
| very-low-resource shared | 2+ |
| cPanel shared | 2+ |
| managed WordPress | 2+ |
| budget VPS | 2 |
| Nginx VPS / Apache VPS | 1 / 1 |
| large dedicated | 1 |
| object-storage | 1 |
| multisite | 1 |

Только в рамках Terms/AUP провайдеров. Для каждого реального сбоя: safe
diagnostics → локальное воспроизведение → regression test → fix → matrix rerun.
