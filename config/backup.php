<?php

return [
    'root' => env('ERP_BACKUP_PATH', storage_path('app/backups')),

    'disk' => env('ERP_BACKUP_DISK', 'local'),

    'database' => [
        'enabled' => env('ERP_BACKUP_DATABASE_ENABLED', true),
    ],

    'storage' => [
        'enabled' => env('ERP_BACKUP_STORAGE_ENABLED', true),
        'roots' => [
            'public' => storage_path('app/public'),
            'private' => storage_path('app/private'),
        ],
        'excluded_segments' => ['backups', 'livewire-tmp'],
        'excluded_files' => ['.gitignore'],
    ],

    'retention' => [
        'daily' => max(1, (int) env('ERP_BACKUP_KEEP_DAILY', 14)),
        'weekly' => max(0, (int) env('ERP_BACKUP_KEEP_WEEKLY', 8)),
        'monthly' => max(0, (int) env('ERP_BACKUP_KEEP_MONTHLY', 6)),
    ],

    'schedule' => [
        'enabled' => env('ERP_BACKUP_SCHEDULE_ENABLED', false),
        'time' => env('ERP_BACKUP_SCHEDULE_TIME', '02:00'),
        'overlap_minutes' => max(60, (int) env('ERP_BACKUP_OVERLAP_MINUTES', 180)),
    ],

    'health' => [
        'maximum_age_hours' => max(1, (int) env('ERP_BACKUP_MAX_AGE_HOURS', 26)),
    ],

    'offsite' => [
        'enabled' => env('ERP_BACKUP_OFFSITE_ENABLED', false),
        'configured' => env('ERP_BACKUP_OFFSITE_CONFIGURED', false),
    ],

    'encryption' => [
        'enabled' => env('ERP_BACKUP_ENCRYPTION_ENABLED', false),
        'configured' => env('ERP_BACKUP_ENCRYPTION_CONFIGURED', false),
    ],

    'mysql' => [
        'dump_binary' => env('ERP_MYSQLDUMP_BINARY', 'mysqldump'),
        'client_binary' => env('ERP_MYSQL_BINARY', 'mysql'),
    ],
];
