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
    'watchlist_refresh_max' => (int) env('WATCHLIST_REFRESH_MAX', 5),
];
