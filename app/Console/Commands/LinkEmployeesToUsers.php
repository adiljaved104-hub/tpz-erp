<?php

namespace App\Console\Commands;

use App\Actions\Employees\LinkUserToEmployee;
use App\DTOs\Employees\LinkUserToEmployeeData;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

class LinkEmployeesToUsers extends Command
{
    protected $signature = 'employees:link-users {--apply : Apply unambiguous links} {--actor= : Authorized User ID required with --apply}';

    protected $description = 'Report or apply unambiguous normalized-email Employee-to-User links';

    public function handle(LinkUserToEmployee $linker): int
    {
        $candidates = Employee::query()->whereNull('user_id')->get()->map(function (Employee $employee): array {
            $normalized = mb_strtolower(trim($employee->email));
            $users = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])->get();
            $employeeCount = Employee::query()->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])->count();

            return [
                'employee' => $employee,
                'user' => $users->count() === 1 && $employeeCount === 1 ? $users->first() : null,
                'status' => $users->count() === 1 && $employeeCount === 1 ? 'ready' : 'exception',
            ];
        });

        $this->table(['Employee', 'Email', 'User', 'Result'], $candidates->map(fn (array $candidate): array => [
            $candidate['employee']->employee_id,
            $candidate['employee']->email,
            $candidate['user']?->getKey() ?? '-',
            $candidate['status'],
        ]));

        if (! $this->option('apply')) {
            $this->info('Dry run only. No links were written.');

            return self::SUCCESS;
        }

        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $actor = $actorId ? User::query()->find($actorId) : null;

        if ($actor === null) {
            $this->error('--actor must identify an authorized User when --apply is used.');

            return self::FAILURE;
        }

        foreach ($candidates->where('status', 'ready') as $candidate) {
            $linker->handle(new LinkUserToEmployeeData($candidate['employee']->getKey(), $candidate['user']->getKey()), $actor);
        }

        $this->info('Unambiguous links applied. Exceptions remain unchanged.');

        return self::SUCCESS;
    }
}
