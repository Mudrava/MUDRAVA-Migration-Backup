# Historical product specification

This document records early requirements and positioning ideas. It does not
state the current release's supported features or measured limits. Current
product claims are in [README](../README.md), [compatibility](compatibility.md)
and [the 100 GiB test report](benchmark-100-gib.md). Future size targets,
comparative claims and Pro proposals below are not release guarantees.

# Product Specification - MUDRAVA Migration & Backup (Free)

## One-liner

> **One site. One `.mudrava` file. Move it anywhere.**

Экспортируйте весь WordPress-сайт в один переносимый файл, перенесите и
восстановите. Без ручной работы с SQL, uploads, themes и десятком архивов.

## Позиционирование

Не «ещё один backup plugin». Это **самый простой способ перенести
WordPress-сайт любого практически достижимого размера**.

- Primary: *The WordPress migration plugin built around one portable file -
  even when the site is huge.*
- Secondary: *Export once. Move anywhere. Resume instead of restarting.*
- Pro (будущее): *Automate migrations and backups without changing the simple
  one-file workflow.*

## Правильное обещание о размере

Нельзя обещать «гарантированно работает на любом хостинге при любом размере» -
физические лимиты диска/PHP/БД/хостера плагином не отменяются.

Корректная формулировка:

> **No artificial archive-size limit. Streaming architecture supports large
> sites within the available host resources.**

В Free **запрещено** ставить искусственный лимит (512 MB / 2 GB / 10 GB …).
Размер ограничивается реальными ресурсами окружения. Проверенный реальный
экспорт и импорт достиг 100 GiB; большие размеры нельзя рекламировать как
проверенные до отдельного бенчмарка.

## Ментальная модель UI

Пользователь видит **один Backup**, даже если под капотом 130 000 encrypted
frames и 13 физических частей.

```text
MUDRAVA
[ Export ]        [ Import ]

Recent backups
─────────────────────────────
my-site.com
Today, 14:28 • 31.4 GB • Encrypted
✓ Verified
```

Split в UI:

```text
my-site-2026-09-25
824.7 GB • Encrypted • 13 parts • Ready
```

Импортёр при нехватке части:

```text
12 of 13 parts found.
Missing: part 0009.
```

## Free - объём v1

- Полный single-site export / import / overwrite
- Один логический `.mudrava`
- Streaming с bounded memory
- Resumable (export/import/upload) после timeout/disconnect/kill
- Browser upload чанками (в обход `upload_max_filesize`)
- Integrity verification (root hash + per-frame CRC/AEAD)
- Опциональная password-защита (AEAD) + plaintext hint с явным предупреждением
- Опциональный split (OFF/AUTO/2/4/8 GB/custom)
- WordPress-aware URL/domain/path rewrite (serialized-safe, JSON-safe)
- Кастомные таблицы, BLOB/binary, Unicode/emoji
- Exclusions (файлы/таблицы)
- Preflight-диагностика хоста
- Безопасный базовый rollback/recovery
- Детерминизм после refresh/reconnect

## Pro - граница (отдельный add-on вне WP.org)

Монетизируем **автоматизацию и профессиональный workflow**, а не искусственные
удобства. Free остаётся полноценным продуктом (WP.org запрещает trialware).

Pro: scheduled backups, S3/S3-compatible/SFTP, direct site-to-site push/pull,
incremental/delta, final delta перед cutover, multisite, staging↔prod,
advanced WP-CLI, retention, agency fleet, team, white-label, priority support.

## Цены (гипотеза для валидации)

| Tier | $/год | Sites |
|:--|--:|--:|
| Free | 0 | unlimited manual |
| Personal Pro | 79 | 5 |
| Agency | 149 | 50 |
| Unlimited | 249 | unlimited |

Lifetime - НЕ запускать (бесконечная стоимость совместимости).
Цены не хардкодить глубоко в коде. Payment через абстракцию `CommerceProvider`
(Paddle / Lemon Squeezy - MoR; eligibility грузинского ИП проверить до коммита).

## Конверсия

Только в моменты естественного intent. После успешной миграции:

```text
Migration complete ✓
31.8 GB transferred • 0 integrity errors
Need this automatically every night?
[ Set up scheduled remote backups - Pro ]
```

Никаких popup «BUY PRO NOW». Никакого invasive tracking (WP.org требует opt-in).

## SEO / рост

- README сразу: «Move an entire WordPress site using one portable `.mudrava` file.»
- Comparison-страницы и host-landing - на mudrava.com, НЕ в WP.org tags.
- WP.org tags (ровно 5, без конкурентов): `migration, backup, site transfer, restore, clone`.
- Главный SEO-moat - реальные benchmark-страницы (100 GB @ 128 MB RAM,
  500 GB interrupted×20, 2 TB benchmark). Никогда не фабриковать цифры.

## Определение успеха миграции

Миграция успешна ТОЛЬКО когда: archive integrity passed + DB restore complete +
filesystem restore complete + URL/path transforms complete + finalize complete +
destination health checks passed.

«HTTP 200» ≠ успех. «Прогресс 100%» ≠ успех. Проверяем итоговый сайт.
