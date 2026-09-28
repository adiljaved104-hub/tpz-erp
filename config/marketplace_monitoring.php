<?php

return [
    'enabled' => (bool) env('MARKETPLACE_MONITORING_ENABLED', true),
    'interval_minutes' => max(5, (int) env('MARKETPLACE_MONITORING_INTERVAL_MINUTES', 15)),
    'batch_size' => max(1, min(500, (int) env('MARKETPLACE_MONITORING_BATCH_SIZE', 100))),
    'reminder_hours' => [2, 24],
    'escalation_hours' => 24,
    'summary_time' => env('MARKETPLACE_MONITORING_SUMMARY_TIME', '08:00'),
    'amazon' => [
        'enabled' => (bool) env('AMAZON_SPAPI_ENABLED', false),
        'endpoint' => env('AMAZON_SPAPI_ENDPOINT'),
        'marketplace_id' => env('AMAZON_SPAPI_MARKETPLACE_ID'),
        'seller_id' => env('AMAZON_SPAPI_SELLER_ID'),
        'lwa_client_id' => env('AMAZON_SPAPI_LWA_CLIENT_ID'),
        'lwa_client_secret' => env('AMAZON_SPAPI_LWA_CLIENT_SECRET'),
        'refresh_token' => env('AMAZON_SPAPI_REFRESH_TOKEN'),
        'lwa_endpoint' => 'https://api.amazon.com/auth/o2/token',
    ],
];
