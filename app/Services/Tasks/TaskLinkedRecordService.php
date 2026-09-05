<?php

namespace App\Services\Tasks;

use App\Enums\TaskLinkedType;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SafetClaim;
use App\Models\StockTransfer;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class TaskLinkedRecordService
{
    /** @return array{label: string, url: ?string, restricted: bool} */
    public function context(Task $task, User $user): array
    {
        if ($task->linked_type === null || $task->linked_record_id === null) {
            return ['label' => '—', 'url' => null, 'restricted' => false];
        }
        $record = $this->record($task->linked_type, $task->linked_record_id);
        if ($record === null || ! $this->canView($user, $task->linked_type, $record)) {
            return ['label' => 'Restricted Record', 'url' => null, 'restricted' => true];
        }

        return ['label' => $this->label($task->linked_type, $record), 'url' => $this->url($task->linked_type, $record), 'restricted' => false];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array{label: string, url: ?string, restricted: bool}>
     */
    public function contexts(Collection $tasks, User $user): array
    {
        $contexts = [];

        foreach ($tasks->filter(fn (Task $task): bool => $task->linked_type === null || $task->linked_record_id === null) as $task) {
            $contexts[$task->id] = ['label' => '—', 'url' => null, 'restricted' => false];
        }

        $tasks->filter(fn (Task $task): bool => $task->linked_type !== null && $task->linked_record_id !== null)
            ->groupBy(fn (Task $task): string => $task->linked_type->value)
            ->each(function (Collection $group) use (&$contexts, $user): void {
                /** @var Task $first */
                $first = $group->first();
                $type = $first->linked_type;
                $records = $this->recordClass($type)::query()
                    ->whereKey($group->pluck('linked_record_id')->unique()->values())
                    ->get()
                    ->keyBy('id');

                foreach ($group as $task) {
                    $record = $records->get($task->linked_record_id);
                    $contexts[$task->id] = $record !== null && $this->canView($user, $type, $record)
                        ? ['label' => $this->label($type, $record), 'url' => $this->url($type, $record), 'restricted' => false]
                        : ['label' => 'Restricted Record', 'url' => null, 'restricted' => true];
                }
            });

        return $contexts;
    }

    public function userCanView(User $user, ?TaskLinkedType $type, ?int $id): bool
    {
        if ($type === null || $id === null) {
            return true;
        }
        $record = $this->record($type, $id);

        return $record !== null && $this->canView($user, $type, $record);
    }

    private function record(TaskLinkedType $type, int $id): mixed
    {
        $class = $this->recordClass($type);

        return $class::query()->find($id);
    }

    /** @return class-string */
    private function recordClass(TaskLinkedType $type): string
    {
        return match ($type) {
            TaskLinkedType::Order => Order::class, TaskLinkedType::CustomerReturn => CustomerReturn::class,
            TaskLinkedType::SafetClaim => SafetClaim::class, TaskLinkedType::WarrantyRepair, TaskLinkedType::InternalRepair => WarrantyRepair::class,
            TaskLinkedType::Complaint => Complaint::class, TaskLinkedType::DamagedStockEvent => DamagedStockEvent::class,
            TaskLinkedType::Product => Product::class, TaskLinkedType::Purchase => Purchase::class,
            TaskLinkedType::StockTransfer => StockTransfer::class, TaskLinkedType::Employee => Employee::class,
        };
    }

    private function canView(User $user, TaskLinkedType $type, mixed $record): bool
    {
        return match ($type) {
            TaskLinkedType::Order => Gate::forUser($user)->allows('order.view', $record),
            TaskLinkedType::CustomerReturn => Gate::forUser($user)->allows('return.view', $record),
            TaskLinkedType::SafetClaim => Gate::forUser($user)->allows('safet_claim.view', $record),
            TaskLinkedType::WarrantyRepair, TaskLinkedType::InternalRepair => Gate::forUser($user)->allows('warranty_repair.view', $record),
            TaskLinkedType::Complaint => Gate::forUser($user)->allows('complaint.view', $record),
            TaskLinkedType::DamagedStockEvent => Gate::forUser($user)->allows('damaged_stock.view'),
            TaskLinkedType::Product => Gate::forUser($user)->allows('product.view', $record),
            TaskLinkedType::Purchase => Gate::forUser($user)->allows('purchase.view', $record),
            TaskLinkedType::StockTransfer => Gate::forUser($user)->allows('stock_transfer.view', $record),
            TaskLinkedType::Employee => Gate::forUser($user)->allows('view', $record),
        };
    }

    private function label(TaskLinkedType $type, mixed $record): string
    {
        return match ($type) {
            TaskLinkedType::Product => $record->sku, TaskLinkedType::Employee => $record->employee_id.' — '.$record->name, default => $record->reference
        };
    }

    private function url(TaskLinkedType $type, mixed $record): ?string
    {
        return match ($type) {
            TaskLinkedType::Order => OrderResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::CustomerReturn => CustomerReturnResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::SafetClaim => SafetClaimResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::WarrantyRepair => WarrantyRepairResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::InternalRepair => InternalRepairResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::Complaint => ComplaintResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::DamagedStockEvent => DamagedItems::getUrl(['search' => $record->reference]),
            TaskLinkedType::Product => ProductResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::Purchase => PurchaseResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::StockTransfer => StockTransferResource::getUrl('view', ['record' => $record]),
            TaskLinkedType::Employee => EmployeeResource::getUrl('view', ['record' => $record]),
        };
    }
}
