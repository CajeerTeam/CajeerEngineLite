# GitHub Automatic Updates GA

**1.6.0 · Руководство оператора**

Релиз реализует автоматические обновления Stable. Схема JSON 14 и формат подписанного `.cjrupdate` не изменились. Supervisor, установленный от root вне дерева CMS, обновляется отдельно. Локальные тесты не заменяют production sign-off.

## 1. Публикация

Следуйте [RELEASE](RELEASE.md), [UPDATE_DISTRIBUTION](UPDATE_DISTRIBUTION.md) и [RELEASE_AUTOMATION_MONITORING](RELEASE_AUTOMATION_MONITORING.md). Настройте защищённые теги и окружения подписи/публикации с обязательными reviewer, официальные RSA-ключи и права репозитория. Создайте `v1.6.0` с VERSION 1.6.0. Workflow выбирает предыдущую опубликованную Stable-базу, требует точный исходный тег 1.5.9 даже для неопубликованного Preview и добавляет 1.5.7/1.5.8 при наличии. Все базы должны быть предками целевого коммита. При ручном запуске можно указать дополнительные `extra_base_tags`; RC-пакет всё равно обязателен. Не подделывайте теги.

Публичный релиз не должен быть draft или prerelease. В состав входят основной и дополнительные `.cjrupdate`, `update-index.json`, полный ZIP, `release-report.json`, `SHA256SUMS`, `SHA256SUMS.sig`. Workflow скачивает и проверяет draft до публикации. Храните независимо проверенный комплект в отдельном операторском каталоге без лишних файлов. Архив изменённых исходников не является официальным подписанным дистрибутивом.

## 2. Первичная настройка supervisor

1. Выполните [установку](INSTALL.md): PHP 8.4.1+, локальное хранилище, отдельная непривилегированная учётная запись, PHP-FPM, systemd, bash, curl и Python 3; web root только `public/`.
2. Остановите таймеры, worker и FPM; разрешите активные транзакции, сделайте независимые копии кода, storage, секретов, ключей и конфигурации. Примените проверенный переход с 1.5.9 (для другой базы — соответствующий exact-base). Не накладывайте файлы при работающем PHP.
3. По [оркестрации](MAINTENANCE_ORCHESTRATION.md), [health/rollback](AUTOMATIC_HEALTH_ROLLBACK.md) и [trust policy](UPDATE_SECURITY_HARDENING.md) установите проверенный `deploy/cajeer-maintenance.sh` в `/usr/local/sbin/cajeer-maintenance` (root:root 0755), service, timer, guards, приватный loopback HTTPS listener и публичный HTTP 503 gate. Не меняйте supervisor во время обслуживания. Старый supervisor не умеет читать `installing`.
4. Подготовьте root-owned `/etc/cajeer/maintenance.env` (0600): canonical site root, FPM unit, site user, фоновые units, официальный репозиторий, managed trust, health host/port и при необходимости CA. Код и update transactions должны быть read-only для HTTP workers. Выключите прежние cron/scheduler services.
5. Сначала задайте `CAJEER_AUTO_UPDATE_ENABLED=0`, `CAJEER_AUTO_UPDATE_INSTALL=0`, `CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL=0`. Настройте совпадающие корректные окна UTC в окружении и `/admin/updates`. Включите проверку Stable и проверьте candidate без установки. Только после staging-приёмки включите оба системных opt-in (`=1`) и автоустановку Stable в интерфейсе. Preview оставьте выключенным. HTTP не управляет службами; не подтверждайте `WORKERS_QUIESCED=1` при работающих процессах.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cajeer-maintenance.timer
sudo systemctl start cajeer-maintenance.service
systemctl list-timers cajeer-maintenance.timer
journalctl -u cajeer-maintenance.service -n 100
```

## 3. Preflight реального сервера

Запустите root preflight с окружением службы. Он удерживает блокировку supervisor, проверяет units/guards, активный FPM, включённый timer, установленную копию supervisor, managed trust, loopback TLS и мониторинг. Он не устанавливает пакет и не останавливает службы. Повторите при занятой блокировке.

```bash
sudo systemd-run --wait --pipe --collect \
  --property=EnvironmentFile=/etc/cajeer/maintenance.env \
  /usr/local/sbin/cajeer-maintenance preflight
```

## 4. Проверка GA

От непривилегированного update user с тем же доверенным окружением GitHub и policy выполните:

```bash
php bin/cajeer release ga-check --artifacts=/srv/releases/1.6.0 --json
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE php bin/cajeer release ga-check \
  --artifacts=/srv/releases/1.6.0 --live --json
