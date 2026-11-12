<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeRole;
use App\Models\AiAssistantSetting;
use App\Models\AiAssistantUsage;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AiAssistantSettings extends Page
{
    protected string $view = 'filament.pages.ai-assistant-settings';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static string|\UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'AI Assistant';
    protected static ?string $title = 'AI Assistant Settings';
    public bool $enabled = false;
    public string $provider = 'mock';
    public string $model = 'gpt-6-luna';
    public float $dailyBudget = 2;
    public float $monthlyBudget = 30;
    public float $spentToday = 0;
    public float $spentThisMonth = 0;
    public int $tokensThisMonth = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user instanceof User && $user->employee?->status === true && $user->employee?->role === EmployeeRole::Owner;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = app(\App\Services\AiAssistant\AiAssistantSettings::class)->current();
        $this->enabled = $settings['enabled']; $this->provider = $settings['provider']; $this->model = $settings['model'];
        $this->dailyBudget = $settings['daily_budget_usd']; $this->monthlyBudget = $settings['monthly_budget_usd'];
        $this->spentToday = (float) AiAssistantUsage::query()->whereDate('created_at', now()->toDateString())->sum('estimated_cost_usd');
        $this->spentThisMonth = (float) AiAssistantUsage::query()->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('estimated_cost_usd');
        $this->tokensThisMonth = (int) AiAssistantUsage::query()->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('input_tokens') + (int) AiAssistantUsage::query()->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('output_tokens');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->validate([
            'enabled' => ['required', 'boolean'], 'provider' => ['required', 'in:mock,openai'],
            'model' => ['required', 'string', 'in:'.implode(',', array_keys(config('ai_assistant.cost_per_million_tokens')))], 'dailyBudget' => ['required', 'numeric', 'min:0', 'max:10000'],
            'monthlyBudget' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);
        AiAssistantSetting::query()->updateOrCreate(['id' => 1], [
            'enabled' => $data['enabled'], 'provider' => $data['provider'], 'model' => $data['model'],
            'daily_budget_usd' => $data['dailyBudget'], 'monthly_budget_usd' => $data['monthlyBudget'],
        ]);
        Notification::make()->success()->title('AI Assistant settings saved')->send();
    }
}
