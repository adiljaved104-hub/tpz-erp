<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use Illuminate\Support\Facades\Schema;

class AiAssistantSettings
{
    public function current(): array
    {
        $row = Schema::hasTable('ai_assistant_settings') ? AiAssistantSetting::query()->find(1) : null;
        return [
            'enabled' => $row?->enabled ?? (bool) config('ai_assistant.enabled'),
            'provider' => $row?->provider ?? config('ai_assistant.provider'),
            'model' => $row?->model ?? config('ai_assistant.model'),
            'daily_budget_usd' => (float) ($row?->daily_budget_usd ?? config('ai_assistant.daily_budget_usd')),
            'monthly_budget_usd' => (float) ($row?->monthly_budget_usd ?? config('ai_assistant.monthly_budget_usd')),
        ];
    }

    public function enabled(): bool { return $this->current()['enabled']; }
}
