# Test Report: Production scheduler и заполнение цен

- Laravel/PostgreSQL: 41 passed, 163 assertions.
- Targeted refresh/search tests: 8 passed, 35 assertions.
- `docker compose config --quiet`: passed.
- Frontend production build: passed.

Acceptance validation: scheduler and worker are declared separately; released Steam state creates missing Plati/GGsel pending states; announced marketplace dispatch remains blocked; stored search remains asynchronous.
