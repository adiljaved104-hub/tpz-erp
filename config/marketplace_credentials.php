<?php

return [
    /*
     * Connection rows store only an opaque reference. Deployments may resolve
     * additional references here from encrypted/server-managed configuration.
     * Never place real marketplace credentials in source control.
     */
    'references' => [
        'noon_default' => [
            'enabled' => (bool) env('NOON_API_ENABLED', false),
            'key_id' => env('NOON_API_KEY_ID'),
            'project_code' => env('NOON_API_PROJECT_CODE'),
            'private_key' => base64_decode((string) env('NOON_API_PRIVATE_KEY_BASE64', ''), true) ?: null,
            'business_model' => env('NOON_API_BUSINESS_MODEL') ?: null,
        ],
    ],
];
