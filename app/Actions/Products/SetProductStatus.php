<?php

namespace App\Actions\Products;

use App\DTOs\Products\ChangeProductStatusData;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ProductAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SetProductStatus
{
    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(Product $product, ChangeProductStatusData $data, User $actor): Product
    {
        $validated = Validator::make([
            'status' => $data->status instanceof ProductStatus ? $data->status->value : $data->status,
            'reason' => trim($data->reason),
        ], [
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($product, $validated, $actor): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
            $from = $product->status;
            $to = ProductStatus::from($validated['status']);

            if ($from === $to) {
                throw ValidationException::withMessages(['status' => 'The Product is already in the requested status.']);
            }

            $permission = $this->permissionFor($from, $to);
            $this->authorization->authorize($actor, $permission, $product);

            $product->forceFill(['status' => $to])->save();
            $event = $from === ProductStatus::Discontinued && $to === ProductStatus::Active
                ? 'product.reactivated'
                : 'product.status_changed';

            $this->activity->log($event, $actor, $product, [
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $validated['reason'],
            ]);

            return $product;
        });
    }

    private function permissionFor(ProductStatus $from, ProductStatus $to): ProductPermission
    {
        return match ([$from, $to]) {
            [ProductStatus::Active, ProductStatus::Inactive],
            [ProductStatus::Discontinued, ProductStatus::Inactive] => ProductPermission::Deactivate,
            [ProductStatus::Inactive, ProductStatus::Active] => ProductPermission::Activate,
            [ProductStatus::Active, ProductStatus::Discontinued],
            [ProductStatus::Inactive, ProductStatus::Discontinued] => ProductPermission::Discontinue,
            [ProductStatus::Discontinued, ProductStatus::Active] => ProductPermission::Reactivate,
            default => throw ValidationException::withMessages(['status' => 'This Product status transition is not allowed.']),
        };
    }
}
