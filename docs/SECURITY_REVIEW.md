# Security review: административный контур

Дата инженерной проверки: 9 августа 2026 года.

Это внутренняя инженерная проверка архитектуры и автоматизированных регрессий. Она не является сертификацией безопасности и не заменяет внешний penetration test.

## Область проверки

Проверены административные REST endpoints, модель ролей `user|admin|owner`, аварийные владельцы из `ADMIN_EMAILS`, аудит действий, ограничения частоты, безопасные проекции пользователей и транзакционное изменение ролей. Внешние сервисы и production-инфраструктура активному сканированию не подвергались.

## Границы доверия

1. **Браузер → Laravel/Sanctum.** Браузер передаёт session/cookie или bearer token. Laravel обязан заново проверить аутентификацию, эффективную роль и rate limit на каждом admin-запросе; скрытие элементов интерфейса не считается контролем доступа.
2. **Telegram-бот → internal token API.** Сервисный токен бота является секретом с правами своего API-контура. Он не должен попадать в браузер, логи, ответы или репозиторий.
3. **Laravel → PostgreSQL и очередь.** База обеспечивает ограничение домена ролей, блокировки владельцев и атомарность роли, отзыва токенов и аудита. Очередь получает только проверенные задания штатного обновления источников.
4. **Laravel → внешние магазины и источники цен.** Ответы Steam, Plati и GGsel недоверенные: ошибки, задержки и некорректные данные не должны расширять административные права или раскрывать секреты.
5. **Reverse proxy → Laravel.** Caddy/Cloudflare завершают TLS. Production origin должен быть закрыт от прямого доступа, поскольку приложение доверяет proxy-заголовкам на этой границе.

## Результаты

### Critical

Подтверждённых Critical-находок в проверенной области нет.

### High

Подтверждённых High-находок в проверенной области нет.

### Medium

| Находка | Свидетельство | Статус |
|---|---|---|
| Role-аудит был доступен обычному администратору через `overview.recent_audit`, несмотря на фильтр отдельного audit endpoint | Регрессия `test_admin_overview_hides_role_change_audit_but_owner_overview_includes_it` | Исправлено: overview и audit используют один viewer-aware scope |
| Ошибка admin endpoint при `APP_DEBUG=true` могла зависеть от стандартного debug renderer | Fault-injection создаёт реальную ошибку PostgreSQL и проверяет отсутствие trace, SQL, паролей, email владельцев и Telegram ID | Усилено: admin API возвращает фиксированные JSON-сообщения и сохраняет HTTP status/headers |
| Некорректные или дополнительные поля write-запросов могли создать неоднозначный контракт | Табличные тесты mass assignment, nested/null/unknown roles и malformed `sources` | Исправлено: строгий allowlist ключей, `array:list` и allowlist источников |
| Сохранённый `GameSourceState.last_error` мог содержать URL, credentials или body внешнего исключения и возвращался через overview | Sentinel-регрессия для `recent_source_failures` и `recordFailure` | Исправлено: в API и состоянии хранится только стабильный код `source_refresh_failed`; класс ошибки остаётся в защищённом журнале worker |

### Low / defense in depth

| Находка | Свидетельство | Статус |
|---|---|---|
| Внутренний вызов role service полагался на HTTP validation и PostgreSQL CHECK | Прямой service-boundary тест неизвестной роли | Исправлено: домен роли проверяется внутри сервиса |
| Требовалось прямое доказательство атомарности при отказе аудита | PostgreSQL constraint fault-injection | Подтверждено: изменение роли и удаление токенов откатываются |
| Требовалось прямое доказательство upgrade/rollback роли | PostgreSQL CHECK и migration rollback tests | Подтверждено для `admin`, `owner` и недопустимого значения |
| Метод или ID можно пытаться менять вне опубликованного контракта | Tests для POST/PUT/DELETE/PATCH variants и неизвестного user ID | Подтверждено: 404/405 без изменения роли, очереди или аудита |

## Подтверждённые защитные свойства

- Guest и `user` получают отказ на административных read/write routes; `admin` не управляет командой, `owner` управляет.
- Read, operational write и role-change используют отдельные лимиты 120, 20 и 5 запросов в минуту, привязанные к аутентифицированному пользователю.
- Последний эффективный владелец и владелец из `ADMIN_EMAILS` не могут быть понижены. Конкурентное понижение двух владельцев сохраняет хотя бы одного.
- Любой переход в роль `owner` или из неё требует текущий пароль владельца-инициатора.
- Изменение роли, отзыв токенов целевого пользователя и UUID-аудит выполняются в одной транзакции PostgreSQL.
- Ответы users/team/audit используют явные безопасные проекции и не содержат password hash, remember token, personal access tokens или Telegram chat ID.
- Поиск экранирует `%`, `_` и `\\` как литералы; SQL payload не расширяет выдачу.
- Старый boolean endpoint и поле `is_admin` удалены из рабочего API-контракта.

## Остаточные риски

