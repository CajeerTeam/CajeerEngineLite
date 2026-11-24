# Совместимость и миграция

| Компонент | Поддерживаемая конфигурация |
| :--- | :--- |
| PHP | 8.4.1+; json, hash, filter, session, openssl, fileinfo, fsync; 8.3 и ниже не поддерживаются |
| PHP 8.5 | Кандидат CI-матрицы; фактический прогон обязателен |
| Диск | Одна локальная POSIX FS, блокировки, атомарный rename, hardlinks и fsync; не NFS/SMB/multi-node |
| Web | Nginx или Apache 2.4 + PHP-FPM, `public/`; отдельный доверенный reverse proxy |
| Браузеры | Chromium, Firefox, WebKit — реальные HTTP navigation tests, не автоматическая сертификация |
| Данные | Чтение схем 1–14; целевая схема 14 |

## Проверка среды и предварительный просмотр миграции

```bash
CAJEER_COMPAT_DOCUMENT_ROOT=/var/www/cajeer-lite/public php bin/cajeer compatibility check --json
php bin/cajeer compatibility migration preview
```

`check` проверяет расширения, версию, разделение путей, document root, trusted peers и JSON. Без переменной document root проверяется вручную. `preview` вычисляет миграцию 1–13 → 14 в памяти, возвращает исходный и целевой хеш и число страниц, не записывает данные и не создаёт backup.

## Применение legacy-миграции

1. Создайте проверенную независимую копию кода, шаблонов, storage и секретов, остановите FPM, cron и workers.
2. Выполните preview и проверьте схему/хеш.
3. Выполните `CAJEER_COMPATIBILITY_CONFIRM=MIGRATE php bin/cajeer compatibility migration apply`.
4. `SiteRepository::migrate` удерживает эксклюзивную блокировку, создаёт checksummed migration snapshot в приватном `storage/backups` и атомарно записывает схему 14; сохраняет контент, credentials и origin.
5. Повторите `compatibility check --json`, `doctor` и HTTP/browser acceptance; сохраните независимую копию.

Signed updater работает только с кодом и схемой 14; он не исполняет произвольные migration scripts. Для версий до 1.4.4 используйте проверенный ручной переход. Exact-base и хеши не позволяют произвольные скачки версии.

## Доверенный reverse proxy

По умолчанию forwarded headers игнорируются; AdminRequestGuard требует canonical Host и серверный `HTTPS=on`. При TLS offload задайте `CAJEER_TRUSTED_PROXY_IPS`: до 16 точных IPv4/IPv6, не CIDR. PHP должен получать запрос непосредственно от доверенного peer; `X-Forwarded-Host` и `X-Forwarded-Proto` должны соответствовать canonical origin. Некорректные и противоречивые значения отвергаются; клиентский `X-Forwarded-For` не применяется для авторизации или rate limit.

Front proxy обязан перезаписывать клиентские Host/forwarded host/proto, а не добавлять их. Закройте прямой публичный доступ к origin firewall; примеры конфигурации используют loopback. Не устанавливайте безусловно `HTTPS=on` для HTTP-only backend.

## Проверки на изолированном стенде

```bash
php tests/core_146.php
bash tests/compatibility_http_146.sh
python3 tests/browser_146.py --browser chromium
python3 tests/browser_146.py --browser firefox
python3 tests/browser_146.py --browser webkit
```

Проверьте реальные конфиги Nginx/Apache, сертификаты, сокеты, TLS и proxy. Конфигурации `deploy/` являются примерами, не автоматической установкой. Python 3.11+ и OpenSSL CLI нужны сборке; CMS не требует Python, но GA supervisor использует его. Прогон exact-tag build не подтверждает все комбинации PHP/browser/FPM.
