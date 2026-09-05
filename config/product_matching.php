<?php

return [
    'minimum_query_length' => 2,
    'candidate_limit' => 150,
    'result_limit' => 12,
    'query_budget' => 20,

    'aliases' => [
        '/\bintel\s+core\s+ultra\s*([3579])\b/u' => 'core ultra $1',
        '/\bcore\s+ultra\s*([3579])\b/u' => 'core ultra $1',
        '/(?<!core )\bultra\s*([3579])\b/u' => 'core ultra $1',
        '/\bu\s*([3579])\s+([0-9]{3}[a-z]{1,2})\b/u' => 'core ultra $1 $2',
        '/\brtx\s*([0-9]{3,4})(ti)?\b/u' => 'rtx $1$2',
        '/\bgtx\s*([0-9]{3,4})(ti)?\b/u' => 'gtx $1$2',
        '/\bgeforce\s+(rtx|gtx)\b/u' => '$1',
        '/\bnvidia\s+(rtx|gtx)\b/u' => '$1',
        '/\b([0-9]+)\s*g\b/u' => '$1 gb',
    ],

    'known_brands' => [
        'acer', 'apple', 'asus', 'dell', 'hp', 'huawei', 'lenovo', 'microsoft', 'msi', 'samsung',
    ],

    'weights' => [
        'brand' => 12,
        'model' => 18,
        'cpu_family' => 10,
        'cpu_model' => 20,
        'gpu_model' => 20,
        'ram_mb' => 10,
        'storage_gb' => 10,
        'title_similarity' => 10,
    ],

    'conflict_penalties' => [
        'brand' => 35,
        'model' => 35,
        'cpu_family' => 25,
        'cpu_model' => 40,
        'gpu_model' => 40,
        'ram_mb' => 20,
        'storage_gb' => 20,
    ],
];
