<?php

namespace App\Services\Products;

use App\DTOs\Products\ProductExportData;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Search\SearchQuery;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    /** @var array<int, string> */
    private const BASE_FIELDS = [
        'sku', 'name', 'brand', 'category', 'model', 'condition', 'processor', 'processor_class', 'processor_model',
        'processor_generation', 'ram', 'storage', 'screen_size', 'graphics', 'color', 'touch_screen', 'is_convertible_360',
        'accounting_title_override', 'website_title_override', 'warranty', 'description', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function stream(ProductExportData $data, User $actor): StreamedResponse
    {
        $this->authorization->authorize($actor, ProductPermission::Export);
        $fields = $this->fieldsFor($actor);
        $query = $this->query($data, $fields);
        $this->authorization->applyVisibilityScope($query, $actor);
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
            array_splice($fields, (int) array_search('warranty', $fields, true), 0, ['selling_price']);
        }

        if ($this->authorization->allows($actor, ProductPermission::ViewCostPrice)) {
            $sellingPosition = array_search('selling_price', $fields, true);
            array_splice($fields, $sellingPosition === false ? (int) array_search('warranty', $fields, true) : $sellingPosition, 0, ['cost_price']);
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
            foreach (SearchQuery::tokens((string) $filters['search']) as $token) {
                $query->where(function (Builder $match) use ($token): void {
                    $match->where(fn (Builder $product): Builder => SearchQuery::matchModel($product, [
                        'products.sku', 'products.name', 'products.model', 'products.processor_class',
                        'products.processor_model', 'products.processor_generation', 'products.ram',
                        'products.storage', 'products.graphics', 'products.color',
                    ], $token))
                        ->orWhereHas('brandRelation', fn (Builder $brand): Builder => SearchQuery::matchModel($brand, ['product_brands.name'], $token))
                        ->orWhere(fn (Builder $legacy): Builder => $legacy->whereNull('brand_id')
                            ->whereRaw("LOWER(products.brand) LIKE ? ESCAPE '!'", [SearchQuery::likePattern($token)]));
                });
            }
        }

        foreach (['brand_id', 'category_id', 'condition', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('id');
    }
}
