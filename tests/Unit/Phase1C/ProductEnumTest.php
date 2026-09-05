<?php

namespace Tests\Unit\Phase1C;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Product;
use PHPUnit\Framework\TestCase;

class ProductEnumTest extends TestCase
{
    public function test_approved_condition_values_and_labels(): void
    {
        $this->assertSame(
            ['new', 'renewed', 'used', 'open_box', 'refurbished'],
            array_column(ProductCondition::cases(), 'value'),
        );
        $this->assertSame('Open Box', ProductCondition::OpenBox->label());
    }

    public function test_approved_status_values(): void
    {
        $this->assertSame(['active', 'inactive', 'discontinued'], array_column(ProductStatus::cases(), 'value'));
    }

    public function test_warranty_formatting(): void
    {
        $this->assertSame('No warranty', Product::warrantyLabel(0));
        $this->assertSame('1 year', Product::warrantyLabel(12));
        $this->assertSame('2 years', Product::warrantyLabel(24));
        $this->assertSame('36 months', Product::warrantyLabel(36));
    }
}
