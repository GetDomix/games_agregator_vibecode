---
run: run-agregator-games-vibecoding-008
work_item: telegram-bot-shared-interface
intent: unified-price-watchlist-mvp
mode: confirm
checkpoint: plan
checkpoint_state: awaiting_approval
---

# Implementation Plan: Полноценный интерфейс Telegram-бота

## Approach

1. Добавить защищённый внутренний API Laravel для бота. Каждый запрос будет проверять `X-Radar-Token`, а пользователь — определяться по стабильному Telegram user id, не по username.
2. При первом `/start` создавать Telegram-only аккаунт в общей таблице `users` и identity `provider=telegram`. Если этот Telegram уже связан с сайтом, использовать существующий аккаунт; конфликт не будет автоматически переносить данные между пользователями.
3. Оставить поиск и бизнес-правила в Laravel: бот получает кандидатов через общий поиск, карточку — из canonical price storage, а неизвестная игра только ставится в фоновую очередь. Для `announced` Laravel возвращает пустые Plati/GGsel.
4. Через тот же backend дать боту список избранного, добавление/обновление/удаление игры, выбор Steam/Plati/GGsel и типов `key`, `gift`, `account`, `rent`, одну целевую цену и повторную активацию alert-а.
5. Построить aiogram-интерфейс на командах и inline-кнопках: `/search`, `/favorites`, `/alerts`, `/help`; текст без команды также сможет начинать поиск. После ввода бот покажет `typing`, затем отправит фото-карточку в визуальном языке сайта (тёмная графитовая основа, coral/amber градиент, Steam/Plati/GGsel цвета) с обложкой, ценами, свежестью и статусом релиза.
6. Сгенерировать фото локально из уже полученных данных: Pillow соберёт PNG без браузера, внешнего рендеринга или новой цены. Полные названия предложений, ссылки, предупреждения и действия останутся в сообщении под фото, чтобы карточка не превращалась в мелкий нечитаемый скриншот.
7. Для настройки alert-а использовать короткий FSM-диалог: выбор scopes кнопками → ввод целевой цены → сохранение. FSM хранит только незавершённый диалог в памяти процесса, но не является копией избранного или аккаунта.
8. Активные и сработавшие alert-ы всегда читать из Laravel. Кнопка «Активировать снова» вызывает общий rearm; изменения сразу видны сайту.
9. Ограничить сценарии личным чатом, экранировать пользовательский текст в HTML-сообщениях, обрабатывать таймауты/ошибки backend понятным сообщением и не обращаться к реальному Telegram API в тестах.
10. Не запускать из Python обновление цен. Удалить устаревшее описание Steam-only радара и неиспользуемую настройку bot-trigger из Compose/документации; расписанием продолжает владеть Laravel.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/app/Http/Middleware/EnsureRadarServiceToken.php` | Единая constant-time проверка сервисного токена для внутренних bot endpoints. |
| `backend/app/Http/Controllers/Api/TelegramBotController.php` | Session, поиск, карточка, избранное и alert-команды для тонкого Telegram-клиента. |
| `backend/app/Services/TelegramBotUserService.php` | Безопасное создание/разрешение Telegram identity и общего пользователя. |
| `backend/tests/Feature/TelegramBotInterfaceTest.php` | Интеграционный контракт bot API, общие данные сайта/бота и ограничения announced/unknown. |
| `bot/ui.py` | Форматирование карточек/алертов и построение inline-клавиатур. |
| `bot/card_renderer.py` | Локальный PNG-рендер карточки в палитре сайта из ответов Laravel. |
| `bot/tests/test_api_client.py` | Изолированные тесты HTTP-клиента без Laravel и Telegram. |
| `bot/tests/test_ui.py` | Тесты экранирования, карточек и callback-клавиатур. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/routes/api.php` | Группа `/internal/telegram/*` с service auth и rate limits. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Использовать общий middleware для совместимых bind/run endpoint-ов без изменения старых URL. |
| `bot/api_client.py` | Типизированные методы session/search/card/favorites/alerts/rearm, единый timeout и нормализованные API-ошибки. |
| `bot/main.py` | Команды, callback handlers и FSM настройки scopes/целевой цены. |
| `bot/requirements.txt` | Добавить Pillow для локального рендера PNG-карточки. |
| `bot/Dockerfile` | Копировать новые runtime-модули бота. |
| `bot/README.md` | Новый пользовательский флоу и актуальная ответственность Laravel scheduler. |
| `bot/.env.example` | Убрать неиспользуемый bot-trigger комментарий, оставить только реально читаемую конфигурацию. |
| `docker-compose.yml` | Убрать неиспользуемый `RADAR_TRIGGER_HOURS` у bot-контейнера; остальные production env не менять. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/TelegramBotInterfaceTest.php` | Service auth; Telegram-only account; повторный session; поиск/card; shared favorite; scopes/target; active/triggered/rearm; announced/unknown. |
| `bot/tests/test_api_client.py` | Заголовки, payload, успешные ответы, 401/404/422 и network timeout. |
| `bot/tests/test_ui.py` | HTML escaping, price/freshness/release rendering и корректный callback data. |
| `bot/tests/test_card_renderer.py` | PNG рендер, размер, fallback без обложки и announced-состояние. |

## Verification

| Scope | Command / Check |
|-------|-----------------|
| Backend | `php artisan test` на PostgreSQL. |
| Bot unit tests | `python -m unittest discover -s bot/tests -v`. |
| Visual renderer | Рендер fixture-карточки и ручная проверка PNG до отправки в Telegram. |
| Bot syntax | `python -m compileall -q bot`. |
| Containers | `docker compose config` и сборка `radar-bot` после изменения списка файлов. |
| Manual contract | Поиск → карточка → scopes/цель → избранное сайта → triggered/rearm; Telegram API при проверке не вызывается. |

## Acceptance Mapping

- Общий поиск и карточка: internal API переиспользует canonical Laravel services.
- Общие избранное и alert-ы: единственный источник истины — PostgreSQL через Laravel.
- Telegram до регистрации сайта: Telegram-only user + `ExternalIdentity`, позже объединяемый существующим OIDC flow.
- Неизвестная игра: состояние фонового наполнения без ожидания магазинов.
- `coming_soon`: Plati/GGsel отсутствуют в карточке и scopes начинают обновляться только после релиза.
- Нет отдельного сканера/хранилища Python: только HTTP-интерфейс и временный FSM.

---
*Awaiting plan approval. No application code has changed in this run.*
