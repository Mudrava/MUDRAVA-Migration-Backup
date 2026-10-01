# WordPress.org Compliance - MUDRAVA Migration & Backup

Проверено по официальным источникам 2026-09-30 (браузер):
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- https://wordpress.org/about/requirements/
- https://wordpress.org/news/ (текущая версия: WordPress 7.1.2)

## Compliance matrix

| Требование (проверено в источнике) | Как выполняем |
|:--|:--|
| GPL или GPL-compatible (guideline 1, «GPLv2 or later strongly recommended») | `GPL-2.0-or-later`, LICENSE в репозитории |
| Все code/data/images GPL-совместимы, включая third-party | Аудит лицензий зависимостей в CI |
| Human-readable source, без obfuscation (guideline 4) | Никакой obfuscation/uglify-mangle без исходников; build-инструкции для minified JS/CSS |
| Trialware запрещён (guideline 5: «may not contain functionality that is restricted or locked, only to be made available by payment»; запрет disable после trial/quota) | Free никогда не истекает; premium-кода в Free нет вообще; Pro = add-on вне directory (прямо рекомендовано guidelines) |
| Executable code через third-party запрещён (guideline 8: запрет установки premium-версий/плагинов с чужих серверов) | Free не качает исполняемый код извне; обновления только через WP.org |
| Tracking только с consent (guideline 7, документирование в readme) | Free не отправляет телеметрию и не подключается к внешним сервисам; privacy.md |
| Admin advertising минимально (guideline 11) | Первая отправленная Free сборка не содержит экранов Pro и рекламы |
| Без front-end backlinks/affiliate spam (guideline 10) | Никаких auto-ссылок |
| Теги: максимум 5, competitor tags запрещены, keyword stuffing запрещён (проверено в тексте guidelines) | Ровно 5: `migration, backup, site transfer, restore, clone` |
| Readme metadata (Requires at least, Tested up to, Stable tag, Requires PHP, License) | readme.txt ниже |
| Stable release хранится на WP.org; SVN = release repository, не dev | GitHub dev → SVN trunk+tags только релизами |
| Security: capabilities, nonces, sanitization, escaping | См. threat-model.md; CI: WPCS + Plugin Check |
| File uploads use the WordPress uploader (Common issues, Files) | REST route checks migration capability; `wp_handle_upload()` writes binary chunks into private staging, and assembled archives are validated before restore |
| i18n: text domain = slug | `mudrava-migration-backup` |
| Uninstall не уничтожает молча пользовательские данные | uninstall.php удаляет настройки; user archives НЕ трогает |

## Требования рантайма (wordpress.org/about/requirements, 2026-09-25)

- Recommended: PHP **8.3+**, MariaDB **10.11+** или MySQL **8.0+**
- Legacy minimum: PHP 7.4+, MySQL 5.5.5+ (EOL, с предупреждением)
- Плагин: `Requires PHP: 7.4` (максимальный охват installed base),
  recommended-матрица CI - PHP 8.3+. `Tested up to: 7.1` (WP 7.1.2).

## readme.txt (шаблон релиза)

```text
=== MUDRAVA Migration & Backup ===
Contributors: mudrava
Tags: migration, backup, site transfer, restore, clone
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move, back up and restore WordPress sites with one portable .mudrava file,
streaming transfers, encryption and resumable imports.
```

## Publisher identity

Профиль `@mudrava` (проверен 2026-09-25): «IT Boutique / Plugin Development
Team at MUDRAVA», joined Feb 2026, сайт mudrava.com/en, GitHub mudravadev.
Существующие плагины: Mudrava Icon Field for ACF with Lucide, MUDRAVA RUM,
MUDRAVA Admin Tweaks. Новый продукт использует ту же identity.

## Release flow

До одобрения: завершенный Free ZIP, Plugin Check, тесты, повторная загрузка
исправленного ZIP в заявку и короткий ответ в существующей переписке с ревьюером.
После одобрения: один стабильный релиз в WordPress.org SVN, затем assets и
проверка установки из каталога. Публичный GitHub остается с одной веткой main,
одним начальным коммитом и одним актуальным GitHub Release по выбору владельца.

Стабильная версия сверяется между PHP заголовком, readme Stable tag,
установочным ZIP и будущим WordPress.org SVN tag. GitHub review candidate tag
может содержать суффикс rc до одобрения.

Plugin Check (`wp plugin check mudrava-migration-backup`) - mandatory CI gate,
но manual review он не заменяет (официальное предупреждение Plugin Check docs).

## Запрещено (напоминалка для контрибьюторов)

- Дедлайнить Free по размеру/времени/квоте.
- Тащить названия конкурентов в tags/readme.
- Превращать readme в sales pitch.
- Коммитить в SVN dev-историю.
- Любые внешние телеметрия/обновления premium-кода из Free.
