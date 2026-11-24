# Эксплуатация CajeerEngine Lite

## Ежедневная диагностика

В корне развёрнутого проекта от соответствующей непривилегированной учётной записи:

```bash
php bin/cajeer doctor
php bin/cajeer monitor status --minutes=60
php bin/cajeer monitor check --json
php bin/cajeer operations check
php bin/cajeer update status
php bin/cajeer update monitor check
```

Внешний HTTPS-мониторинг должен проверять `/health/live` и `/health/ready` (200 и `{"status":"ready"}` для ready; 503 требует разбирательства). Веб-интерфейс мониторинга: `/admin/developer?tab=monitoring` после авторизации. Проверяйте exit status, а не только stdout. Встроенный мониторинг не доставляет внешние уведомления автоматически. Храните FPM и web-server логи приватно и ротируйте средствами ОС.

## Регулярные задачи

Пример cron для мониторинга и архивов; адаптируйте пути, пользователя, защищённый вывод и согласуйте задачи с окнами обслуживания:

```cron
*/5 * * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer monitor check --json
15 3 * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer operations backup create
45 3 * * * cd /var/www/cajeer-lite && /usr/bin/php bin/cajeer monitor check --verify-backup
```

Копируйте проверенные архивы off-host; не совмещайте полный backup с update/recovery. Для автоматической установки 1.6.0 используйте [GA supervisor](GITHUB_AUTOMATIC_UPDATES_GA.md), не одновременно старые cron/worker. Не подменяйте реальную остановку workers флагом quiesced.

## Диагностика инцидентов

| Симптом | Первые действия |
| :--- | :--- |
| `live` недоступен | Проверить TLS, proxy, FPM и процессы |
| `live` работает, `ready` нет | Проверить `doctor`, storage, свободное место, update/CMS recovery |
| Рост p95/5xx | Проверить FPM pool, I/O диска, таймауты провайдеров, contention журнала |
| Неудачная установка | Остановить автоустановку, сохранить journal/hold, проверить supervisor и rollback; не открывать gate вручную |
| Ошибка опциональной интеграции | Отключить её поддерживаемым способом, не обходить TLS, авторизацию и согласие |
| Подозрение на потерю данных | Остановить запись, следовать [BACKUP_RECOVERY](BACKUP_RECOVERY.md) |

## Метрики и ограничения

Приватный журнал запросов ротируется примерно на 2 МиБ, содержит только сохранённые измерения, может пропускать записи при contention; не включает IP, query string и содержимое запросов. Это не точный счётчик, не распределённый сборщик и не замена FPM-метрикам. Настройте пороги диска, p95, 5xx и памяти по [OPERATIONAL_RELIABILITY](OPERATIONAL_RELIABILITY.md). Проверяйте возраст архива и диск до обновлений, используйте внешнюю систему долгосрочного мониторинга.

Нагрузочная репетиция только в изоляции:

```bash
python3 tests/operational_load_147.py --seconds 60 --workers 4
```

Для длительного staging soak определите длительность, p95/p99, error rate и ресурсные пороги по целям эксплуатации. Скрипт использует disposable PHP, не production FPM. Реальные показатели не следует выдумывать.
