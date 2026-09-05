<?php

namespace App\Console\Commands;

use App\Actions\Employees\BootstrapInitialOwner as BootstrapInitialOwnerAction;
use Illuminate\Console\Command;

class BootstrapInitialOwner extends Command
{
    protected $signature = 'owner:bootstrap {--force : Skip the interactive confirmation}';

    protected $description = 'Create and link the approved Owner Employee profile for User ID 1';

    public function handle(BootstrapInitialOwnerAction $bootstrap): int
    {
        if (! $this->option('force') && ! $this->confirm('This writes the approved Owner Employee and activity log. Continue?')) {
            $this->warn('Owner bootstrap cancelled.');

            return self::SUCCESS;
        }

        $employee = $bootstrap->handle();
        $this->info("Approved Owner linked as {$employee->employee_id}.");

        return self::SUCCESS;
    }
}
