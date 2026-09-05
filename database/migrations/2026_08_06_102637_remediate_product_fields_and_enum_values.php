<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const CONDITION_MAP = [
        'New' => 'new',
        'new' => 'new',
        'Renewed' => 'renewed',
        'renewed' => 'renewed',
        'Used' => 'used',
        'used' => 'used',
        'Open Box' => 'open_box',
        'OpenBox' => 'open_box',
        'open_box' => 'open_box',
        'Refurbished' => 'refurbished',
        'refurbished' => 'refurbished',
    ];

    /** @var array<string, string> */
    private const STATUS_MAP = [
        'Active' => 'active',
        'active' => 'active',
        'Inactive' => 'inactive',
        'inactive' => 'inactive',
        'Discontinued' => 'discontinued',
        'discontinued' => 'discontinued',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertKnownValues('condition', self::CONDITION_MAP);
        $this->assertKnownValues('status', self::STATUS_MAP);
        $this->assertWarrantyRange();
        $this->assertDecimalColumnFits('cost_price', 15, 4, true);
        $this->assertDecimalColumnFits('selling_price', 15, 2, false);

        foreach (self::CONDITION_MAP as $from => $to) {
            if ($from !== $to) {
                DB::table('products')->where('condition', $from)->update(['condition' => $to]);
            }
        }

        foreach (self::STATUS_MAP as $from => $to) {
            if ($from !== $to) {
                DB::table('products')->where('status', $from)->update(['status' => $to]);
            }
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('condition')->default('new')->change();
            $table->unsignedInteger('warranty')->default(12)->change();
            $table->decimal('cost_price', 15, 4)->nullable()->default(null)->change();
            $table->decimal('selling_price', 15, 2)->default(0)->change();
            $table->string('status')->default('active')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $reverseConditions = array_flip([
            'New' => 'new',
            'Renewed' => 'renewed',
            'Used' => 'used',
            'Open Box' => 'open_box',
            'Refurbished' => 'refurbished',
        ]);
        $reverseStatuses = array_flip([
            'Active' => 'active',
            'Inactive' => 'inactive',
            'Discontinued' => 'discontinued',
        ]);

        $this->assertKnownValues('condition', $reverseConditions);
        $this->assertKnownValues('status', $reverseStatuses);
        $this->assertWarrantyRange();
        $this->assertDecimalColumnFits('cost_price', 10, 2, false);
        $this->assertDecimalColumnFits('selling_price', 10, 2, false);

        foreach ($reverseConditions as $from => $to) {
            DB::table('products')->where('condition', $from)->update(['condition' => $to]);
        }

        foreach ($reverseStatuses as $from => $to) {
            DB::table('products')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('condition')->default('New')->change();
            $table->integer('warranty')->default(12)->change();
            $table->decimal('cost_price', 10, 2)->nullable(false)->default(0)->change();
            $table->decimal('selling_price', 10, 2)->default(0)->change();
            $table->string('status')->default('Active')->change();
        });
    }

    /** @param array<string, string> $allowed */
    private function assertKnownValues(string $column, array $allowed): void
    {
        $unknown = DB::table('products')
            ->select($column)
            ->distinct()
            ->pluck($column)
            ->reject(fn (mixed $value): bool => is_string($value) && array_key_exists($value, $allowed))
            ->values();

        if ($unknown->isNotEmpty()) {
            throw new RuntimeException("Unknown Product {$column} values: ".$unknown->map(
                fn (mixed $value): string => var_export($value, true),
            )->implode(', '));
        }
    }

    private function assertWarrantyRange(): void
    {
        $invalid = DB::table('products')
            ->select(['id', 'warranty'])
            ->get()
            ->first(function (object $product): bool {
                $value = $product->warranty;

                return ! ((is_int($value) || (is_string($value) && ctype_digit($value)))
                    && (int) $value >= 0
                    && (int) $value <= 600);
            });

        if ($invalid !== null) {
            throw new RuntimeException("Product {$invalid->id} has an invalid warranty value.");
        }
    }

    private function assertDecimalColumnFits(string $column, int $precision, int $scale, bool $nullable): void
    {
        foreach (DB::table('products')->select(['id', $column])->get() as $product) {
            $value = $product->{$column};

            if ($value === null) {
                if ($nullable) {
                    continue;
                }

                throw new RuntimeException("Product {$product->id} has null {$column}, which the target schema cannot represent.");
            }

            $decimal = (string) $value;

            if (! preg_match('/^\d+(?:\.\d+)?$/', $decimal)) {
                throw new RuntimeException("Product {$product->id} has an unsafe {$column} value.");
            }

            [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
            $integerDigits = strlen(ltrim($integer, '0')) ?: 1;
            $fractionDigits = strlen(rtrim($fraction, '0'));

            if ($integerDigits > $precision - $scale || $fractionDigits > $scale) {
                throw new RuntimeException("Product {$product->id} {$column} cannot fit DECIMAL({$precision},{$scale}) without data loss.");
            }
        }
    }
};
