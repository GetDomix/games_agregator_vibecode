---
run: run-agregator-games-vibecoding-001
work_item: canonical-game-price-model
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: approved
approved_at: 2026-07-25T14:11:27Z
---

# Implementation Plan: Единая модель игр и цен

## Approach

Реализация ограничивается Laravel backend и выполняется добавочно, без изменения существующих API. Одна обратимая миграция создаст четыре канонические таблицы, добавит nullable `favorites.game_id` и перенесёт пригодные legacy-данные средствами Laravel Query Builder без внешних запросов. Затем будут добавлены Eloquent-модели, связи и PostgreSQL-интеграционные тесты.

Порядок реализации:

1. Создать `games`, `game_source_states`, `current_game_prices` и `game_price_observations` с внешними ключами, уникальностями, индексами и CHECK-ограничениями.
2. Добавить nullable `favorites.game_id`, сохранив `appid` и все прежние поля для обратной совместимости.
3. Внутри миграции выполнить повторяемый backfill порциями:
   - создать/обновить игры из `search_histories`, затем из `favorites`, чтобы данные избранного имели приоритет;
   - связать каждое избранное с игрой по Steam `appid`;
   - выбрать последнее доступное Steam-наблюдение на игру из `price_snapshots`, с fallback на `favorites.last_steam_price_rub`;
   - создать только `steam/official` текущую цену, одно историческое наблюдение и состояние `stale`;
   - не переносить `market_min_rub`, потому что в нём потеряны площадка и вид предложения.
4. Добавить четыре модели с денежными `decimal:2` casts, константами допустимых значений, отношениями и 90-дневной константой хранения истории.
5. Расширить `Favorite` связью `game()`, не меняя текущий API-массив и пользовательское поведение.
6. Добавить интеграционные тесты чистой схемы, отношений, ограничений и сценария обновления legacy-базы.
7. Запустить профильный тест, затем полный `php artisan test`; результат зафиксировать в FIRE test report.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_25_140600_create_canonical_game_price_model.php` | Каноническая схема, ограничения, legacy-backfill и обратимый откат. |
| `backend/app/Models/Game.php` | Идентичность игры, релизные статусы и отношения. |
| `backend/app/Models/GameSourceState.php` | Свежесть, расписание и ошибка по источнику. |
| `backend/app/Models/CurrentGamePrice.php` | Актуальная общая цена по источнику и виду предложения. |
| `backend/app/Models/GamePriceObservation.php` | Append-only история цен и политика хранения 90 дней. |
| `backend/tests/Feature/CanonicalGamePriceModelTest.php` | PostgreSQL-проверки чистой схемы, модели, constraints и legacy-backfill. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/app/Models/Favorite.php` | Добавить `game_id` в серверную модель и отношение `game()`, сохранив существующий контракт API. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/CanonicalGamePriceModelTest.php` | Создание чистой схемы; уникальный Steam `appid`; уникальные источник/вид; CHECK-ограничения; Eloquent-отношения; перенос существующего избранного и последней Steam-цены без market-истории и дубликатов. |
| `backend/tests/Feature/ApiSmokeTest.php` | Регрессия существующих health/auth/favorites/prices контрактов в составе полного набора. |

## Technical Details

- Денежные значения хранятся как `decimal(12,2)` и кастуются в моделях как `decimal:2`, чтобы избежать ошибок двоичного `float`.
- `games.steam_appid` уникален; `game_source_states` уникален по `(game_id, source)`; `current_game_prices` — по `(game_id, source, offer_kind)`.
- История индексируется по `(game_id, source, offer_kind, observed_at)`; состояния дополнительно индексируются по `next_refresh_at`.
- Допустимые значения дублируются доменными константами моделей и PostgreSQL CHECK-ограничениями.
- Backfill не обращается к Steam, Plati или GGsel и не блокирует дальнейшее обновление цен серверным планировщиком.
- Старые `appid`, `last_steam_price_rub`, `price_snapshots` и `search_histories` не удаляются на этом этапе.
- Автоматическая очистка наблюдений старше 90 дней будет подключена в `central-price-refresh`; здесь фиксируется доменная политика и подходящий индекс.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/canonical-game-price-model-design.md`

---
*Plan approved at Checkpoint 2 on 2026-07-25T14:11:27Z. Execution follows.*
