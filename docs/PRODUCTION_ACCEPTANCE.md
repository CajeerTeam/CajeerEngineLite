# Чек-лист production-приёмки

**1.6.0 · Для оператора развёртывания**

Отмечайте пункты только после фактического выполнения. Для CMS требуется [Production Stable](PRODUCTION_STABLE.md); для GA — [GitHub Automatic Updates GA](GITHUB_AUTOMATIC_UPDATES_GA.md). `release stable-check --json [--live]` — базовая проверка, не sign-off: exit 1 означает ошибку, exit 2 — проверки пройдены, человеческое принятие ожидается.

## Подготовка

- [ ] Записаны версия, схема, PHP, canonical origin, настройки TLS/proxy, ключи доверия и точный base/target.
- [ ] Проверены SHA256SUMS, подпись, полный состав и индекс официального релиза.
- [ ] Независимая off-host копия кода, шаблонов, storage, конфигурации и секретов проверена; CMS TAR создан и проверен отдельно.
- [ ] FPM имеет доступ только для чтения к коду, приватное storage защищено (0700/0600), web root — только public/.
- [ ] Окно обслуживания согласовано, writers остановлены перед изменениями, определены условия rollback.

## Изолированный staging

- [ ] Восстановлена именно off-host копия на отдельном стенде с отдельными credentials; проверен exact-base переход, схема 14.
- [ ] `VERSION` совпадает с `Lite::VERSION`; пройдены `doctor`, `developer check`, security audit, compatibility check, stable-check.
- [ ] Выполнен CI PHP/HTTP и реальная браузерная навигация Chromium/Firefox/WebKit; проверены конфиги Nginx/Apache и реальный FPM.
- [ ] TLS валиден; GET `/health/live` и `/health/ready` возвращают 200, ready — `{"status":"ready"}`; POST `/health/ready` — 405. Неверный Host отвергается, проверены HSTS, cache-control, CSP, cookie Secure/HttpOnly/SameSite, закрытие private routes.
- [ ] Проверены страницы, редактор, медиа, SEO, CSRF, шаблоны в разрешённое окно, backup/export/import, отказ невалидных TAR и отзыв сессий.
- [ ] При подключённом OIDC — реальные login/logout, mismatch, backchannel logout, CLI recovery и revocation. При подключённых AI/аналитике — доставка, согласие и лимиты расходов.
- [ ] Проверены `release stable-check --json --live` и выбранные provider probes при нормальном TLS; отключённые провайдеры документированы как N/A.
- [ ] Проверен откат и восстановление полной копии, включая реальные данные и медиа; записано время восстановления. Нагрузочные тесты и длительная эксплуатация соответствуют заранее согласованным порогам.

## GA автоматических обновлений

- [ ] Установлены отдельно проверенные root-owned supervisor и guards; dedicated FPM/background units, read-only mounts, managed trust и loopback TLS; старые scheduler/cron выключены.
- [ ] Пройден root `preflight`; проверены окно UTC, Stable, отключённый Preview и явный системный/UI opt-in.
- [ ] Репетиция реальных GitHub-переходов с предыдущей Stable и 1.5.9: 503 gate, смена PID, сохранность данных, readiness и rollback.
- [ ] Проверены отказ подписей/ключей, outage, прерванный supervisor и reboot; неисправные fixtures не опубликованы в официальном канале.
- [ ] Свежий `release ga-check --live` пройден; сохранены все 11 evidence-файлов и digest, оператор проверил их перед `ga-sign-off`.

## Развёртывание и наблюдение

- [ ] Остановлены writers/schedulers; создана свежая независимая копия; применены только проверенные файлы с правильными путями и владельцами.
- [ ] FPM перезапущен после проверок, health и критические пользовательские сценарии проходят; продолжается мониторинг ошибок, latency, ресурсов и backup age.
- [ ] При сбое запись остановлена; восстановлены согласованные код и storage или выполнен точный rollback; повторены readiness и auth checks. CMS TAR не заменяет полный rollback сервера.
- [ ] Зафиксированы дата, среда, reviewer, ссылки на проверенные результаты и разрешённые отклонения; приватный sign-off экспортирован в защищённый аудит.

Исторические проверки: `tests/core_141.php`, `tests/site_concurrency_141.php`, `tests/release_engineering_148.py`, [RC 1.4.9](RELEASE_CANDIDATE.md). Выполняйте fault injection только в изоляции. Никакая автоматическая проверка не подтверждает непроверенные credentials, внешний provider, реальный трафик или конфигурацию. `not_run` не равно успешной проверке.
