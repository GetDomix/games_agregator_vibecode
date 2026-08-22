---
id: website-watchlist-ui
title: Интерфейс сайта для избранного и алертов
intent: unified-price-watchlist-mvp
complexity: medium
mode: confirm
status: completed
depends_on:
  - stored-price-search
  - cross-source-alert-settings
  - telegram-identity-merge
  - alert-evaluation-delivery
created: 2026-07-25T13:47:35Z
run_id: run-agregator-games-vibecoding-007
completed_at: 2026-07-25T17:47:06.937Z
---

# Work Item: Интерфейс сайта для избранного и алертов

## Description

Заменить прототипный `prompt` полноценным сценарием настройки и добавить в кабинет общие состояния, историю и Telegram-привязку.

## Acceptance Criteria

- [ ] Форма добавления позволяет выбрать площадки, виды и целевую цену без браузерного `prompt`.
- [ ] Пользователь видит свежесть цены, ошибку источника и состояние фонового обновления.
- [ ] Кабинет разделяет активные и сработавшие алерты и позволяет редактировать или реактивировать их.
- [ ] История отображается по площадке и виду предложения.
- [ ] Анонсированная игра имеет понятный статус ожидания релиза без ложных маркетплейс-предложений.
- [ ] Официальная Telegram-привязка показывает результат объединения аккаунтов.
- [ ] Интерфейс проходит lint, TypeScript build и ручную проверку основных сценариев.

## Technical Notes

Сохранить существующий визуальный язык и карточки. Большой `App.tsx` разрешается разбить только в пределах затронутых компонентов без несвязанного редизайна.

## Dependencies

- stored-price-search
- cross-source-alert-settings
- telegram-identity-merge
- alert-evaluation-delivery
