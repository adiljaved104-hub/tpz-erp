<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('sku')->unique();

            $table->string('name');

            $table->string('brand');

            $table->string('category');

            $table->string('model')->nullable();

            $table->string('condition')->default('New');

            $table->string('processor')->nullable();

            $table->string('ram')->nullable();

            $table->string('storage')->nullable();

            $table->string('screen_size')->nullable();

            $table->string('graphics')->nullable();

            $table->string('color')->nullable();

            $table->integer('warranty')->default(12);

            $table->decimal('cost_price', 10, 2)->default(0);

            $table->decimal('selling_price', 10, 2)->default(0);

            $table->text('description')->nullable();

            $table->string('status')->default('Active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
