# Установка CajeerEngine Lite

**1.6.0 · Чистое развёртывание**

## 1. Подготовьте среду

| Компонент | Требование |
| :--- | :--- |
| ОС и диск | Linux/POSIX, локальная FS с atomic rename, hardlink, `flock`, `fsync` |
| PHP | 8.4.1+, `json`, `filter`, `hash`, `session`, `openssl`, `fileinfo`, стандартные средства проверки изображений |
| Сеть | `allow_url_fopen` и CA-сертификаты для HTTPS провайдеров и discovery |
| Web | PHP-FPM, Nginx или Apache 2.4, HTTPS |
| Дополнительно | Python 3.11+ и OpenSSL CLI для сборки; systemd, bash, curl и Python для supervisor |

SQL, Redis, Composer и Node.js не обязательны для CMS. PHP 8.5 и браузерная матрица требуют подтверждения тестами. Подробнее: [совместимость](COMPATIBILITY.md).

## 2. Проверьте официальный дистрибутив

Получите весь комплект файлов авторизованного релиза, включая ZIP, основной пакет, отчёт, подписанные контрольные суммы и дополнительные файлы дистрибуции. Независимо получите доверенный публичный RSA-ключ. Не доверяйте ключу, скачанному вместе с непроверенным пакетом.

```bash
openssl dgst -sha256 -verify /etc/cajeer/update-trusted.pem -signature SHA256SUMS.sig SHA256SUMS
sha256sum --strict -c SHA256SUMS
```

Для подписанного индекса и дополнительных пакетов также используйте процедуру [RELEASE](RELEASE.md). Распакуйте **полный ZIP** в пустой каталог, например `/var/www/cajeer-lite`. Архив изменений и `.cjrupdate` не являются полным установочным ZIP.

## 3. Настройте сервер

- Выделите отдельного Unix-пользователя. Код доступен PHP-FPM для чтения, но не записи; приватные каталоги `storage/` — 0700, приватные файлы — 0600, владелец имеет необходимые права.
- Настройте `deploy/nginx.conf` или `deploy/apache-vhost.conf`: домен, TLS-сертификаты, абсолютный путь `public/`, сокет FPM. Document root никогда не должен указывать на корень проекта.
- Для Apache включите нужные модули SSL, proxy_fcgi, rewrite и headers; в Nginx передавайте в FastCGI только ожидаемый front controller. Проверьте `nginx -t` либо `apachectl configtest`.
- Настройте перенаправление HTTP → HTTPS. Для reverse proxy соблюдайте [правила доверия](COMPATIBILITY.md). Храните сертификаты, OIDC и API-секреты вне web root и репозитория; не запускайте PHP от root.

## 4. Инициализируйте сайт

Выполните из корня проекта от владельца хранилища. В Bash пароль можно передать без вывода и без записи значения в историю:

```bash
read -rsp 'Пароль администратора (12–72 байта): ' CAJEER_ADMIN_PASSWORD; echo
export CAJEER_ADMIN_PASSWORD
php bin/cajeer install https://example.com 'Мой сайт'
unset CAJEER_ADMIN_PASSWORD
php bin/cajeer doctor
php bin/cajeer compatibility check --json
php bin/cajeer security audit --json
php bin/cajeer operations backup create
```

Замените `example.com` своим доменом. Не запускайте install на существующем `site.json`. Для старых данных используйте [обновление и миграцию](UPGRADE.md). Переменные CLI не передаются PHP-FPM автоматически; настройте окружение FPM отдельно. Оставьте необязательные интеграции выключенными до проверки.

## 5. Проверьте запуск

```bash
curl --fail https://example.com/health/live
curl --fail https://example.com/health/ready
```

Проверьте главную, `/admin`, cookie, TLS, статику, отсутствие доступа к `/storage/site.json`, резервным копиям и служебным файлам. Создайте проверенную off-host копию и пройдите [production-приёмку](PRODUCTION_ACCEPTANCE.md).

Для локальной разработки используйте отдельно установленный тестовый экземпляр: `php -S 127.0.0.1:8080 -t public router.php`. Не выставляйте встроенный сервер в Интернет.

Автоустановку подключайте только по [GA-руководству](GITHUB_AUTOMATIC_UPDATES_GA.md), после проверки реальной инфраструктуры.
