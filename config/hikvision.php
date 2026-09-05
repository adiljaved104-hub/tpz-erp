<?php

return [
    'enabled' => filter_var(
        env('HIKVISION_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'scheduled_sync_enabled' => filter_var(
        env('HIKVISION_SCHEDULED_SYNC_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'scheme' => env('HIKVISION_SCHEME', 'http'),
    'host' => env('HIKVISION_HOST'),
    'port' => (int) env('HIKVISION_PORT', 80),

    'verify_ssl' => filter_var(
        env('HIKVISION_VERIFY_SSL', true),
        FILTER_VALIDATE_BOOL
    ),

    'username' => env('HIKVISION_USERNAME'),
    'password' => env('HIKVISION_PASSWORD'),

    'timeout' => (int) env('HIKVISION_TIMEOUT', 10),

    'sync_overlap_minutes' => (int) env('HIKVISION_SYNC_OVERLAP_MINUTES', 10),

    'sync_end_lag_seconds' => (int) env('HIKVISION_SYNC_END_LAG_SECONDS', 60),

    'sync_interval_minutes' => (int) env('HIKVISION_SYNC_INTERVAL_MINUTES', 5),

    'first_sync_lookback_hours' => 8,

    'page_size' => 100,
    'maximum_pages' => 200,
    'maximum_backfill_days' => 31,

    'source' => 'hikvision_isapi',

    // Zero asks AcsEvent for all event codes.
    // Employee authentication payload structure is then classified locally;
    // major/minor remain audit metadata.
    'event_query_major' => 0,
    'event_query_minor' => 0,
];
