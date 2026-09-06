<?php

return [
    'enabled' => (bool) env('ERP_DEMO_DATA_ENABLED', false),
    'allowed_environments' => ['local', 'staging', 'testing'],
    'allowed_database' => env('ERP_DEMO_DATABASE_NAME'),
    'confirmation' => 'STAGING-DEMO',
    'password' => env('ERP_DEMO_USER_PASSWORD'),
    'profile' => 'full',
    'marker' => '[DEMO:staging-v1]',
    'version' => 'staging-v1',
    'lock_seconds' => 3600,
    'require_confirmation_in_tests' => false,
];
