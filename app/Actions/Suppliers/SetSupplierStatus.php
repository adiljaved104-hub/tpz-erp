<?php

namespace App\Actions\Suppliers;

use App\DTOs\Suppliers\ChangeSupplierStatusData;
use App\Exceptions\SupplierOperationalUseException;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SupplierUsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetSupplierStatus
{
    public function __construct(private readonly ActivityLogger $activity, private readonly SupplierUsageService $usage) {}

    public function handle(Supplier $supplier, ChangeSupplierStatusData $data, User $actor): Supplier
    {
        if (! $actor->can('changeStatus', $supplier)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(
            ['active' => $data->active, 'reason' => trim($data->reason)],
            ['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']],
        )->validate();

        try {
            return DB::transaction(function () use ($supplier, $validated, $actor): Supplier {
                $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->getKey());
                $previous = $supplier->status;

                if ($previous === $validated['active']) {
                    return $supplier;
                }

                if (! $validated['active']) {
                    $usage = $this->usage->check($supplier);
                    if ($usage->used) {
                        throw new SupplierOperationalUseException('Supplier deactivation is blocked by: '.implode(', ', $usage->sources).'.');
                    }
                }

                $supplier->forceFill(['status' => $validated['active']])->save();
                $this->activity->log('supplier.status_changed', $actor, $supplier, [
                    'from_active' => $previous,
                    'to_active' => $supplier->status,
                    'reason' => $validated['reason'],
                ]);

                return $supplier;
            });
        } catch (SupplierOperationalUseException $exception) {
            $this->activity->log('supplier.status_change_rejected', $actor, $supplier, [
                'to_active' => $validated['active'], 'reason' => $validated['reason'], 'rejection' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
