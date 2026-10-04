<?php

namespace App\Console\Commands;

use App\Services\Notifications\StockAlertReminderService;
use Illuminate\Console\Command;

class SendStockAlertReminders extends Command
{
    protected $signature = 'inventory:send-stock-reminders';

    protected $description = 'Send idempotent stock alert reminders and due escalations';

    public function handle(StockAlertReminderService $reminders): int
    {
        $result = $reminders->evaluate();

        $this->info("Sent {$result['reminders']} reminder(s) and {$result['escalations']} escalation(s); resolved {$result['resolved']} incident(s).");

        return self::SUCCESS;
    }
}
