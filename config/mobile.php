<?php

return [
    'minimum_runtime_version' => max(1, (int) env('MOBILE_MINIMUM_RUNTIME_VERSION', 1)),
    'app' => [
        'latest_version' => env('MOBILE_APP_LATEST_VERSION', '1.4.0'),
        'latest_build' => max(0, (int) env('MOBILE_APP_LATEST_BUILD', 0)),
        'minimum_build' => max(0, (int) env('MOBILE_APP_MINIMUM_BUILD', 0)),
        'update_required' => (bool) env('MOBILE_APP_UPDATE_REQUIRED', false),
        'download_url' => env('MOBILE_APP_DOWNLOAD_URL', 'https://tpzerp.cloud/downloads/tpz-erp.apk'),
        'message' => env('MOBILE_APP_UPDATE_MESSAGE', 'A newer version of Tech Point Zone is available.'),
    ],
    // Inclusive sellable-unit thresholds, configured on the ERP server.
    'low_stock_threshold' => max(1, (int) env('MOBILE_LOW_STOCK_THRESHOLD', 2)),
    'critical_stock_threshold' => max(0, (int) env('MOBILE_CRITICAL_STOCK_THRESHOLD', 1)),
    'stock_alerts' => [
        'low_stock_reminder_hours' => [24],
        'out_of_stock_reminder_hours' => [2, 24, 48],
        'out_of_stock_escalation_hours' => 48,
    ],
    'push_enabled' => (bool) env('MOBILE_PUSH_ENABLED', false),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
];
