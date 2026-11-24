# Обновление и откат

**1.6.0 · Сохранение данных · Exact-base переходы**

## Выберите тип перехода

| Ситуация | Действие |
| :--- | :--- |
| Новый сайт | Полный ZIP и [установка](INSTALL.md) |
| Установленная 1.5.9 | Подписанный пакет с точной базой 1.5.9 или проверенный ручной переход |
| Более ранняя Stable | Подписанный достижимый маршрут по индексу; один переход за запуск |
| Изменённый runtime или старый updater | Отдельно проверенный ручной переход, без обхода проверки хешей |
| Схема ниже 14 | Явная миграция после резервирования, до подключения signed updater |

## Перед обновлением

Запишите `VERSION`, PHP, схему, canonical origin, отпечаток ключа и исходное дерево. Восстановите независимую копию на staging и отрепетируйте переход. Остановите FPM, таймеры и фоновые задачи перед записью файлов. Флаг CLI не подтверждает реальную остановку процессов.

```bash
php bin/cajeer compatibility check --json
php bin/cajeer operations backup create
php bin/cajeer operations backup list
php bin/cajeer operations backup verify NAME
php bin/cajeer update status
```

Подставьте имя созданного архива. CMS TAR дополняет, но не заменяет полную off-host копию кода, данных, шаблонов и секретов.

## Подписанная установка

Проверьте опубликованные SHA256SUMS и подпись, используйте отдельно установленную managed trust policy (см. [доверие](UPDATE_SECURITY_HARDENING.md)). Пример для точной базы 1.5.9; имя соответствует дополнительному пакету распределения:

```bash
export CAJEER_UPDATE_TRUST_POLICY_FILE=/etc/cajeer/update-trust.json
php bin/cajeer update trust status
php bin/cajeer update verify /secure/CajeerEngineLite-1.5.9-to-1.6.0.cjrupdate
CAJEER_UPDATE_CONFIRM=INSTALL php bin/cajeer update install /secure/CajeerEngineLite-1.5.9-to-1.6.0.cjrupdate
php bin/cajeer doctor
php bin/cajeer security audit --json
php bin/cajeer compatibility check --json
php bin/cajeer update status
```

Пример пути применим только к реально выпущенному артефакту. Exact-base, схема, PHP, подпись и исходные хеши обязательны. `docs/` — исходная документация, не управляемая signed updater; обновите отдельно. Не накладывайте полный ZIP поверх рабочей установки: удалённые файлы могут остаться.

## Ручной переход и миграция

Проверьте происхождение patch ZIP и соответствие версии, остановите процессы записи, сохраните резервную копию и наложите относительные пути. Не перезаписывайте `storage/`, локальные секреты и пользовательские шаблоны. Учтите удалённые файлы по фактическому diff. При схеме ниже 14:

```bash
php bin/cajeer compatibility migration preview
CAJEER_COMPATIBILITY_CONFIRM=MIGRATE php bin/cajeer compatibility migration apply
```

Не редактируйте `site.json` вручную и не пытайтесь выполнить downgrade схемы через updater.

## Проверка и восстановление

`php bin/cajeer release stable-check --json` — базовая проверка (1 — ошибка, 2 — проверки пройдены, ручная приёмка ожидается). Перезапустите FPM с обновлением OPcache, проверьте TLS, `/health/ready`, страницы, авторизацию, медиа, SEO и мониторинг. Для 1.6.0 выполните [GA gate](GITHUB_AUTOMATIC_UPDATES_GA.md).

При прерванной установке с остановленными процессами записи: `php bin/cajeer update recover`. Для завершённой транзакции получите ID через `update status`, затем `CAJEER_UPDATE_CONFIRM=ROLLBACK php bin/cajeer update rollback ID`. Откат восстанавливает код, а не изменения содержимого после обновления. Не удаляйте активный journal. Если recovery не проходит, сохраните доказательства и используйте независимую копию [BACKUP_RECOVERY](BACKUP_RECOVERY.md).

## Автоматический переход

Не включён автоматически. Установите независимо проверенный supervisor и startup guards до возобновления таймеров; старый supervisor не понимает фазу `installing`. Следуйте [GA runbook](GITHUB_AUTOMATIC_UPDATES_GA.md), не запускайте legacy cron параллельно systemd. Preview разрешается отдельно; в Stable оставляйте его выключенным.

Исторически 1.5.0 выпускался с базы 1.4.8, не 1.4.9; для RC требовался отдельный пакет. Не применяйте исторические переходы к другим версиям.
