# Threat Model - MUDRAVA Migration & Backup

Дата: 2026-09-25. Плагин по определению получает права читать почти весь сайт,
экспортировать БД и перезаписывать destination. Это high-privilege софт.

## Assets

1. Содержимое сайта клиентов (PII, заказы, учётки) - в БД и файлах.
2. Пароли архивов и производные ключи.
3. Сессии/токены админов WordPress.
4. Credentials хостинга/облака (Pro: S3, SFTP; pairing secrets direct transfer).
5. Целостность destination-сайта (restore перезаписывает всё).
6. Репутация MUDRAVA (один CVE = конец доверия в migration-нише).

## Attack surfaces & mitigations

### A. Злонамеренный архив (import-путь) - главный вектор

| Угроза | Mitigation |
|:--|:--|
| Path traversal `../../wp-config.php` | `PathGuard`: normalized relative POSIX only; realpath-проверка внутри destination root; reject `..`, absolute, `C:\`, null bytes |
| Absolute/symlink escape | Symlinks не восстанавливаются по умолчанию; opt-in только с target внутри root |
| Corrupt frames (bit flip, huge lengths) | Per-frame CRC32 + AEAD tag; `stored_len` ограничен разумным максимумом до allocation; streaming, никогда не аллоцируем по attack-header |
| Zip-bomb стиль (logical >> stored) | Декомпрессия в чанки с лимитом logical_len; лимиты на ratio; disk preflight |
| PHP object injection через serialized payload | URL rewriter десериализует только ожидаемые option/meta значения, `allowed_classes:false` при разборе; исходные байты сохраняются, если не меняются |
| SQL injection через имена таблиц/схему | Имена таблиц валидируются `^[A-Za-z0-9_]+$`; значения только через prepared statements |
| Поддельный manifest/root_hash | Manifest сверяется пересчётом цепи; несоответствие = `MUDRAVA_MANIFEST_MISMATCH`, restore останавливается |

### B. Неавторизованный доступ к admin/REST

| Угроза | Mitigation |
|:--|:--|
| CSRF на export/import | Nonce на все state-changing запросы; REST permission callbacks; capability `mudrava_migrate` (map: manage_options) |
| Subscriber/author двигает job | Все эндпоинты требуют capability; owner-проверка job UUID |
| Brute-force pairing (Pro direct) | Short-lived pairing secret, rate limit, constant-time compare, expiry, replay protection (session UUID + sequence ACK) |
| SSRF через remote URL (Pro) | Allow/deny list, deny private/link-local ranges, HTTPS default, без redirect-following в доверенные сети |

### C. Утечки секретов

| Угроза | Mitigation |
|:--|:--|
| Пароль архива в логах/option/URL | Пароль живёт только в памяти запроса; никогда в option/transient/log/telemetry/localStorage; POST-only, `noindex` |
| Hint выдаёт пароль | UI явно: «Anyone who has this archive can read the password hint»; проверка на совпадение с паролем с подсказкой |
| Токены в диагностике | Diagnostic bundle redacts Authorization, cookies, DB creds, AWS/SFTP keys, salts, license keys |
| Developer забывает redaction | Тесты: logger с маркером-секретом не должен оставлять его ни в одной output-строке |

### D. Криптография

- Никакой самодеятельной криптографии. Только libsodium
  (`crypto_pwhash` Argon2id + `crypto_aead_xchacha20poly1305_ietf`) или
  OpenSSL AES-256-GCM fallback.
- Nonce = `prefix || sequence` - уникален в рамках archive (sequence монотонен).
- Ключ не кэшируется на диске. Between-request resume пересобирает ключ из
  пароля, введённого в сессии браузера (session-scoped, memory only).
- Crypto capability отсутствует → явное сообщение + opt-in unencrypted.
  Silent downgrade запрещён и покрыт тестом.

### E. DoS / ресурсные атаки

- Bounded memory по контракту; лимиты на размер фрейма/батча.
- Job lock с TTL: параллельные запуски одного archive → очередь/отказ.
- Rate limit REST-тикков; backoff на 429/503.
- Disk full → checkpoint + `MUDRAVA_DISK_FULL`, никогда silent partial success.

### F. Supply chain / WP.org compliance

- GPL-совместимые лицензии всех ассетов; никакой obfuscation.
- Никакого executable code из внешних серверов (WP.org guideline 8).
- Tracking opt-in (guideline 7). Premium - только add-on вне directory.
- Зависимости: `composer audit` + `npm audit` в CI; пин версий.

### G. Инсайдер/агент (Copilot workflow)

- В репозиторий не коммитятся: `.env`, cookies, storage state с реальными
  аккаунтами, архивы клиентов, реальные токены, sudo-пароли.
- Playwright MCP использует существующую браузерную сессию; извлечение/печать
  токенов запрещена.
- Прод-сайты: никаких разрушающих операций; только dedicated test sites.

## STRIDE-сводка горячих точек

| Категория | Горячая точка | Контроль |
|:--|:--|:--|
| Spoofing | Pairing/import без подтверждения | Capability + pairing approval + short TTL |
| Tampering | Архив в transit/at rest | AEAD + root hash chain + golden tests |
| Repudiation | «Плагин всё удалил» | Audit log операций (без секретов), restore point |
| Information Disclosure | Экспорт чужого сайта, утечка пароля | Capability, redaction, password-never-stored |
| DoS | Гигантские батчи, параллельные jobs | Bounded sizes, locks, rate limits |
| Elevation | REST без capability, SQL без prepare | Permission callbacks, prepared statements, WPCS в CI |

## Security review gate

Перед релизом: external security review (бюджет $5k–20k+), secret scan,
malicious archive suite, dependency audit. Миграционный плагин получает
права на весь сайт - на этом не экономят.
