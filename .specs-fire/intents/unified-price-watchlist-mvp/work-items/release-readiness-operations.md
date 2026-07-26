---
id: release-readiness-operations
title: Сквозные тесты и production scheduler
intent: unified-price-watchlist-mvp
complexity: medium
mode: confirm
status: completed
depends_on:
  - canonical-game-price-model
  - central-price-refresh
  - stored-price-search
  - cross-source-alert-settings
  - telegram-identity-merge
  - alert-evaluation-delivery
  - website-watchlist-ui
  - telegram-bot-shared-interface
  - free-search-monetization-cleanup
created: 2026-07-25T13:47:35Z
run_id: run-agregator-games-vibecoding-012
completed_at: 2026-07-26T09:40:58.376Z
---

# Work Item: Сквозные тесты и production scheduler

## Description

Собрать проверяемый production-контур с единственным scheduler, изолированными интеграционными тестами и наблюдаемостью качества обновления и доставки.

## Acceptance Criteria

- [ ] Backend feature-тесты выполняются на PostgreSQL и подменяют Steam, Plati, GGsel и Telegram.
- [ ] Покрыты миграция существующих данных, `coming_soon`, фильтры источников/видов, объединение аккаунтов и дедупликация доставки.
- [ ] Docker Compose запускает отдельные Laravel scheduler и queue worker.
- [ ] Python APScheduler больше не инициирует общий радар, поэтому параллельных владельцев расписания нет.
- [ ] Frontend проходит lint и production build, а бот — изолированные тесты команд и API-клиента.
- [ ] Логи или метрики показывают свежесть, ошибки источников, число обработанных игр, созданных событий и доставленных сообщений.
- [ ] Есть документированный порядок миграции, проверки и отката без автоматического production deploy.

## Technical Notes

Этот пункт не исправляет функциональные пробелы предыдущих работ, а проверяет их совместную работу и готовит безопасный запуск.

## Dependencies

- canonical-game-price-model
- central-price-refresh
- stored-price-search
- cross-source-alert-settings
- telegram-identity-merge
- alert-evaluation-delivery
- website-watchlist-ui
- telegram-bot-shared-interface
- free-search-monetization-cleanup
