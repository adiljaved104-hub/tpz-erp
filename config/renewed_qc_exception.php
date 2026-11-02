<?php

return [
    // Safe default: no employee may bypass Renewed QC until Owner activates this
    // time-limited exception on the specific deployment.
    'enabled' => (bool) env('RENEWED_QC_EXCEPTION_ENABLED', false),

    // Required when enabled. Example: 2026-11-09T23:59:59+05:00
    'expires_at' => env('RENEWED_QC_EXCEPTION_EXPIRES_AT'),
];
