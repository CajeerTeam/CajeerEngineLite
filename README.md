<div align="center">

# CajeerEngine Lite

**Лёгкая CMS для самостоятельного размещения сайтов**

![Версия 1.6.0](https://img.shields.io/badge/version-1.6.0-2563eb?style=flat-square)
![PHP 8.4.1+](https://img.shields.io/badge/PHP-8.4.1%2B-777bb4?style=flat-square)
![JSON 14](https://img.shields.io/badge/JSON_schema-14-475569?style=flat-square)
![Apache 2.0](https://img.shields.io/badge/license-Apache--2.0-475569?style=flat-square)

[Возможности](#возможности) · [Быстрый старт](#быстрый-старт) · [Документация](docs/README.md) · [Релиз 1.6.0](docs/RELEASE_NOTES.md) · [История изменений](CHANGELOG.md)

</div>

---

CajeerEngine Lite — независимая редакция CajeerEngine для визиток, лендингов, портфолио и небольших корпоративных сайтов. Управляйте страницами, медиа, SEO и интеграциями из административной панели, сохраняя контроль над данными и размещением. Для работы самой CMS не нужны сервер SQL, Composer, Node.js или Redis.

> **1.6.0 · GitHub Automatic Updates GA** — стабильная линия автоматических обновлений с подписанными пакетами, планируемым обслуживанием, проверкой HTTPS и восстановлением после неудачной установки. Наличие кода GA не означает, что релиз опубликован или конкретный сервер прошёл production-приёмку. Автоматическая установка требует отдельного подключения и проверки инфраструктуры.

## Возможности

| Направление | Что входит |
| :--- | :--- |
| **Контент** | Управление страницами, черновики, публикация, меню, блочный редактор, медиатека и использование изображений в контенте. |
| **SEO** | Управление метаданными, canonical, robots.txt, sitemap.xml, индексированием и структурированными данными. |
| **Доступ** | Локальный вход владельца, опциональная привязка SkiF4er ID (OIDC), управление сессиями и защищённое восстановление доступа. |
| **Аналитика** | Cookie Consent, Google Analytics 4, Яндекс Метрика, Google Tag Manager, Microsoft Clarity, Matomo; отчёты подключённых провайдеров. |
| **Cajeer AI Assistant** | Опциональный OpenAI API: чат, генерация контента, предложения по страницам и SEO, аналитические выводы, техническая диагностика. |
| **Эксплуатация** | Резервные копии и импорт/экспорт содержимого, диагностика, health endpoints, приватный мониторинг и инструменты разработчика. |
| **Обновления** | Подписанные exact-base пакеты, Stable/Preview, центр обновлений, планировщик, независимый supervisor, health-проверки и откат. |

Подробнее: [паспорт продукта и границы возможностей](docs/PRODUCT.md).

## Быстрый старт

Для локального знакомства используйте отдельную распакованную копию и PHP 8.4.1+:

```bash
php bin/cajeer install http://127.0.0.1:8080 "Мой сайт"
php bin/cajeer doctor
php -S 127.0.0.1:8080 -t public router.php
```

Укажите новый пароль администратора (12–72 байта; интерактивный ввод видимый). Откройте `http://127.0.0.1:8080/admin`. Установщик предназначен для пустого хранилища; повторно не запускайте его для существующего сайта. Встроенный сервер PHP используется только для локального тестирования.

### Развёртывание на сервере

| Компонент | Требование |
| :--- | :--- |
| Среда | Linux/POSIX, локальная файловая система с `flock`, `fsync`, атомарным rename и hardlink |
| PHP | 8.4.1+, необходимые стандартные расширения, PHP-FPM |
| Веб-сервер | Nginx либо Apache 2.4, HTTPS, document root строго `public/` |
| Данные | Один сайт, один узел, приватное `storage/`, схема JSON 14 |
| Автообновление | Отдельная учётная запись, systemd, root-owned supervisor, managed trust, окно обслуживания UTC |

Для production следуйте [руководству по установке](docs/INSTALL.md) и [чек-листу приёмки](docs/PRODUCTION_ACCEPTANCE.md). Для обновления существующего сайта используйте [UPGRADE](docs/UPGRADE.md), а не повторную установку.

## Документация

| Для кого | С чего начать |
| :--- | :--- |
| Владелец сайта | [Паспорт продукта](docs/PRODUCT.md) · [Работа с CMS](docs/USER_GUIDE.md) |
| Системный администратор | [Установка](docs/INSTALL.md) · [Обновление](docs/UPGRADE.md) · [Эксплуатация](docs/OPERATIONS.md) · [Восстановление](docs/BACKUP_RECOVERY.md) |
| Release-инженер | [Заметки о релизе](docs/RELEASE_NOTES.md) · [Публикация](docs/RELEASE.md) · [GA-приёмка](docs/GITHUB_AUTOMATIC_UPDATES_GA.md) |
| Специалист по безопасности | [Политика безопасности](SECURITY.md) · [Совместимость](docs/COMPATIBILITY.md) · [Чек-лист приёмки](docs/PRODUCTION_ACCEPTANCE.md) |
| Разработчик | [Участие в разработке](CONTRIBUTING.md) · [Полный указатель](docs/README.md) · [Архив истории](docs/HISTORY.md) |

## Проект

- Репозиторий Lite: [CajeerTeam/CajeerEngineLite](https://github.com/CajeerTeam/CajeerEngineLite).
- Основной проект: [CajeerTeam/CajeerEngine](https://github.com/CajeerTeam/CajeerEngine).
- Лицензия: [Apache License 2.0](LICENSE). Правила использования бренда: [TRADEMARKS](TRADEMARKS.md).
- Поддержка: `support@cajeer.ru`. Сообщения об уязвимостях: `security@cajeer.ru` (приватно).

<sub>© 2026 SkiF4er · Cajeer Team · сведения о правообладателях и участниках: [NOTICE](NOTICE).</sub>
