<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $products = DB::table('products')->orderBy('id')->get(['id', 'brand', 'category']);
        $this->preflight($products);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE "products" ADD COLUMN "brand_id" INTEGER NULL REFERENCES "product_brands"("id") ON DELETE RESTRICT');
            DB::statement('ALTER TABLE "products" ADD COLUMN "category_id" INTEGER NULL REFERENCES "product_categories"("id") ON DELETE RESTRICT');
            DB::statement('CREATE INDEX "products_brand_id_index" ON "products" ("brand_id")');
            DB::statement('CREATE INDEX "products_category_id_index" ON "products" ("category_id")');
        } else {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('brand_id')->nullable()->constrained('product_brands')->restrictOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
                $table->index('brand_id');
                $table->index('category_id');
            });
        }

        DB::transaction(function () use ($products): void {
            $brandIds = $this->createCatalogRecords($products, 'brand', 'product_brands');
            $categoryIds = $this->createCatalogRecords($products, 'category', 'product_categories');

            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update([
                    'brand_id' => $brandIds[$this->normalize($product->brand)],
                    'category_id' => $categoryIds[$this->normalize($product->category)],
                ]);
            }

            if (! DB::connection()->pretending()) {
                $this->verifyLinkage($products);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['category_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['category_id']);
            $table->dropColumn(['brand_id', 'category_id']);
        });
    }

    private function preflight(iterable $products): void
    {
        foreach ($products as $product) {
            foreach (['brand', 'category'] as $field) {
                $display = $this->display((string) $product->{$field});
                $normalized = $this->normalize((string) $product->{$field});

                if ($display === '' || $normalized === '' || mb_strlen($display, 'UTF-8') > 255 || mb_strlen($normalized, 'UTF-8') > 255) {
                    throw new RuntimeException("Product {$product->id} has an invalid legacy {$field} value.");
                }
            }
        }
    }

    /** @return array<string, int> */
    private function createCatalogRecords(iterable $products, string $field, string $table): array
    {
        $ids = [];

        foreach ($products as $product) {
            $normalized = $this->normalize((string) $product->{$field});

            if (isset($ids[$normalized])) {
                continue;
            }

            $ids[$normalized] = (int) DB::table($table)->insertGetId([
                'name' => $this->display((string) $product->{$field}),
                'normalized_name' => $normalized,
                'status' => true,
                'created_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function verifyLinkage(iterable $originalProducts): void
    {
        $original = collect($originalProducts)->keyBy('id');
        $linked = DB::table('products as p')
            ->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->get(['p.id', 'p.brand', 'p.category', 'p.brand_id', 'p.category_id', 'b.normalized_name as brand_normalized', 'c.normalized_name as category_normalized']);

        foreach ($linked as $product) {
            $before = $original->get($product->id);

            if ($before === null || $product->brand !== $before->brand || $product->category !== $before->category) {
                throw new RuntimeException("Legacy Product text changed unexpectedly for Product {$product->id}.");
            }

            if ($product->brand_id === null || $product->category_id === null
                || $product->brand_normalized !== $this->normalize($product->brand)
                || $product->category_normalized !== $this->normalize($product->category)) {
                throw new RuntimeException("Catalog linkage verification failed for Product {$product->id}.");
            }
        }

        if ($linked->count() !== $original->count()) {
            throw new RuntimeException('Catalog linkage verification did not cover every Product.');
        }
    }

    private function display(string $value): string
    {
        $value = preg_replace('/^\s+|\s+$/u', '', $value) ?? '';

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    private function normalize(string $value): string
    {
        return mb_strtolower($this->display($value), 'UTF-8');
    }
};