```

Локальный gate проверяет подписанный runtime, индекс и RC-пакет; проблемы конфигурации обновлений до live-режима могут быть предупреждениями. Для live явно добавьте `--provider=NAME` для каждого настроенного API-провайдера из `required_providers`; для платного AI — `--paid` и `CAJEER_AI_PAID_PROBE_CONFIRM=I_ACCEPT_API_CHARGES`. Не запускайте платные проверки без согласия. См. [Production Stable](PRODUCTION_STABLE.md).

Live GA делает свежий запрос точного тега GitHub без кэша, отклоняет draft/Preview, лишние и отсутствующие assets, неправильные URL и изменённые digests. Все опубликованные файлы скачиваются потоково и сравниваются с независимо проверенным комплектом (общий бюджет 256 МБ). Проверяются managed trust, Stable opt-in, UTC-окно, свежий завершённый scheduler heartbeat, успешная свежая Stable-проверка, отсутствие pending work/hold и предупреждений или ошибок мониторинга. Сбой сети означает отказ gate, а не право установки по старому кэшу. Временные загрузки удаляются; gate не ставит задачи и не устанавливает код. Отсутствие более нового релиза допустимо, но не доказывает репетицию обновления.

## 5. Восстановление и доказательства

На изолированном staging выполните реальный GitHub-переход с предыдущей Stable и 1.5.9 через systemd/FPM/TLS. Зафиксируйте base/target, хеш пакета, 503 gate, смену PID, readiness, очередь, медиа и backup integrity. Проверьте повреждённый пакет, отзыв ключа, сетевой сбой, прерывание supervisor, перезагрузку и staging-only подписанный неисправный fixture. Не публикуйте плохой fixture как официальный. Проведите off-host restore, CI/browser matrix, проверки провайдеров, наблюдение и длительную нагрузку.

`installing` сохраняется до запуска worker. При неопределённой завершённой транзакции новый runtime откатывается даже при рабочем HTTPS; неизменившаяся база восстанавливается без отката постороннего receipt. Если rollback или readiness прежней версии не проходит, публичный gate остаётся закрытым. Не удаляйте активные журналы и не снимайте hold до устранения причины.

### Операторская приёмка

Создайте доказательства по [Production Stable](PRODUCTION_STABLE.md) и дополнительно `github_update_e2e.txt`, `systemd_reboot_recovery.txt`, `supervisor_preflight.txt`. В `approval.json` включите все 11 SHA-256 доказательств (ключи без `.txt`), а также `distribution_index_sha256` и `publication_sha256` из `updates_ga.distribution_index_sha256` и `updates_ga.publication.publication_sha256` свежего live-отчёта. Sign-off повторно выполняет live gate, отклоняет изменившиеся revision, trust, site или runtime и создаёт приватную запись. Доказательства и личность reviewer подтверждаются оператором, а не независимо проверяются программой. CMS-only `production-check`/`sign-off` не подтверждают GA.

```bash
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE \
CAJEER_PRODUCTION_SIGNOFF_CONFIRM=I_REVIEWED_PRODUCTION_EVIDENCE \
php bin/cajeer release ga-sign-off --artifacts=/srv/releases/1.6.0 \
  --live --evidence=/secure/ga-review --json
```

Передайте те же provider flags, что для live gate, если они требуются.

| Код | Значение |
| :---: | :--- |
| 0 | Записан операторский sign-off в `storage/app/release-signoff/` |
| 1 | Ошибка или отказ gate |
| 2 | Автоматические проверки пройдены; live/ручное принятие ожидается |

Продолжайте `php bin/cajeer update monitor check` и `php bin/cajeer monitor check --verify-backup`; при отказе условий безопасности отключайте автоустановку.

## 6. Изолированные тесты

```bash
python3 tests/release_ga_160.py
bash tests/maintenance_orchestration_155.sh
bash tests/maintenance_units_155.sh
php tests/core_157.php
php tests/core_158.php
```

`release_ga_160.py --base /clean/stable --rc /clean/1.5.9` использует точные исходные базы. Тесты создают временные ключи, выполняют установку/откат и проверяют streaming transport с контролируемыми metadata и bytes. CI также проверяет очередь с локальным TLS/Nginx и симулированными GitHub/systemd. Ни один тест не публикует официальный релиз и не подтверждает production.
