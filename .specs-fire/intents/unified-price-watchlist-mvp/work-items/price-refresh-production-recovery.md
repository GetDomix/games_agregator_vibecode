---
id: price-refresh-production-recovery
title: Production scheduler и заполнение цен
intent: unified-price-watchlist-mvp
complexity: high
mode: validate
status: completed
depends_on:
  - central-price-refresh
  - stored-price-search
  - telegram-bot-shared-interface
created: 2026-07-25T19:10:00Z
run_id: run-agregator-games-vibecoding-010
completed_at: 2026-07-25T19:08:51.468Z
---

# Work Item: Production scheduler и заполнение цен

## Description

Запустить единственный production scheduler и queue worker, чтобы серверное хранилище цен обновлялось независимо от пользовательских действий, а после подтверждённого Steam-релиза новая игра получала состояния Plati и GGsel.

## Acceptance Criteria

- [ ] Docker Compose запускает раздельные `backend`, `scheduler` и `queue-worker` с общей PostgreSQL queue-конфигурацией.
- [ ] Только scheduler запускает Laravel schedule; worker исполняет очередь `prices` и default jobs.
- [ ] Новая игра сначала проверяет только Steam; для announced не создаются и не запрашиваются Plati/GGsel.
- [ ] После Steam-результата `released` создаются pending states Plati и GGsel, доступные scheduler не позднее следующей минуты.
- [ ] Цена источника сохраняется в canonical tables и доступна одинаково сайту и боту после фонового обновления.
- [ ] Feature-тесты покрывают released/announced переход, очередь и сохранённую карточку; Compose проходит config validation.
- [ ] Production smoke-check подтверждает живые worker/scheduler и свежесть хотя бы одной безопасной тестовой игры без обхода лимитов источников.

## Technical Notes

Не добавлять новый ценовой источник и не выполнять запросы к магазинам внутри пользовательского HTTP-запроса. Внешние запросы идут только через существующие queue jobs, source rate limit и backoff.

## Dependencies

- central-price-refresh
- stored-price-search
- telegram-bot-shared-interface
