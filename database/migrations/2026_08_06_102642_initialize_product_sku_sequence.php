<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $highest = 0;
        $skus = DB::connection()->pretending()
            ? DB::connection()->getPdo()->query('SELECT sku FROM products')->fetchAll(PDO::FETCH_COLUMN)
            : DB::table('products')->pluck('sku');

        foreach ($skus as $sku) {
            if (! is_string($sku) || preg_match('/^TPZ-(\d{6,})$/', $sku, $matches) !== 1) {
                continue;
            }

            if (strlen($matches[1]) > strlen((string) PHP_INT_MAX)) {
                throw new RuntimeException("Product SKU {$sku} is too large for the reference allocator.");
            }

            $number = (int) $matches[1];

            if ($number >= PHP_INT_MAX) {
                throw new RuntimeException("Product SKU {$sku} exhausted the reference allocator.");
            }

            $highest = max($highest, $number);
        }

        $expectedNext = $highest + 1;

        DB::table('reference_sequences')->insertOrIgnore([
            'key' => 'product_sku',
            'next_value' => $expectedNext,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::connection()->pretending()) {
            return;
        }

        $storedNext = DB::table('reference_sequences')->where('key', 'product_sku')->value('next_value');

        if (! is_numeric($storedNext) || (int) $storedNext < $expectedNext) {
            throw new RuntimeException('The product_sku sequence conflicts with existing Product SKUs.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // References are never decremented or deleted because they may have been consumed.
    }
};
