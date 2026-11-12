<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use App\Models\AiAssistantAudit;
use App\Models\AiAssistantConversation;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiAssistantService
{
    public function __construct(private readonly ResponsesProvider $provider, private readonly AiAssistantSettings $settings, private readonly SensitiveTextSanitizer $sanitizer) {}

    public function conversations(User $user): array
    {
        return AiAssistantConversation::query()->where('user_id', $user->id)->latest('last_message_at')->limit(50)->get(['id', 'title', 'last_message_at'])->toArray();
    }

    public function create(User $user): AiAssistantConversation
    {
        return AiAssistantConversation::query()->create(['user_id' => $user->id]);
    }

    public function history(User $user, AiAssistantConversation $conversation): array
    {
        abort_unless($conversation->user_id === $user->id, 404);
        return $conversation->messages()->oldest()->limit(200)->get(['id', 'role', 'content', 'status', 'created_at'])->toArray();
    }

    public function send(User $user, AiAssistantConversation $conversation, string $prompt, callable $emit): AiAssistantMessage
    {
        abort_unless($this->settings->enabled(), 404);
        abort_unless($conversation->user_id === $user->id, 404);
        $prompt = trim($this->sanitizer->sanitize($prompt));
        if ($prompt === '' || mb_strlen($prompt) > 6000) throw ValidationException::withMessages(['message' => 'Message is required and must be under 6,000 characters.']);
        $reservation = $this->reserveBudget($user);
        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => $prompt, 'status' => 'complete']);
        if ($conversation->title === 'New chat') $conversation->update(['title' => mb_substr($prompt, 0, 100)]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'streaming', 'provider' => $this->settings->current()['provider'], 'model' => $this->settings->current()['model']]);
        $history = $conversation->messages()->where('id', '<', $assistant->id)->where('status', 'complete')->latest()->limit(config('ai_assistant.max_history_messages'))->get()->reverse()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        $text = '';
        try {
            $usage = $this->provider->stream($user, $history, function (string $chunk) use (&$text, $emit): void { $text .= $chunk; $emit($chunk); });
            $assistant->update(['content' => $text, 'status' => 'complete', 'response_id' => $usage['response_id'] ?? null, 'input_tokens' => $usage['input_tokens'] ?? 0, 'output_tokens' => $usage['output_tokens'] ?? 0]);
            $this->recordUsage($user, $assistant, $usage, 'complete', $reservation);
            $this->audit($user, $conversation, 'message.completed', ['message_id' => $assistant->id]);
        } catch (Throwable $exception) {
            report($exception);
            $assistant->update(['content' => $text, 'status' => 'failed', 'error_code' => 'provider_error']);
            // Keep the conservative reservation: an interrupted stream may still have incurred provider usage.
            $reservation->update(['message_id' => $assistant->id, 'status' => 'failed']);
            $this->audit($user, $conversation, 'message.failed', ['message_id' => $assistant->id]);
            $conversation->update(['last_message_at' => now()]);
            throw $exception;
        }
        $conversation->update(['last_message_at' => now()]);
        return $assistant->fresh();
    }

    private function reserveBudget(User $user): AiAssistantUsage
    {
        return DB::transaction(function () use ($user): AiAssistantUsage {
            AiAssistantSetting::query()->whereKey(1)->lockForUpdate()->first();
            $settings = $this->settings->current();
            $model = $settings['model'];
            $rates = config('ai_assistant.cost_per_million_tokens', [])[$model] ?? null;
            if ($settings['provider'] === 'openai' && $rates === null) throw ValidationException::withMessages(['model' => 'A cost rate must be configured for this model before enabling it.']);
            $rates ??= ['input' => 0, 'output' => 0];
            $reserved = $settings['provider'] === 'mock' ? 0 : ((4 * 128000 * $rates['input']) + (4 * config('ai_assistant.max_output_tokens') * $rates['output'])) / 1_000_000;
            $now = now();
            $now = $now->timezone(config('app.timezone'));
            $todayStart = $now->copy()->startOfDay();
            $monthStart = $now->copy()->startOfMonth();
            $today = (float) AiAssistantUsage::query()->whereBetween('created_at', [$todayStart, $todayStart->copy()->endOfDay()])->sum('estimated_cost_usd');
            $month = (float) AiAssistantUsage::query()->whereBetween('created_at', [$monthStart, $monthStart->copy()->endOfMonth()])->sum('estimated_cost_usd');
            if ($today + $reserved > $settings['daily_budget_usd'] || $month + $reserved > $settings['monthly_budget_usd']) {
                throw ValidationException::withMessages(['budget' => 'The AI Assistant usage limit has been reached for this period.']);
            }
            return AiAssistantUsage::query()->create(['user_id' => $user->id, 'provider' => $settings['provider'], 'model' => $model, 'estimated_cost_usd' => $reserved, 'status' => 'reserved']);
        });
    }

    private function recordUsage(User $user, AiAssistantMessage $message, array $usage, string $status, AiAssistantUsage $reservation): void
    {
        $model = $this->settings->current()['model'];
        $rates = config('ai_assistant.cost_per_million_tokens', [])[$model] ?? ['input' => 0, 'output' => 0];
        $input = (int) ($usage['input_tokens'] ?? 0); $output = (int) ($usage['output_tokens'] ?? 0);
        $cost = ($input * $rates['input'] + $output * $rates['output']) / 1_000_000;
        $reservation->update(['message_id' => $message->id, 'input_tokens' => $input, 'output_tokens' => $output, 'estimated_cost_usd' => $status === 'complete' ? $cost : 0, 'status' => $status]);
        $settings = $this->settings->current();
        $todayStart = now(config('app.timezone'))->startOfDay();
        $monthStart = now(config('app.timezone'))->startOfMonth();
        $month = (float) AiAssistantUsage::query()->whereBetween('created_at', [$monthStart, $monthStart->copy()->endOfMonth()])->sum('estimated_cost_usd');
        $day = (float) AiAssistantUsage::query()->whereBetween('created_at', [$todayStart, $todayStart->copy()->endOfDay()])->sum('estimated_cost_usd');
        if (($settings['monthly_budget_usd'] > 0 && $month >= $settings['monthly_budget_usd'] * config('ai_assistant.warning_ratio'))
            || ($settings['daily_budget_usd'] > 0 && $day >= $settings['daily_budget_usd'] * config('ai_assistant.warning_ratio'))) {
            $this->audit($user, $message->conversation, 'budget.warning', ['month' => now()->format('Y-m'), 'day' => now()->toDateString(), 'threshold_reached' => true]);
        }
    }

    private function audit(User $user, AiAssistantConversation $conversation, string $event, array $metadata = []): void
    {
        AiAssistantAudit::query()->create(['user_id' => $user->id, 'conversation_id' => $conversation->id, 'event' => $event, 'metadata' => $metadata, 'ip_address' => request()->ip()]);
    }
}
