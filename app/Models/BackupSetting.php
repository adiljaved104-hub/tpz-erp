<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'enabled', 'backup_time', 'database_enabled', 'storage_enabled',
        'daily_retention', 'weekly_retention', 'monthly_retention', 'backup_disk',
        'offsite_enabled', 'encryption_enabled', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'storage_enabled' => 'boolean',
            'daily_retention' => 'integer',
            'weekly_retention' => 'integer',
            'monthly_retention' => 'integer',
            'offsite_enabled' => 'boolean',
            'encryption_enabled' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
