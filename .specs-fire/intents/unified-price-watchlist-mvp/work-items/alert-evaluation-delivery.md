---
id: alert-evaluation-delivery
title: Срабатывание и доставка алертов
intent: unified-price-watchlist-mvp
complexity: high
mode: validate
status: completed
depends_on:
  - central-price-refresh
  - cross-source-alert-settings
  - telegram-identity-merge
created: 2026-07-25T13:47:35Z
run_id: run-agregator-games-vibecoding-006
completed_at: 2026-07-25T17:27:34.901Z
---

# Work Item: Срабатывание и доставка алертов

## Description

Проверять обновлённые предложения относительно пользовательских фильтров, создавать одно срабатывание и надёжно доставлять его в Telegram с общей историей для сайта и бота.

## Acceptance Criteria

- [ ] Оцениваются только выбранные пользователем площадки и виды предложений.
- [ ] Алерт срабатывает, когда минимальная допустимая цена равна цели или ниже.
- [ ] Одно достижение цели создаёт одно событие даже при повторе задания или нескольких одинаковых предложениях.
- [ ] Событие хранит площадку, вид, цену, название предложения, ссылку и время наблюдения.
- [ ] Временная ошибка Telegram приводит к идемпотентной повторной доставке, а не к новому событию.
- [ ] После успешного срабатывания состояние становится `triggered` и не уведомляет повторно до реактивации.
- [ ] API предоставляет активные и сработавшие алерты и историю доставки.

## Technical Notes

Доставка должна быть отдельным queued job с idempotency key. Нельзя использовать текущую 20-часовую эвристику дедупликации как источник истины.

## Dependencies

- central-price-refresh
- cross-source-alert-settings
- telegram-identity-merge
