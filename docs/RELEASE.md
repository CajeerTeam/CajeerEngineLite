# Релизный процесс

**CajeerEngine Lite 1.6.0 · Подписанная дистрибуция**

## Состав официального релиза

| Артефакт | Назначение |
| :--- | :--- |
| `CajeerEngineLite-1.6.0.zip` | Полная чистая установка |
| `CajeerEngineLite-1.6.0.cjrupdate` | Основной подписанный пакет с точной Stable-базой |
| `CajeerEngineLite-1.5.9-to-1.6.0.cjrupdate` | Обязательный дополнительный RC-переход |
| Дополнительные `.cjrupdate` | Поддержанные точные базы, включая 1.5.7/1.5.8 при наличии тегов |
| `update-index.json` | Подписанный индекс пакетов |
| `release-report.json` | Отчёт сборки и репетиции, хеши runtime |
| `SHA256SUMS`, `SHA256SUMS.sig` | Подписанный основной список контрольных сумм |

> Архив изменённых исходников — не официальный ZIP и не подписанный пакет. Отчёт сборки не подтверждает production: `production_accepted: false`.

## Сборка и проверка

Используйте чистые исходники точных тегов. `VERSION` должен совпадать с `Lite::VERSION`; базы — предки цели, а файлы соответствуют реально установленным версиям. Не изменяйте VERSION ради искусственного перехода. Пример (при наличии экспортов и доверенных ключей):

```bash
python3 tests/release_distribution_152.py \
  --base /srv/src/stable \
  --extra-base /srv/src/1.5.9 \
  --candidate /srv/src/1.6.0 \
  --output /srv/releases/1.6.0 \
  --signing-key /secure/release-private.pem \
  --trusted-key /secure/release-trusted.pem
python3 tests/release_distribution_152.py --verify /srv/releases/1.6.0 \
  --target 1.6.0 --trusted-key /secure/release-trusted.pem
```

Добавьте `--extra-base` для доступных точных тегов 1.5.7/1.5.8 (без дублирования основной базы). Output не должен существовать. Работайте на изолированном build runner без живых provider credentials. Приватный ключ вне исходников, 0600; доверенный публичный ключ получен независимо. Python 3.11+ и OpenSSL CLI нужны сборке. Подробности: [Distribution](UPDATE_DISTRIBUTION.md), [Update Engine](UPDATE_ENGINE.md).

Сборщик сравнивает manifest и хеши с фактическим diff, отказывает при неподдерживаемых runtime-путях, строит нормализованный ZIP, проверяет CRC/inventory, чистую установку, HTTP readiness, home, robots/sitemap. Для каждой базы репетирует установку, сравнение кода и содержимого, откат, отказ повреждённого пакета. Подписывает SHA-256; отдельно проверяет индекс и дополнительные пакеты. `docs/` входят в полный ZIP, но не управляются code-only updater. Репетиции не изменяют production. Воспроизводимость зависит от идентичных исходников, ключа, режимов файлов и инструментов; версии zlib могут менять ZIP.

```bash
cd /srv/releases/1.6.0
openssl dgst -sha256 -verify /secure/release-trusted.pem -signature SHA256SUMS.sig SHA256SUMS
sha256sum --strict -c SHA256SUMS
```

## GitHub Actions

1. Настройте защиту неизменяемых `vX.Y.Z` тегов и ограничение редактирования workflow; окружения `release-signing` и `release-publish` с обязательным review и ограничением protected tags.
2. Secret `CAJEER_RELEASE_PRIVATE_KEY` (PEM) — только в signing; variable `CAJEER_RELEASE_PUBLIC_KEY` — независимо закреплённый публичный PEM для обоих окружений. Проверяйте исходники тега, старый updater и скрипты сборки: код в подписывающем job имеет доступ к ключу. Не давайте его PR/forks, используйте изолированный runner и проверенные версии actions.
3. После проверки исходников и staging создайте защищённый `v1.6.0`. Workflow `Signed Lite release` автоматически выбирает предыдущую опубликованную Stable-базу, требует точную 1.5.9 и добавляет доступные 1.5.7/1.5.8. При ручном запуске на теге укажите `base_tag` и при необходимости `extra_base_tags` через пробел. Проверяются версии и ancestry.
4. Workflow запускает полный CI и отдельную репетицию exact-tag с временным ключом. После разрешения подписывающего окружения собирает комплект; отдельный publication job с правами записи проверяет подпись, хеши, версии и неизменность тегов, создаёт draft, скачивает обратно assets и сверяет весь состав. Только затем публикует. Существующие релизы не перезаписываются; оставшийся draft после ошибки проверяется вручную.
5. `1.4.9` и `1.5.9` — prerelease, не latest. `1.6.0` — Stable; не обходите gates маркировкой RC как Stable.

## Критерии допуска

- Полный CI, exact-tag rehearsal, [production-приёмка](PRODUCTION_ACCEPTANCE.md), реальные FPM/systemd/TLS и внешние провайдеры (где подключены).
- Проверенные резервирование, откат, нагрузочная эксплуатация и записи результатов; `release ga-check` и `release ga-sign-off` по [GA runbook](GITHUB_AUTOMATIC_UPDATES_GA.md).
- Сохранить release notes, неизменяемые артефакты и историю доверенных ключей. Не публиковать синтетические тестовые ключи и данные.

Исторические детали: 1.5.0 официально строился с 1.4.8; для 1.4.9 требовался отдельный переход. `python3 tests/release_engineering_148.py` проверяет исходный механизм сборки; `tests/rc_staging_149.py` — RC/TLS. Для 1.6.0 используйте `python3 tests/release_ga_160.py` и актуальный workflow. Реальный GitHub API, credentials и разрешения окружений требуют проверки сопровождающим.
