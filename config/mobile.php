<?php

return [
    // Inclusive sellable-unit thresholds, configured on the ERP server.
    'low_stock_threshold' => max(1, (int) env('MOBILE_LOW_STOCK_THRESHOLD', 2)),
    'critical_stock_threshold' => max(0, (int) env('MOBILE_CRITICAL_STOCK_THRESHOLD', 1)),
    'push_enabled' => (bool) env('MOBILE_PUSH_ENABLED', false),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
];
