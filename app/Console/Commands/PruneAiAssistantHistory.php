<?php

namespace App\Console\Commands;

use App\Models\AiAssistantConversation;
use Illuminate\Console\Command;

class PruneAiAssistantHistory extends Command
{
    protected $signature = 'ai-assistant:prune-history';
    protected $description = 'Delete AI Assistant conversations older than the configured retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('ai_assistant.retention_days', 30)));
        $deleted = AiAssistantConversation::query()->where('updated_at', '<', $cutoff)->delete();
        $this->info("Deleted {$deleted} expired AI Assistant conversations.");
        return self::SUCCESS;
    }
}
