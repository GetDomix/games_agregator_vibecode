---
work_item: free-search-monetization-cleanup
intent: unified-price-watchlist-mvp
created: 2026-07-25T19:40:25Z
mode: validate
checkpoint_1: approved
---

# Design: Бесплатный поиск и удаление Pro

## Summary

Полностью удалить продуктовую модель Pro, подписки, промокоды и дневные поисковые квоты. Поиск остаётся бесплатным для гостей и авторизованных пользователей, а защита API обеспечивается только техническим Laravel rate limit. Реклама сохраняется как нейтральный блок после полного списка результатов и в подвале, не скрывается для отдельных планов и не влияет на предложения.

## Scope

**In Scope:**

- Удаление тарифов, billing-заготовок, промокодов и административного управления планами.
- Удаление дневных квот из API, backend-логики, базы данных и интерфейса.
- Удаление полей плана пользователя из базы данных, модели и публичного JSON.
- Новая обратимая по структуре PostgreSQL migration без изменения старых migrations.
- Бесплатный поиск с сохранением существующих технических `throttle`-ограничений.
- Только два рекламных размещения: после всех результатов и в подвале.
- Регрессионная проверка авторизации, избранного, истории и общего Telegram-аккаунта.

**Out of Scope:**

- Платежи, подписки, новые тарифы и замена Pro другой монетизацией.
- Новые рекламные или партнёрские механики.
- Изменение источников цен, алгоритма ранжирования или состава предложений.
- Изменение Telegram-бота, если он не потребляет удаляемые поля API.
- Исправление визуальной карточки бота и отдельный полный release-readiness аудит.
- Архивный Python-код в `legacy/`, который не входит в текущий Laravel/React deploy.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Доступ к поиску | Бесплатный для гостя и пользователя; только route-level `throttle` | Дневная бизнес-квота больше не нужна, но техническая защита от спама остаётся. |
| Устаревшие endpoints | Удалить маршруты, чтобы они отвечали `404` | Это явно завершает billing/quota API вместо сохранения мёртвых заглушек. |
| Ответ `/api/prices` | Удалить объект `quota` и любое потребление дневного счётчика | Клиент больше не должен знать о тарифах или дневном лимите. |
| Публичный пользователь | Удалить `plan`, `plan_label`, `plan_expires_at` | Сайт и бот работают с единым аккаунтом без уровня подписки. |
| Хранилище | Новой migration удалить `daily_search_quotas`, `users.plan`, `users.plan_expires_at` | История применённых migrations остаётся неизменной и production обновляется штатно. |
| Откат migration | Восстановить только таблицу и столбцы с прежними defaults | Удалённые значения планов и квот восстановить невозможно; это явно документируется. |
| Админка | Удалить Pro/квотные метрики и изменение плана, сохранить управление администраторами | Админка не должна поддерживать удалённую продуктовую модель. |
| Реклама | Одинакова для всех; только `after_results` и `footer` | Реклама не мешает поиску и не создаёт скрытого преимущества платного плана. |
| Честность выдачи | Партнёрский ID и реклама не участвуют в выборке, сортировке или выделении офферов | Сохраняется согласованная нейтральность основного списка предложений. |

## Data Models Affected

### Modifies

- **User**: удалить `plan` и `plan_expires_at` из fillable/casts и удалить методы `hasActivePro`, `dailySearchLimit`, `planLabel` — модель аккаунта больше не содержит подписку.
- **DailySearchQuota**: удалить Eloquent-модель и таблицу `daily_search_quotas` — дневной бизнес-лимит прекращает существовать.

## Technical Approach

### Architecture

```
Guest / authenticated user
          |
          v
GET /api/prices -- Laravel throttle --> stored-price search --> PostgreSQL prices
          |                                      |
          |                                      +--> auth history / favorite state
          v
React renders complete honest result list
          |
          +--> neutral ad after results
          +--> neutral footer ad

Removed path:
plans / billing / promo / daily quota / user plan / admin plan controls
```

### API Changes

- `GET /api/quota` — удалить маршрут; ожидаемый ответ после релиза: `404`.
- `GET /api/plans` — удалить маршрут; ожидаемый ответ после релиза: `404`.
- `POST /api/billing/request` — удалить маршрут; ожидаемый ответ после релиза: `404`.
- `POST /api/billing/promo` — удалить маршрут; ожидаемый ответ после релиза: `404`.
- `POST /api/admin/users/{id}/plan` — удалить маршрут и метод контроллера; ожидаемый ответ: `404`.
- `GET /api/prices` — оставить `throttle:20,1`, удалить дневную квоту и поле `quota` из ответа.
- `GET /api/auth/me` и auth-ответы — удалить `plan`, `plan_label`, `plan_expires_at`.
- `GET /api/admin/overview` — удалить `pro_active`, `searches_today`, `promo_codes` и `recent_users[].plan`.
- `GET /api/ads/config` — возвращать только размещения `after_results` и `footer`, без `hidden_for_pro` и Pro-текста.

### Database Changes

```sql
-- up
DROP TABLE IF EXISTS daily_search_quotas;
ALTER TABLE users DROP COLUMN IF EXISTS plan_expires_at;
ALTER TABLE users DROP COLUMN IF EXISTS plan;

-- down (structure only; deleted values are not recoverable)
ALTER TABLE users ADD COLUMN plan VARCHAR(32) NOT NULL DEFAULT 'free';
ALTER TABLE users ADD COLUMN plan_expires_at TIMESTAMP NULL;
CREATE TABLE daily_search_quotas (... прежняя структура и индексы ...);
```

## Dependencies

