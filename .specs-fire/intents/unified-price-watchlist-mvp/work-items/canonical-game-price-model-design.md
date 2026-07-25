---
work_item: canonical-game-price-model
intent: unified-price-watchlist-mvp
created: 2026-07-25T13:57:55Z
mode: validate
checkpoint_1: approved
---

# Design: Единая модель игр и цен

## Summary

Добавить общий для всех пользователей каталог игр, состояние обновления каждого источника, актуальные цены и неизменяемую историю наблюдений. Существующее избранное остаётся совместимым, а пригодные Steam-данные переносятся в новую модель без создания недостоверной истории по маркетплейсам.

## Scope

**In Scope:**
- Каноническая игра с уникальным Steam `appid`, метаданными и статусом релиза.
- Общие текущие цены по источнику и виду предложения.
- История ценовых наблюдений с политикой хранения 90 дней.
- Состояние свежести и ошибок отдельно для каждого источника.
- Nullable-связь существующего избранного с канонической игрой.
- Безопасный идемпотентный backfill игр и достоверных Steam-цен.

**Out of Scope:**
- Планировщик и получение новых цен из внешних источников.
- Изменение публичного API поиска и избранного.
- Пользовательские фильтры источников и видов предложений.
- Telegram, доставка алертов и изменение монетизации.
- Физическое удаление старых таблиц и полей до завершения перехода.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Владение ценами | Общие серверные таблицы, не связанные с пользователем | Одна игра не должна запрашиваться и храниться повторно для каждого пользователя; клиент не сможет подменить объективную цену. |
| Текущая цена и история | Раздельные таблицы | Текущие значения читаются быстро, а append-only история остаётся пригодной для графиков, аудита и алертов. |
| Состояние источника | Отдельная строка на игру и источник | Отсутствие предложений, ошибка запроса и устаревшая цена являются разными состояниями и не должны кодироваться отсутствием ценовой строки. |
| Классификация предложений | `official`, `key`, `gift`, `account`, `rent`, `other` | Steam хранится как официальная цена, а предложения маркетплейсов можно фильтровать без смешивания рискованных видов. |
| Расширяемость значений | Строковые поля с доменными константами и ограничениями БД | Проще добавлять источники и виды предложений, сохраняя проверку допустимых значений. |
| Переход со старой схемы | Добавочная обратимая миграция; старые поля временно сохраняются | Публичные API продолжают работать во время поэтапного перехода следующих work items. |
| Backfill маркетплейсов | Не переносить агрегированное `market_min_rub` | Старое поле не позволяет достоверно определить площадку и вид предложения; выдуманные данные сделали бы историю нечестной. |
| Политика истории | 90 дней, одна запись на успешное наблюдение | Этого достаточно для MVP-графика и диагностики без неограниченного роста таблицы. Очистка подключается вместе с планировщиком. |

## Data Models Affected

### Creates
- **Game**: `steam_appid`, `name`, `header_image`, `release_status`, `release_date` — единая идентичность игры.
- **GameSourceState**: источник, времена попытки/успеха/следующего обновления, статус и ошибка — управление свежестью данных.
- **CurrentGamePrice**: источник, вид предложения, агрегированные цены и репрезентативные предложения — быстрый актуальный срез.
- **GamePriceObservation**: источник, вид предложения, цена и предложение на момент наблюдения — append-only история.

### Modifies
- **Favorite**: nullable `game_id` и Eloquent-связь — постепенный переход без поломки существующего API на `appid`.

## Technical Approach

### Architecture

```text
Steam / Plati / GGsel
          |
          v
адаптеры и классификатор (следующие work items)
          |
          +--> game_source_states
          +--> current_game_prices
          +--> game_price_observations
                         |
                         v
           поиск / избранное / алерты / бот

legacy favorites + search_histories + price_snapshots
          |
          v
идемпотентный backfill --> games + favorites.game_id + достоверная Steam-цена
```

### Database Changes

