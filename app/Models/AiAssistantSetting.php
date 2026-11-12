<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistantSetting extends Model
{
    protected $table = 'ai_assistant_settings';

    protected $fillable = ['id', 'enabled', 'provider', 'model', 'daily_budget_usd', 'monthly_budget_usd'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'daily_budget_usd' => 'decimal:2', 'monthly_budget_usd' => 'decimal:2'];
    }
}
