<?php

namespace App\Actions\Warehouses;

use App\DTOs\Warehouses\ChangeDefaultWarehouseData;
use App\Exceptions\DefaultWarehouseException;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\DefaultWarehouseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

class SetDefaultWarehouse
{
    public function __construct(
        private readonly DefaultWarehouseService $defaults,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(Warehouse $warehouse, ChangeDefaultWarehouseData $data, User $actor): Warehouse
    {
        if (! $actor->can('changeDefault', $warehouse)) {
            throw new AuthorizationException;
        }

        $reason = Validator::make(
            ['reason' => trim($data->reason)],
            ['reason' => ['required', 'string', 'max:1000']],
        )->validate()['reason'];

        try {
            return $this->defaults->switchTo($warehouse, $reason, $actor);
        } catch (DefaultWarehouseException $exception) {
            $this->activity->log('warehouse.default_change_rejected', $actor, $warehouse, [
                'reason' => $reason,
                'rejection' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