- Завершённый work item `stored-price-search`: `/api/prices` должен продолжить читать серверное хранилище.
- Существующие auth, favorites, history и Telegram identity endpoints остаются источником общего аккаунта.
- Laravel route throttling остаётся единственным пользовательским ограничением поиска.

## Affected Files

| File | Action | Purpose |
|------|--------|---------|
| `backend/app/Http/Controllers/Api/BillingController.php` | Delete | Удалить планы, ручной checkout и промокоды. |
| `backend/app/Models/DailySearchQuota.php` | Delete | Удалить модель дневного лимита. |
| `backend/routes/api.php` | Modify | Удалить quota/billing/admin-plan routes, сохранить technical throttles. |
| `backend/app/Http/Controllers/Api/PriceController.php` | Modify | Удалить чтение/потребление квоты и поле ответа. |
| `backend/app/Services/StoredPriceSearchService.php` | Modify | Удалить устаревшее поле `quota` из stored response. |
| `backend/app/Services/AggregatorService.php` | Modify | Удалить устаревшее поле `quota` из совместимого response shape. |
| `backend/app/Models/User.php` | Modify | Удалить plan-поля, методы и публичный JSON. |
| `backend/app/Http/Controllers/Api/AdminController.php` | Modify | Удалить Pro/квотные метрики и управление планами. |
| `backend/app/Http/Controllers/Api/AdsController.php` | Modify | Ограничить рекламу двумя нейтральными размещениями. |
| `backend/config/gpa.php` | Modify | Удалить конфигурацию квот, Pro, цен и промокодов. |
| `backend/.env.example` | Modify | Удалить устаревшие переменные окружения. |
| `docker-compose.yml` | Modify | Удалить передачу дневных лимитов в backend. |
| `README.md` | Modify | Удалить документацию Pro, промокода и admin plan API. |
| `backend/database/migrations/2026_07_25_194100_remove_pro_and_search_quotas.php` | Create | Удалить устаревшую схему с документированным структурным rollback. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | Create | Зафиксировать бесплатный поиск, удалённые endpoints и сохранность аккаунта. |
| `frontend/src/App.tsx` | Modify | Удалить тарифы, квоты, Pro-копирайтинг и admin plan controls; перенести рекламу. |
| `frontend/src/api.ts` | Modify | Удалить plan-поля из типа пользователя. |
| `frontend/src/styles.css` | Modify | Удалить стили тарифов/квот и оставить корректные рекламные блоки. |

## Security Considerations

- **Защита от массовых запросов**: сохранить существующие `throttle:30,1` для поиска кандидатов и `throttle:20,1` для цен.
- **Миграция production данных**: проверять наличие таблицы и столбцов перед удалением; не менять применённые старые migrations.
- **Публичный контракт аккаунта**: убедиться, что auth, Telegram merge, favorites и history не зависят от удалённых колонок.
- **Честность предложений**: тестом и review подтвердить, что ad/partner config не передаётся в сортировку офферов.

## Integration Points

| System | Type | Purpose |
|--------|------|---------|
| React frontend | HTTP API | Перестать запрашивать plans/quota/billing и отображать Pro. |
| Telegram bot | Shared backend account | Убедиться, что удаление plan-полей не ломает session/account payload. |
| PostgreSQL | Laravel migration | Удалить quota table и plan columns безопасно на существующей и чистой базе. |
| Ads config | Public HTTP API | Отдать только нейтральные post-results/footer slots. |

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Существующий код читает удалённые plan-поля | High | Полный `rg` по backend/frontend/bot, feature tests auth/favorites/history/Telegram session. |
| `/api/prices` всё ещё возвращает или считает quota | High | Contract test с двумя запросами при старом limit config `1` и assert отсутствия `quota`. |
| Migration ломает существующую PostgreSQL базу | High | `Schema::hasTable/hasColumn`, migrate на текущей схеме и `migrate:fresh` на чистой PostgreSQL. |
| Rollback создаёт ложное ожидание восстановления данных | Medium | Явно отметить structure-only rollback в migration и test report. |
| Реклама остаётся внутри выдачи или наверху | Medium | API contract test слотов и browser check расположения после полного result section. |
| Frontend сохраняет скрытые Pro-ветки | Medium | Удалить типы/state/API calls/CSS, выполнить lint/build и статический поиск запрещённых строк. |
| Удаление admin metrics ломает таблицу админки | Medium | Синхронно изменить backend payload и frontend types/rendering; проверить admin view. |

## Implementation Checklist

- [ ] Сначала добавить failing feature tests для бесплатного поиска, удалённых endpoints, публичного user contract, ad slots и сохранности auth/favorites/history.
- [ ] Добавить новую migration удаления `daily_search_quotas` и plan-колонок с structure-only rollback.
- [ ] Удалить BillingController, DailySearchQuota и соответствующие routes.
- [ ] Удалить quota-логику и response fields из PriceController и сервисов формирования ответа.
- [ ] Удалить plan-логику из User и Pro/квотные операции из AdminController.
- [ ] Ограничить ads config размещениями `after_results` и `footer`.
- [ ] Удалить quota/plan/billing UI, types, state, API calls и CSS; разместить рекламу после всех результатов.
- [ ] Удалить устаревшие config/env/Compose параметры и документацию Pro.
- [ ] Выполнить backend suite, PostgreSQL migration checks, frontend lint/build и browser smoke scenarios.
- [ ] Выполнить FIRE code review с отдельной проверкой отсутствия Pro/quota ссылок и влияния рекламы на выдачу.

---
*Generated by specs.md - FIRE Flow | Checkpoint 1 approved: 2026-07-25T19:40:25Z*
