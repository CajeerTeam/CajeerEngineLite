# Production Stable — базовая приёмка CMS

Механизм введён в 1.5.0, применяется к актуальному runtime; для 1.6.0 дополнительно обязателен [GA gate](GITHUB_AUTOMATIC_UPDATES_GA.md). CMS-only sign-off не подтверждает автоматические обновления GA.

## Подписанные артефакты

Исторический официальный переход 1.5.0 использует v1.4.8 как последнюю Stable-базу; для 1.4.9 требуется отдельно подписанный exact-base переход или проверенный ручной patch. Для 1.6.0 см. [RELEASE](RELEASE.md). Храните весь комплект и независимо закреплённый публичный ключ вне проекта, недоступными для изменения web worker. Не используйте временный тестовый ключ для публикации.

`release-report.json` содержит SHA-256 каждого runtime-файла (VERSION, bootstrap, router, CLI, core, public, templates). Gate сверяет подпись, артефакты, пакет и фактическое локальное дерево; дополнительные или локально изменённые runtime-файлы вызывают отказ. Отдельно согласуйте и подпишите собственную сборку либо используйте неизменённый runtime. HTTP version header не является удалённой аттестацией всего кода. Запускайте проверки на установленном сервере с актуальными данными и окружением FPM.

## Автоматические проверки

Следуйте [INSTALL](INSTALL.md), [UPGRADE](UPGRADE.md), [BACKUP_RECOVERY](BACKUP_RECOVERY.md). Репетируйте точные переход и rollback на off-host копии в изоляции; проверьте CI (PHP 8.4/8.5), Chromium/Firefox/WebKit, web-server и провайдеров. После развёртывания создайте новый проверенный CMS backup. Для live gate требуется не менее 20 сохранённых измерений за час, последнее — не старше пяти минут, без некорректных записей и с соблюдением настроенных порогов. Телеметрия не заменяет внешний мониторинг и нагрузочные тесты.

```bash
export CAJEER_UPDATE_TRUST_POLICY_FILE=/etc/cajeer/update-trust.json
php bin/cajeer release production-check --artifacts=/srv/releases/1.6.0 --json
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE php bin/cajeer release production-check \
  --artifacts=/srv/releases/1.6.0 --live --json
```

Используйте подходящую к версии trust policy. Exit 1 — ошибка/отказ, 2 — автоматические проверки прошли, ручная приёмка ожидается, 0 — записан sign-off. Локальные проверки не обращаются к провайдерам. Для live укажите `--provider=NAME` каждого настроенного провайдера из `required_providers`; платный AI требует `--paid` и `CAJEER_AI_PAID_PROBE_CONFIRM=I_ACCEPT_API_CHARGES`. Clarity может расходовать квоту. Реальные browser tracking/consent, GTM, OIDC login/logout, доставка событий и billing проверяются отдельно, если применимы. Неподключённые провайдеры отметьте N/A с причиной, а не passed. Не используйте искусственный TLS trust на публичном сервере.

## Доказательства оператора

Создайте приватный каталог вне `public/` и заполните реальными результатами восемь файлов:

| Файл | Содержание |
| :--- | :--- |
| `ci.txt` | CI, тег, commit, результат |
| `browser_e2e.txt` | Фактическая навигация и формы |
| `staging_fpm_tls.txt` | Реальный PHP-FPM и TLS |
| `providers_and_consent.txt` | Подключённые провайдеры, согласие, обоснованные N/A |
| `offhost_restore.txt` | Восстановление независимой копии |
| `upgrade_rollback.txt` | Проверенный exact-base переход и откат |
| `load_soak.txt` | Нагрузка, продолжительность, пороги и измерения |
| `production_observation.txt` | Наблюдение после развёртывания |

В каждом файле — среда, дата, тег/commit, digest артефакта, фактические результаты и ссылки на сохранённые доказательства. Устраните блокирующие ошибки до approval, удалите токены и персональные данные. Не фабрикуйте evidence.

В `approval.json` заполните `reviewer` (идентификатор человека), `decision` (`approve`), `version` (точная установленная версия), `receipt_sha256`, `site_sha256`, `origin_sha256` из свежего live-отчёта (`artifact.receipt_sha256`, `site_sha256`, `origin_sha256`), `evidence` (объект: имя каждого файла без `.txt` → его реальный SHA-256). Для GA добавьте ещё три файла и два digest согласно GA runbook. Вычисляйте хеши через `sha256sum`.

```bash
CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE \
CAJEER_PRODUCTION_SIGNOFF_CONFIRM=I_REVIEWED_PRODUCTION_EVIDENCE \
php bin/cajeer release sign-off --artifacts=/srv/releases/1.6.0 \
  --live --evidence=/secure/review --json
```

Для GA используйте `ga-sign-off` и его расширенный evidence. Передайте те же provider flags. Sign-off повторяет live gate и проверяет хеши; при изменении site/runtime или устаревшем отчёте требуется новый review. Приостановите редактирование контента на время приёмки. Финальная запись под эксклюзивными locks создаётся атомарно и без перезаписи в `storage/app/release-signoff/<id>.json`. `operator_attested` — свидетельство оператора, не криптографически подписанное заключение и не независимая проверка личности/текста. Экспортируйте evidence и receipt в защищённый аудит-архив. При изменении развёртывания повторите приёмку.

## Изолированная проверка базового механизма

```bash
python3 tests/production_stable_150.py
python3 tests/production_stable_150.py --base /path/to/clean/previous/source
```

Тест использует временные RSA-ключи, локальный HTTPS/Nginx, содержимое, backup/restore, gate и тестовый sign-off; проверяет отказ при подмене данных, runtime и отсутствии согласия. Не подтверждает фактический production FPM, провайдеров, долгую нагрузку или человеческую приёмку. Не сохраняйте тестовый approval как реальный.
