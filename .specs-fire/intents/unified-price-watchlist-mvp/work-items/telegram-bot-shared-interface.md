---
id: telegram-bot-shared-interface
title: Полноценный интерфейс Telegram-бота
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
run_id: run-agregator-games-vibecoding-008
completed_at: 2026-07-25T18:32:50.497Z
---

# Work Item: Полноценный интерфейс Telegram-бота

## Description

Добавить в aiogram-бот поиск, краткую карточку, избранное и управление алертами через тот же backend и аккаунт, что использует сайт.

## Acceptance Criteria

- [ ] Бот ищет игру через общий backend и позволяет выбрать результат.
- [ ] Краткая карточка показывает актуальные предложения, время обновления и статус релиза.
- [ ] Пользователь может добавить игру, выбрать площадки и виды и задать целевую цену.
- [ ] Бот показывает активные и сработавшие алерты и позволяет реактивировать их.
- [ ] Изменение в боте немедленно видно на сайте и наоборот.
- [ ] Неизвестная игра показывает состояние фонового заполнения, а `coming_soon` не показывает маркетплейсы.
- [ ] Python-бот не хранит отдельную копию пользовательских данных и не запускает общий ценовой сканер.

## Technical Notes

Все бизнес-правила остаются в Laravel. Бот является тонким интерфейсом и использует типизированный внутренний API с сервисной аутентификацией и Telegram identity.

## Dependencies

- stored-price-search
- cross-source-alert-settings
- telegram-identity-merge
- alert-evaluation-delivery
