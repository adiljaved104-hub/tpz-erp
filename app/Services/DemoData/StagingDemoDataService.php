<?php

namespace App\Services\DemoData;

use App\Enums\EmployeeRole;
use App\Models\User;
use App\Services\DefaultWarehouseService;
use App\Services\DemoData\Scenarios\AfterSalesScenarioBuilder;
use App\Services\DemoData\Scenarios\CommerceScenarioBuilder;
use App\Services\DemoData\Scenarios\OperationsScenarioBuilder;
use App\Services\DemoData\Scenarios\ReferenceScenarioBuilder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class StagingDemoDataService
{
    public function __construct(
        private readonly DemoEnvironmentGuard $guard,
        private readonly DefaultWarehouseService $warehouses,
        private readonly ReferenceScenarioBuilder $references,
        private readonly CommerceScenarioBuilder $commerce,
        private readonly AfterSalesScenarioBuilder $afterSales,
        private readonly OperationsScenarioBuilder $operations,
    ) {}

    public function run(string $profile = 'full', bool $dryRun = false, ?string $confirmation = null): DemoRunReport
    {
        if ($profile !== (string) config('demo.profile', 'full')) {
            throw new RuntimeException('Only the approved full demo profile is available.');
        }
        $this->guard->assertAllowed($dryRun, $confirmation);

        $owner = User::query()->whereHas('employee', fn ($query) => $query->where('role', EmployeeRole::Owner)->where('status', true))->with('employee')->sole();
        $warehouse = $this->warehouses->operationalDefault();
        if ($warehouse->code !== 'MAIN') {
            throw new RuntimeException('The approved MAIN warehouse must be the active operational default.');
        }

        if ($dryRun) {
            return new DemoRunReport(true, $this->plannedCounts(), $this->guard->databaseIdentity());
        }

        $lock = Cache::lock('erp-demo:'.hash('sha256', $this->guard->databaseIdentity()), (int) config('demo.lock_seconds', 3600));
        if (! $lock->get()) {
            throw new RuntimeException('Another demo-data generation is already running for this database.');
        }

        try {
            $snapshot = DemoSafetySnapshot::capture();
            $context = new DemoContext($owner, $warehouse);
            $this->references->build($context);
            $this->commerce->build($context);
            $this->afterSales->build($context);
            $this->operations->build($context);
            $snapshot->assertPreserved();

            return new DemoRunReport(false, $context->counts, $this->guard->databaseIdentity());
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int> */
    private function plannedCounts(): array
    {
        return [
            'teams' => 3, 'employees' => 6, 'suppliers' => 5, 'platforms' => 5,
            'products' => 32, 'components' => 8, 'purchases' => 10, 'orders' => 60,
            'quotations' => 8, 'returns' => 10, 'claims' => 4, 'service_cases' => 8,
            'tasks' => 16, 'expenses' => 24,
        ];
    }
}
