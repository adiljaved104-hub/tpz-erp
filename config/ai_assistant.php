<?php

return [
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
    'provider' => env('AI_ASSISTANT_PROVIDER', 'mock'),
    'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 45),
    'max_output_tokens' => (int) env('AI_ASSISTANT_MAX_OUTPUT_TOKENS', 1200),
    'max_history_messages' => (int) env('AI_ASSISTANT_MAX_HISTORY_MESSAGES', 16),
    'max_date_range_days' => (int) env('AI_ASSISTANT_MAX_DATE_RANGE_DAYS', 366),
    'retention_days' => (int) env('AI_ASSISTANT_RETENTION_DAYS', 30),
    'requests_per_minute' => (int) env('AI_ASSISTANT_REQUESTS_PER_MINUTE', 8),
    'monthly_budget_usd' => (float) env('AI_ASSISTANT_MONTHLY_BUDGET_USD', 30),
    'daily_budget_usd' => (float) env('AI_ASSISTANT_DAILY_BUDGET_USD', 2),
    'warning_ratio' => 0.8,
    'cost_per_million_tokens' => [
        // Standard text token rates in USD per million; verify before release.
        'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
        'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00],
        'gpt-6.1-sol' => ['input' => 2.00, 'output' => 10.00],
        'gpt-6-astra' => ['input' => 10.00, 'output' => 50.00],
    ],
];
