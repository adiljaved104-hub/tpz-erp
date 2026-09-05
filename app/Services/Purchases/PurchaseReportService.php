<?php

namespace App\Services\Purchases;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReportService
{
    public function __construct(private readonly PurchaseAuthorization $authorization) {}

    public function open(User $actor): Builder
    {
        $this->authorization->authorize($actor, PurchasePermission::View);

        return Purchase::query()->whereIn('status', [PurchaseStatus::Approved, PurchaseStatus::PartiallyReceived]);
    }

    public function expected(User $actor): Builder
    {
        return $this->open($actor)->whereNotNull('expected_delivery_date')->orderBy('expected_delivery_date');
    }
}
