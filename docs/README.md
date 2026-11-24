# Документация CajeerEngine Lite

**Версия 1.6.0 · Схема данных 14**

[Главная проекта](../README.md) · [Что нового](RELEASE_NOTES.md) · [Журнал изменений](../CHANGELOG.md)

## Начало работы

| Руководство | Назначение |
| :--- | :--- |
| [Паспорт продукта](PRODUCT.md) | Назначение, возможности, архитектура и ограничения |
| [Руководство пользователя](USER_GUIDE.md) | Страницы, редактор, медиа, SEO, аналитика и AI |
| [Установка](INSTALL.md) | Проверка дистрибутива, настройка сервера и запуск |
| [Совместимость](COMPATIBILITY.md) | PHP, файловая система, прокси и миграция |

## Эксплуатация и безопасность

| Руководство | Назначение |
| :--- | :--- |
| [Обновление и откат](UPGRADE.md) | Подписанный и ручной переход |
| [Эксплуатация](OPERATIONS.md) | Мониторинг, обслуживание и диагностика |
| [Резервирование](BACKUP_RECOVERY.md) | TAR, независимые копии и восстановление |
| [Политика безопасности](../SECURITY.md) | Защита сервера и приватные сообщения об уязвимостях |
| [Production-приёмка](PRODUCTION_ACCEPTANCE.md) | Проверки перед вводом в эксплуатацию |

## Релиз 1.6.0

| Руководство | Назначение |
| :--- | :--- |
| [Заметки о релизе](RELEASE_NOTES.md) | Изменения, совместимость и переход |
| [Релизный процесс](RELEASE.md) | Подпись, сборка и публикация |
| [GitHub Automatic Updates GA](GITHUB_AUTOMATIC_UPDATES_GA.md) | Развёртывание supervisor, live gate, sign-off |
| [Production Stable](PRODUCTION_STABLE.md) | Базовая приёмка CMS и формат доказательств |

## Инженерный справочник

Ниже сохранены исходные подробные технические runbook'и. Часть из них написана на английском и относится к моменту появления функции; применять их следует с учётом требований актуального GA-руководства.

| Тема | Документы |
| :--- | :--- |
| Ядро и хранилище | [Core baseline](CORE_BASELINE.md), [Storage recovery](STORAGE_RECOVERY.md), [Security hardening](SECURITY_HARDENING.md) |
| Надёжность | [Operational reliability](OPERATIONAL_RELIABILITY.md), [Release Candidate 1.4.9](RELEASE_CANDIDATE.md) |
| Подписанные обновления | [Update Engine](UPDATE_ENGINE.md), [Automatic updates](AUTOMATIC_UPDATES.md), [GitHub discovery](GITHUB_RELEASE_DISCOVERY.md), [Distribution](UPDATE_DISTRIBUTION.md) |
| Управление | [Control Center](UPDATE_CONTROL_CENTER.md), [Scheduler](UPDATE_SCHEDULER.md), [Maintenance orchestration](MAINTENANCE_ORCHESTRATION.md) |
| Восстановление и доверие | [Health & rollback](AUTOMATIC_HEALTH_ROLLBACK.md), [Update security hardening](UPDATE_SECURITY_HARDENING.md) |
| Релизная автоматизация | [Release automation & monitoring](RELEASE_AUTOMATION_MONITORING.md), [Automatic Updates RC](AUTOMATIC_UPDATES_RC.md) |
| История | [Архив прежнего README](HISTORY.md), [CHANGELOG](../CHANGELOG.md) |

> Не используйте исторические команды обновления для произвольной версии. Проверяйте exact-base пакет, доверенный ключ и текущее состояние установленного сайта.
