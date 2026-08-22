---
run: run-agregator-games-vibecoding-001
work_item: canonical-game-price-model
intent: unified-price-watchlist-mvp
generated: 2026-07-25T14:48:37Z
mode: validate
---

# Implementation Walkthrough: Единая модель игр и цен

## Summary

Создана общая серверная модель игры, состояния источников, текущих цен и ценовой истории. Существующее избранное получило безопасную nullable-связь с канонической игрой, а миграция переносит пригодные legacy-данные без обращения к магазинам и без создания недостоверной истории Plati/GGsel.

## Structure Overview

Каноническая `Game` стала корнем объективных ценовых данных. Пользовательские записи `Favorite` ссылаются на одну игру, а `GameSourceState`, `CurrentGamePrice` и `GamePriceObservation` хранят общие для всех пользователей состояние, актуальный срез и историю. Старые поля остаются на месте до перехода API в следующих work items.

## Architecture

### Pattern Used

Additive canonical data model: новая нормализованная схема добавляется рядом с legacy-схемой, связывается nullable foreign key и постепенно становится источником данных для поиска, алертов и Telegram.

### Layer Structure

```text
Favorite пользователя ──┐
Favorite пользователя ──┼──> Game
                        │      ├──> GameSourceState
                        │      ├──> CurrentGamePrice
                        │      └──> GamePriceObservation
legacy snapshots ───────┘             (90 дней)
```

## Files Changed

### Created

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_25_140600_create_canonical_game_price_model.php` | Четыре таблицы, constraints, nullable-связь и legacy-backfill. |
| `backend/app/Models/Game.php` | Каноническая игра и релизные статусы. |
| `backend/app/Models/GameSourceState.php` | Состояние свежести по источнику. |
| `backend/app/Models/CurrentGamePrice.php` | Общий актуальный ценовой срез. |
| `backend/app/Models/GamePriceObservation.php` | Append-only история и 90-дневная политика. |
| `backend/tests/Feature/CanonicalGamePriceModelTest.php` | PostgreSQL-интеграционные тесты схемы и backfill. |

### Modified

| File | Changes |
|------|---------|
| `backend/app/Models/Favorite.php` | Добавлены `game_id` и отношение `game()` без изменения API-представления. |

## Domain Model

### Entities

| Entity | Properties | Business Rules |
|--------|------------|----------------|
| Game | Steam `appid`, название, изображение, статус и дата релиза | Steam `appid` уникален; статус только `unknown`, `announced`, `released`. |
| GameSourceState | Игра, источник, времена обновления, статус, ошибка | Одна строка на игру и источник; источник только Steam/Plati/GGsel. |
| CurrentGamePrice | Игра, источник, вид, агрегированные цены и предложения | Одна строка на игру/источник/вид; цены неотрицательны. |
| GamePriceObservation | Игра, источник, вид, цена, предложение, время | История отделена от актуального среза; политика хранения — 90 дней. |
| Favorite | Пользователь, legacy `appid`, nullable `game_id`, цель | Несколько пользователей могут ссылаться на одну каноническую игру. |

## Key Implementation Details

### 1. Additive migration

Старые таблицы и поля не переписываются. Откат сначала удаляет связь избранного, затем новые таблицы в обратном порядке зависимостей.

### 2. Honest legacy backfill

Игры собираются из истории поиска и избранного, причём метаданные избранного имеют приоритет. Переносится последняя Steam-цена по времени наблюдения; объединённый `market_min_rub` намеренно игнорируется, потому что из него нельзя восстановить площадку и вид предложения.

### 3. Shared price ownership

Новые цены не имеют `user_id` и не получили публичных write-endpoint. Поэтому два пользователя одной игры читают один серверный ценовой срез, а не пользовательские копии.

## Security Considerations

| Concern | Approach |
|---------|----------|
| Подмена цены клиентом | Канонические таблицы не доступны через публичную запись API. |
| Невалидные значения источника/вида | PostgreSQL CHECK-ограничения плюс доменные константы моделей. |
| Секреты и внешние вызовы | Миграция не использует токены и не обращается к Steam, Plati или GGsel. |

## Performance Considerations

| Requirement | Implementation |
|-------------|----------------|
| Быстрое чтение актуальной цены | Отдельная `current_game_prices` с уникальным составным ключом. |
| Выбор задач обновления | Индекс `status + next_refresh_at` в состояниях источников. |
| История игры | Составной индекс `game/source/kind/observed_at`. |
| Перенос существующей БД | Исходные таблицы читаются порциями по 500 строк. |
| Ограничение роста | Политика 90 дней; автоматическая очистка подключается в `central-price-refresh`. |

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Денежные casts | `decimal:2` | Исключить двоичные погрешности `float` в новой модели. |
| Последняя legacy-цена | Максимальное время наблюдения, не максимальный ID | Импортированные записи могут добавляться не по хронологии. |
| История маркетплейсов | Не backfill-ить `market_min_rub` | Нельзя честно восстановить источник и вид предложения. |
| Совместимость | Сохранить старые поля и nullable `game_id` | Следующие work items смогут мигрировать потребителей поэтапно. |

## Deviations from Plan

Scope не изменился. Реализация уточнила понятие «последняя цена»: выбор выполняется по `observed_at`/legacy timestamp, а не по ID; добавлен соответствующий регрессионный случай.

## Dependencies Added

Новые зависимости приложения не добавлялись. Служебный пакет `yaml` установлен только внутри `.specsmd/fire` для штатных FIRE-скриптов.

## How to Verify

1. **Поднять PostgreSQL проекта**

   ```powershell
   docker compose up -d db
   ```

   Expected: сервис `db` становится healthy на PostgreSQL 16.

2. **Запустить профильные тесты с настроенной `gpa_test`**

   ```powershell
   cd backend
   php artisan test --filter=CanonicalGamePriceModelTest
   ```

   Expected: 6 tests passed, 28 assertions.

3. **Запустить полный backend-набор**

   ```powershell
   php artisan test
   ```

   Expected: 14 tests passed, 49 assertions.

4. **Проверить стиль**

   ```powershell
   .\vendor\bin\pint --test
   ```

   Expected: `passed`.

## Test Coverage

- Tests added: 6
- Assertions in new tests: 28
- Full suite: 14 tests, 49 assertions
- Numeric line coverage: не собирался, coverage driver в текущем окружении не настроен
- Status: passing

## Ready for Review

- [x] All acceptance criteria met
- [x] Tests passing
- [x] No critical issues
- [x] FIRE artifacts updated
- [x] Developer notes captured

## Developer Notes

- Новая миграция рассчитана на PostgreSQL; CHECK-ограничения добавляются именно там.
- Старый `Favorite.last_steam_price_rub` временно остаётся клиентским legacy-полем, но каноническая `current_game_prices` через API не записывается.
- `GamePriceObservation::RETENTION_DAYS` фиксирует 90-дневную политику; физическую очистку должен вызвать планировщик следующего work item.
- Локальный Docker оставлен запущенным по просьбе пользователя; сервис `db` используется для тестов.

---
*Generated by specs.md - fabriqa.ai FIRE Flow Run run-agregator-games-vibecoding-001*
