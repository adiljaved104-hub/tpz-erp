<?php

namespace App\Services\Products;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Products\UpdateProductData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ProductIntelligence\ProductDuplicateGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportService
{
    public const MODE_CREATE = 'create';

    public const MODE_UPSERT = 'upsert';

    public function __construct(
        private readonly ProductDuplicateGuard $duplicates,
        private readonly ProductTitleService $titles,
        private readonly CreateProduct $create,
        private readonly UpdateProduct $update,
        private readonly ProductAuthorization $authorization,
    ) {}

    /** @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>} */
    public function preview(string $path, string $mode, User $actor): array
    {
        $this->authorize($actor);
        $this->assertMode($mode);
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $headers = array_map(fn ($value): string => $this->header((string) $value), array_shift($sheet) ?? []);
        $rows = [];

        foreach ($sheet as $offset => $values) {
            if (collect($values)->every(fn ($value): bool => blank($value))) {
                continue;
            }

            $raw = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $raw[$header] = $values[$index] ?? null;
                }
            }
            $rows[] = $this->inspectRow($raw, $offset + 2, $mode, $actor);
        }

        return ['rows' => $rows, 'counts' => [
            'new' => collect($rows)->where('status', 'new')->count(),
            'update' => collect($rows)->where('status', 'update')->count(),
            'duplicate' => collect($rows)->filter(fn (array $row): bool => $row['warnings'] !== [])->count(),
            'invalid' => collect($rows)->where('status', 'invalid')->count(),
        ]];
    }

    /** @param array{rows: array<int, array<string, mixed>>} $preview @return array{created: int, updated: int} */
    public function import(array $preview, string $mode, User $actor, ?string $duplicateOverrideReason = null): array
    {
        $this->authorize($actor);
        $this->assertMode($mode);
        $rows = collect($preview['rows'] ?? []);
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'The import file contains no Product rows.']);
        }
        if ($rows->contains(fn (array $row): bool => $row['status'] === 'invalid')) {
            throw ValidationException::withMessages(['file' => 'Correct every invalid row before importing.']);
        }
        if ($rows->contains(fn (array $row): bool => $row['warnings'] !== []) && blank($duplicateOverrideReason)) {
            throw ValidationException::withMessages(['duplicateOverrideReason' => 'Provide a reason to import rows with possible duplicate warnings.']);
        }
        validator(['duplicateOverrideReason' => $duplicateOverrideReason], [
            'duplicateOverrideReason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($rows, $actor, $duplicateOverrideReason): array {
            $created = 0;
            $updated = 0;
            foreach ($rows as $row) {
                $data = $row['data'];
                if ($row['status'] === 'update') {
                    $product = Product::query()->where('sku', $data['sku'])->lockForUpdate()->firstOrFail();
                    $this->update->handle($product, $this->updateData($product, $data, $duplicateOverrideReason), $actor);
                    $updated++;
                } else {
                    $this->create->handle($this->createData($data, $duplicateOverrideReason), $actor);
                    $created++;
                }
            }

            return compact('created', 'updated');
        });
    }

    /** @return array<int, string> */
    public function templateHeaders(): array
    {
        return ['TPZ SKU', 'Brand', 'Category', 'Model', 'Processor Class', 'Processor Model', 'Generation', 'RAM', 'Storage', 'Screen Size', 'Graphics', 'Color', 'Touch', '360 Convertible', 'Condition', 'Warranty Months', 'Selling Price', 'Accounting Title Override', 'Website Title Override'];
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function inspectRow(array $raw, int $number, string $mode, User $actor): array
    {
        $errors = [];
        $brandName = trim((string) ($raw['brand'] ?? ''));
        $categoryName = trim((string) ($raw['category'] ?? ''));
        $model = trim((string) ($raw['model'] ?? ''));
        $brand = ProductBrand::query()->active()->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])->first();
        $category = ProductCategory::query()->active()->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])->first();
        if (! $brand) {
            $errors[] = "Brand \"{$brandName}\" was not found.";
        }
        if (! $category) {
            $errors[] = "Category \"{$categoryName}\" was not found.";
        }
        if ($model === '') {
            $errors[] = 'Model is required.';
        }

        $sku = trim((string) ($raw['tpz_sku'] ?? ''));
        $existing = $sku === '' ? null : Product::query()->where('sku', $sku)->first();
        if ($sku !== '' && $mode === self::MODE_CREATE) {
            $errors[] = 'TPZ SKU is only accepted in Create / Update mode to identify an existing Product.';
        } elseif ($sku !== '' && ! $existing) {
            $errors[] = "TPZ SKU \"{$sku}\" was not found. Leave it blank to create a new Product.";
        }

        $touch = $this->boolean($raw['touch'] ?? null, 'Touch', $errors);
        $convertible = $this->boolean($raw['360_convertible'] ?? null, '360 Convertible', $errors);
        $condition = $this->condition($raw['condition'] ?? null);
        if (! $condition) {
            $errors[] = 'Condition is invalid.';
        }
        $warrantyValue = blank($raw['warranty_months'] ?? null) ? ($existing?->warranty ?? 12) : $raw['warranty_months'];
        $warranty = filter_var($warrantyValue, FILTER_VALIDATE_INT);
        if ($warranty === false || $warranty < 0 || $warranty > 600) {
            $errors[] = 'Warranty Months must be between 0 and 600.';
        }
        $selling = trim((string) ($raw['selling_price'] ?? ''));
        if ($selling !== '' && ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $selling)) {
            $errors[] = 'Selling Price must be a valid AED amount.';
        }

        $data = [
            'sku' => $sku ?: null, 'brand_id' => $brand?->id, 'category_id' => $category?->id, 'model' => $model,
            'processor_class' => $this->nullable($raw['processor_class'] ?? null), 'processor_model' => $this->nullable($raw['processor_model'] ?? null),
            'processor_generation' => $this->nullable($raw['generation'] ?? null), 'ram' => $this->nullable($raw['ram'] ?? null),
            'storage' => $this->nullable($raw['storage'] ?? null), 'screen_size' => $this->nullable($raw['screen_size'] ?? null),
            'graphics' => $this->nullable($raw['graphics'] ?? null), 'color' => $this->nullable($raw['color'] ?? null),
            'touch_screen' => $touch, 'is_convertible_360' => $convertible, 'condition' => $condition?->value,
            'warranty' => $warranty === false ? null : $warranty, 'selling_price' => $selling === '' ? null : $selling,
            'accounting_title_override' => $this->nullable($raw['accounting_title_override'] ?? null),
            'website_title_override' => $this->nullable($raw['website_title_override'] ?? null),
        ];

        $warnings = [];
        if ($errors === [] && $brand && $category && $condition) {
            $draft = new Product($data + ['name' => $existing?->name ?? $model, 'brand' => $brand->name, 'category' => $category->name, 'sku' => $sku ?: 'NEW']);
            $draft->setRelation('brandRelation', $brand);
            $draft->setRelation('categoryRelation', $category);
            $data['name'] = $existing?->name ?? $this->titles->accounting($draft);
            $matches = $this->duplicates->candidates($actor, $data, $existing?->id);
            $warnings = $matches->map(fn ($match): string => "Possible duplicate of {$match->sku}.")->all();
        }

        return ['row' => $number, 'status' => $errors !== [] ? 'invalid' : ($existing ? 'update' : 'new'), 'data' => $data, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param array<int, string> $errors */
    private function boolean(mixed $value, string $field, array &$errors): ?bool
    {
        if (blank($value)) {
            return null;
        }
        $normalized = mb_strtolower(trim((string) $value));
        if (in_array($normalized, ['yes', 'y', '1', 'true'], true)) {
            return true;
        }
        if (in_array($normalized, ['no', 'n', '0', 'false'], true)) {
            return false;
        }
        $errors[] = "{$field} must be Yes or No.";

        return null;
    }

    private function condition(mixed $value): ?ProductCondition
    {
        $normalized = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));

        return ProductCondition::tryFrom($normalized ?: ProductCondition::New->value);
    }

    /** @param array<string, mixed> $data */
    private function createData(array $data, ?string $reason): CreateProductData
    {
        return new CreateProductData(name: $data['name'], brandId: $data['brand_id'], categoryId: $data['category_id'], condition: $data['condition'],
            processorClass: $data['processor_class'], processorModel: $data['processor_model'], processorGeneration: $data['processor_generation'], model: $data['model'],
            ram: $data['ram'], storage: $data['storage'], screenSize: $data['screen_size'], graphics: $data['graphics'], color: $data['color'], touchScreen: $data['touch_screen'],
            isConvertible360: $data['is_convertible_360'], accountingTitleOverride: $data['accounting_title_override'], websiteTitleOverride: $data['website_title_override'],
            warranty: $data['warranty'], sellingPrice: $data['selling_price'], sellingPriceProvided: $data['selling_price'] !== null, duplicateOverrideReason: $reason);
    }

    /** @param array<string, mixed> $data */
    private function updateData(Product $product, array $data, ?string $reason): UpdateProductData
    {
        return new UpdateProductData(name: $product->name, brandId: $data['brand_id'], categoryId: $data['category_id'], condition: $data['condition'],
            processor: $product->processor, processorClass: $data['processor_class'], processorModel: $data['processor_model'], processorGeneration: $data['processor_generation'], model: $data['model'],
            ram: $data['ram'], storage: $data['storage'], screenSize: $data['screen_size'], graphics: $data['graphics'], color: $data['color'], touchScreen: $data['touch_screen'],
            isConvertible360: $data['is_convertible_360'], accountingTitleOverride: $data['accounting_title_override'], websiteTitleOverride: $data['website_title_override'],
            warranty: $data['warranty'], sellingPrice: $data['selling_price'], sellingPriceProvided: $data['selling_price'] !== null, description: $product->description, duplicateOverrideReason: $reason);
    }

    private function authorize(User $actor): void
    {
        if (! in_array($actor->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            abort(403);
        }
        $this->authorization->authorize($actor, ProductPermission::Create);
    }

    private function assertMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_CREATE, self::MODE_UPSERT], true)) {
            throw ValidationException::withMessages(['mode' => 'Select a valid import mode.']);
        }
    }

    private function header(string $header): string
    {
        return trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($header))), '_');
    }

    private function nullable(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
