<?php

namespace App\Services\Products;

use App\DTOs\Products\ProductExportData;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ProductAuthorization;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    /** @var array<int, string> */
    private const BASE_FIELDS = [
        'sku', 'name', 'brand', 'category', 'model', 'condition', 'processor', 'ram', 'storage',
        'screen_size', 'graphics', 'color', 'warranty', 'description', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function stream(ProductExportData $data, User $actor): StreamedResponse
    {
        $fields = $this->fieldsFor($actor);
        $query = $this->query($data, $fields);
        $rowCount = (clone $query)->count();
        $filters = $data->filters();

        return response()->streamDownload(function () use ($query, $fields, $rowCount, $filters, $actor): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new \RuntimeException('Unable to open the Product export stream.');
            }

            fputcsv($output, $fields);

            foreach ($query->lazyById(500) as $product) {
                fputcsv($output, array_map(function (string $field) use ($product): mixed {
                    if ($field === 'brand') {
                        return $product->displayBrandName();
                    }

                    if ($field === 'category') {
                        return $product->displayCategoryName();
                    }

                    $value = $product->{$field};

                    return $value instanceof BackedEnum ? $value->value : $value;
                }, $fields));
            }

            fclose($output);
            $this->activity->log('product.exported', $actor, properties: [
                'filters' => $filters,
                'exported_row_count' => $rowCount,
                'included_fields' => $fields,
            ]);
        }, 'products-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<int, string> */
    public function fieldsFor(User $actor): array
    {
        $fields = self::BASE_FIELDS;

        if ($this->authorization->allows($actor, ProductPermission::ViewSellingPrice)) {
            array_splice($fields, 13, 0, ['selling_price']);
        }

        if ($this->authorization->allows($actor, ProductPermission::ViewCostPrice)) {
            $sellingPosition = array_search('selling_price', $fields, true);
            array_splice($fields, $sellingPosition === false ? 13 : $sellingPosition, 0, ['cost_price']);
        }

        return $fields;
    }

    /** @param array<int, string> $fields */
    private function query(ProductExportData $data, array $fields): Builder
    {
        $query = Product::query()->products()
            ->select(array_values(array_unique(array_merge(['id', 'brand_id', 'category_id'], $fields))))
            ->with(['brandRelation:id,name', 'categoryRelation:id,name']);
        $filters = $data->filters();

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($search): void {
                $query->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('brandRelation', fn (Builder $brand): Builder => $brand->where('name', 'like', "%{$search}%"))
                    ->orWhere(function (Builder $legacy) use ($search): void {
                        $legacy->whereNull('brand_id')->where('brand', 'like', "%{$search}%");
                    })
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        foreach (['brand_id', 'category_id', 'condition', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('id');
    }
}
