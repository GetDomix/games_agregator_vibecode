<?php

return [
    'http_timeout' => (float) env('HTTP_TIMEOUT_SECONDS', 20),
    'plati_max_pages' => (int) env('PLATI_MAX_PAGES', 5),
    'plati_page_size' => (int) env('PLATI_PAGE_SIZE', 100),
    'ggsel_limit' => (int) env('GGSEL_LIMIT', 100),
    'digiseller_partner_id' => env('DIGISELLER_PARTNER_ID', ''),
    'ads_enabled' => filter_var(env('ADS_ENABLED', true), FILTER_VALIDATE_BOOL),
    'ads_contact_email' => env('ADS_CONTACT_EMAIL', 'ads@example.com'),
    'ads_label' => env('ADS_LABEL', 'Реклама'),
    'search_cache_ttl' => (int) env('SEARCH_CACHE_TTL', 900),
    'http_max_retries' => (int) env('HTTP_MAX_RETRIES', 2),
    'watchlist_refresh_max' => (int) env('WATCHLIST_REFRESH_MAX', 5),
    'price_refresh_interval_hours' => (int) env('PRICE_REFRESH_INTERVAL_HOURS', 3),
    'announced_steam_refresh_hours' => (int) env('ANNOUNCED_STEAM_REFRESH_HOURS', 24),
    'price_refresh_dispatch_batch' => (int) env('PRICE_REFRESH_DISPATCH_BATCH', 100),
    'price_refresh_queue' => env('PRICE_REFRESH_QUEUE', 'prices'),
    'price_source_budgets' => [
        'steam' => (int) env('PRICE_SOURCE_STEAM_PER_MINUTE', 30),
        'plati' => (int) env('PRICE_SOURCE_PLATI_PER_MINUTE', 10),
        'ggsel' => (int) env('PRICE_SOURCE_GGSEL_PER_MINUTE', 15),
    ],
    'steam_price_regions' => [
        ['region' => 'RU', 'country' => 'ru', 'language' => 'russian', 'currency' => 'RUB', 'label' => 'Россия'],
        ['region' => 'US', 'country' => 'us', 'language' => 'english', 'currency' => 'USD', 'label' => 'США'],
        ['region' => 'KZ', 'country' => 'kz', 'language' => 'russian', 'currency' => 'KZT', 'label' => 'Казахстан'],
        ['region' => 'TR', 'country' => 'tr', 'language' => 'english', 'currency' => 'TRY', 'label' => 'Турция'],
        ['region' => 'DE', 'country' => 'de', 'language' => 'english', 'currency' => 'EUR', 'label' => 'Европа'],
    ],
    'price_refresh_backoff_minutes' => [1, 5, 15, 30, 60],
    // Comma-separated admin emails (also users.is_admin flag)
    'admin_emails' => env('ADMIN_EMAILS', ''),
    'brand_name' => env('BRAND_NAME', 'Игроскан'),

    // Telegram bot and shared-account authentication
    'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
    'telegram_oidc_client_id' => env('TELEGRAM_OIDC_CLIENT_ID', ''),
    'telegram_oidc_client_secret' => env('TELEGRAM_OIDC_CLIENT_SECRET', ''),
    'telegram_oidc_redirect_uri' => env('TELEGRAM_OIDC_REDIRECT_URI', ''),
    'radar_service_token' => env('RADAR_SERVICE_TOKEN', ''),
];
