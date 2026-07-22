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
    'free_searches_per_day' => (int) env('FREE_SEARCHES_PER_DAY', 15),
    'guest_searches_per_day' => (int) env('GUEST_SEARCHES_PER_DAY', 5),
    // null / 0 = unlimited for Pro
    'pro_searches_per_day' => env('PRO_SEARCHES_PER_DAY') !== null && env('PRO_SEARCHES_PER_DAY') !== ''
        ? (int) env('PRO_SEARCHES_PER_DAY')
        : null,
    // MVP-friendly prices (subscription must feel cheap vs daily free limit)
    'pro_price_rub_month' => (int) env('PRO_PRICE_RUB_MONTH', 99),
    'pro_price_rub_year' => (int) env('PRO_PRICE_RUB_YEAR', 790),
    'search_cache_ttl' => (int) env('SEARCH_CACHE_TTL', 900),
    'http_max_retries' => (int) env('HTTP_MAX_RETRIES', 2),
    // Comma-separated: CODE:days, e.g. KEYSIGNAL-PRO:30,VIP2026:365
    'promo_codes' => env('PROMO_CODES', 'KEYSIGNAL-PRO:30,KEYSIGNAL-YEAR:365'),
    'billing_contact_email' => env('BILLING_CONTACT_EMAIL', env('ADS_CONTACT_EMAIL', 'ads@example.com')),
    'watchlist_refresh_max' => (int) env('WATCHLIST_REFRESH_MAX', 5),
    // Comma-separated admin emails (also users.is_admin flag)
    'admin_emails' => env('ADMIN_EMAILS', ''),
    'brand_name' => env('BRAND_NAME', 'Игроскан'),
];
