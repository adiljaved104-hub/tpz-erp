<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('processor_class')->nullable()->after('processor');
            $table->string('processor_model')->nullable()->after('processor_class');
            $table->string('processor_generation')->nullable()->after('processor_model');
            $table->boolean('touch_screen')->nullable()->after('color');
            $table->boolean('is_convertible_360')->nullable()->after('touch_screen');
            $table->string('accounting_title_override')->nullable()->after('is_convertible_360');
            $table->string('website_title_override', 1000)->nullable()->after('accounting_title_override');
        });

        Schema::table('tax_invoices', function (Blueprint $table): void {
            $table->string('title_mode', 30)->default('auto')->after('source_order_id');
        });

        Schema::create('product_marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id');
            $table->foreignId('marketplace_platform_id');
            $table->string('marketplace_identifier')->nullable();
            $table->string('listing_sku')->nullable();
            $table->string('listing_title', 1000);
            $table->timestamps();
            $table->foreign('product_id', 'pml_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('marketplace_platform_id', 'pml_platform_fk')->references('id')->on('marketplace_platforms')->restrictOnDelete();
            $table->index(['product_id', 'marketplace_platform_id'], 'pml_product_platform_idx');
            $table->unique(['marketplace_platform_id', 'marketplace_identifier'], 'pml_platform_identifier_uq');
            $table->unique(['marketplace_platform_id', 'listing_sku'], 'pml_platform_sku_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_marketplace_listings');
        Schema::table('tax_invoices', fn (Blueprint $table) => $table->dropColumn('title_mode'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'processor_class', 'processor_model', 'processor_generation', 'touch_screen',
            'is_convertible_360', 'accounting_title_override', 'website_title_override',
        ]));
    }
};