| Риск | Уровень | Решение / следующая мера |
|---|---|---|
| Кража bearer token или активной browser session | High | TLS, безопасное хранение cookies/tokens, короткие сессии, отзыв токенов при смене роли; добавить MFA и оповещения владельцев до публичного запуска |
| Компрометация аккаунта или почты владельца из `ADMIN_EMAILS` | High | Минимальный allowlist, отдельный защищённый аккаунт, MFA у провайдера входа, регулярный пересмотр списка |
| Новые уязвимости зависимостей после даты проверки | Medium | `npm audit --omit=dev` и повторный OSV Scanner для 118 Composer-пакетов завершены без находок. До каждого production deploy сохранить обязательный audit-gate в CI |
| Poisoning/некорректные данные внешних API цен | Medium | Валидация схемы и диапазонов, наблюдаемость источников, отсутствие прямого редактирования цены из admin UI |
| Отсутствие внешнего penetration testing | Medium | Провести отдельный тест staging/production boundary перед публичным запуском; этот документ не является сертификатом |
| Прямой доступ к origin в обход доверенного proxy | Medium | Firewall/Cloudflare Tunnel должен разрешать origin только доверенному proxy; проверить инфраструктуру при release readiness |

## Dependency gate и ограничение среды

Первичный независимый OSV Scanner обнаружил 8 advisories в двух production-зависимостях: `guzzlehttp/guzzle` 7.15.1 — GHSA-f7vp-7xgx-4w4r (Medium) и GHSA-v5mv-p594-2x33 (High); `league/commonmark` 2.8.3 — GHSA-29pj-957v-52mc (Medium), GHSA-2q4p-g7hv-5rgv, GHSA-g2gp-3wwq-f4ph, GHSA-jfm3-95jq-q3rf и GHSA-mh25-x5hq-wrqp (High), GHSA-mj63-m3rc-8ppr (Medium). Оба пакета приходят через `laravel/framework`; Guzzle обслуживает исходящие HTTP-запросы, CommonMark — framework-функции Markdown. Прямого пользовательского Markdown-контракта в проверенной области нет, но зависимости всё равно считались затронутыми без попытки понизить риск только по текущей достижимости.

Исправление: точечное обновление до `guzzlehttp/guzzle` 7.15.2 и `league/commonmark` 2.9.0 без изменения остальных пакетов. Повторный официальный OSV Scanner проверил 118 пакетов и завершился с `No issues found`; Composer dry-run подтвердил воспроизводимость lock-файла, а production Docker build установил обе новые версии.

10 августа 2026 года после устранения блокировки Packagist со стороны локального VPN успешно выполнен `composer audit --locked --no-interaction`: exit code 0, известных advisories для зафиксированных зависимостей не найдено. Команда добавлена в backend CI gate и должна успешно завершаться перед каждым будущим production release.

## Воспроизводимая проверка

Backend на машине с PHP и PostgreSQL test database:

```bash
cd backend
php artisan test --filter='AdminSecurityTest|AdminAuthorizationTest|AdminRoleManagementTest|AdminRoleMigrationTest'
php artisan test
composer audit --locked --no-interaction
```

Frontend:

```bash
cd frontend
npm test
npm run lint
npm run build
npm audit --omit=dev
```

Bot и Compose:

```bash
cd bot
python -m unittest discover -s tests -v
cd ..
docker compose config --quiet
```

В этом репозитории backend suite также воспроизводится через локальный образ:

```bash
docker run --rm --network igroscan_default \
  -v "$PWD/backend:/var/www/html" -w /var/www/html \
  --entrypoint php igroscan-backend \
  vendor/bin/phpunit -c phpunit.task3.xml
```

## Проверка готовности выпуска — 9 августа 2026 года

### Upgrade-shaped миграция PostgreSQL

В отдельной базе `sdd_upgrade_task7` миграции сначала выполнены только по `2026_07_22_180000_add_users_is_admin.php`. После добавления обычного пользователя, legacy-администратора и пользователя из `ADMIN_EMAILS` выполнены оставшиеся миграции по `2026_08_09_131000` включительно. Проверка через реальный Laravel HTTP kernel и bearer tokens подтвердила:

- legacy `is_admin=true` преобразован в `admin_role=admin`, `/api/admin/overview` вернул 200;
- настроенный через `ADMIN_EMAILS` пользователь получил эффективную роль `owner`, `/api/admin/team` вернул 200;
- обычный пользователь получил 403 от `/api/admin/overview`.

### Финальные автоматизированные доказательства

- Backend: 144 теста, 810 assertions — PASS после обновления зависимостей.
- Frontend: 22 теста — PASS; lint — exit 0 с одним существующим Fast Refresh warning в `brand.tsx`; production build — PASS, 435 modules; `npm audit --omit=dev` — 0 уязвимостей.
- Bot: 29 тестов — PASS в Compose-образе.
- Dependencies: OSV Scanner — 118 Composer-пакетов, `No issues found`; `composer install --dry-run` — PASS. Ограничение прямого Composer audit описано выше.
- Deployment: `docker compose build backend frontend scheduler queue-worker` — PASS после dependency fix; образы установили Guzzle 7.15.2 и CommonMark 2.9.0. `docker compose config --quiet` — PASS.
- Миграционный, dependency и deployment gate не выполняли push и не меняли production-инфраструктуру.