```sql
games(steam_appid UNIQUE, name, header_image, release_status, release_date)
game_source_states(game_id, source, last_attempt_at, last_success_at,
                   next_refresh_at, status, last_error,
                   UNIQUE(game_id, source))
current_game_prices(game_id, source, offer_kind, min_price_rub,
                    avg_price_rub, offer_count, cheapest_offer_*,
                    popular_offer_*, observed_at,
                    UNIQUE(game_id, source, offer_kind))
game_price_observations(game_id, source, offer_kind, min_price_rub,
                        offer_*, observed_at)
favorites ADD game_id NULL REFERENCES games(id) ON DELETE SET NULL
```

## Affected Files

| File | Action | Purpose |
|------|--------|---------|
| `backend/database/migrations/*_create_canonical_game_price_model.php` | Create | Создать схему, ограничения, nullable-связь и выполнить безопасный backfill. |
| `backend/app/Models/Game.php` | Create | Каноническая игра и связи. |
| `backend/app/Models/GameSourceState.php` | Create | Состояние обновления источника. |
| `backend/app/Models/CurrentGamePrice.php` | Create | Актуальная цена по источнику и виду. |
| `backend/app/Models/GamePriceObservation.php` | Create | Историческое ценовое наблюдение. |
| `backend/app/Models/Favorite.php` | Modify | Добавить связь с `Game`, сохранив прежнее API-поведение. |
| `backend/tests/Feature/CanonicalGamePriceModelTest.php` | Create | Проверить схему, связи, уникальность и backfill на legacy-данных. |

## Security Considerations

- **Подмена цен клиентом**: новые ценовые таблицы не получают публичных write-endpoint; записывать их смогут только серверные процессы.
- **Небезопасные URL предложений**: на этом этапе URL только хранятся; перед выдачей клиенту в следующих work items сохраняется существующая серверная нормализация.

## Integration Points

| System | Type | Purpose |
|--------|------|---------|
| PostgreSQL | Схема и backfill | Каноническое хранение общих данных. |
| Existing favorites | Nullable foreign key | Переход без удаления `appid` и без изменения API. |
| Existing price snapshots | Read-only migration source | Перенос только однозначной Steam-цены. |
| Future refresh/search/alerts | Eloquent relations | Единая база для следующих work items. |

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Дубли игр при переносе | High | Уникальный `steam_appid`, upsert и повторяемая логика связывания. |
| Ошибочная история маркетплейсов | High | Не переносить объединённое `market_min_rub`; начинать раздельную историю с новых наблюдений. |
| Долгая миграция на существующей БД | Medium | Обрабатывать данные порциями, использовать индексы и не обращаться к внешним сервисам. |
| Неограниченный рост истории | Medium | Индекс по игре/источнику/виду/времени и 90-дневная политика очистки в work item планировщика. |
| Неизвестный вид предложения | Medium | Сохранять `other`; `account` и `rent` не активировать для алертов по умолчанию. |
| Откат после появления новых данных | Medium | Добавочный этап, резервная копия перед production rollback, старые таблицы пока не удаляются. |

## Implementation Checklist

- [ ] Создать обратимую миграцию четырёх канонических таблиц и nullable `favorites.game_id`.
- [ ] Добавить ограничения допустимых значений, внешние ключи, уникальности и индексы.
- [ ] Реализовать модели, casts, доменные константы и связи.
- [ ] Выполнить идемпотентный backfill игр из `favorites` и `search_histories`.
- [ ] Связать существующие записи `favorites` с созданными играми.
- [ ] Перенести последнее достоверное Steam-наблюдение, не создавая market-историю.
- [ ] Добавить тесты чистой схемы, legacy-backfill, дубликатов и некорректных значений.
- [ ] Зафиксировать совместимость старых таблиц и 90-дневную политику истории.

---
*Generated by specs.md - fabriqa.ai FIRE Flow | Checkpoint 1 approved: 2026-07-25T13:57:55Z*
