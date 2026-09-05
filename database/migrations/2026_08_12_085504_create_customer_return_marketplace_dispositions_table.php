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
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TABLE customer_return_marketplace_dispositions (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_return_item_id INTEGER NOT NULL, result VARCHAR NOT NULL CHECK(result IN ('sellable','non_sellable')), quantity INTEGER NOT NULL CHECK(quantity > 0), recorded_by_user_id INTEGER NOT NULL, disposed_at DATETIME NOT NULL, posting_key VARCHAR NOT NULL UNIQUE, notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT, FOREIGN KEY(recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
            DB::statement('CREATE INDEX customer_return_marketplace_dispositions_item_result_index ON customer_return_marketplace_dispositions(customer_return_item_id,result)');

            return;
        }
        Schema::create('customer_return_marketplace_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_item_id');
            $table->foreign('customer_return_item_id', 'crm_dispositions_return_item_fk')->references('id')->on('customer_return_items')->restrictOnDelete();
            $table->string('result');
            $table->unsignedInteger('quantity');
            $table->foreignId('recorded_by_user_id');
            $table->foreign('recorded_by_user_id', 'crm_dispositions_recorder_fk')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('disposed_at');
            $table->uuid('posting_key')->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['customer_return_item_id', 'result'], 'crm_dispositions_item_result_idx');
        });
        DB::statement("ALTER TABLE customer_return_marketplace_dispositions ADD CONSTRAINT marketplace_disposition_values_check CHECK(result IN ('sellable','non_sellable') AND quantity > 0)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_return_marketplace_dispositions') && DB::table('customer_return_marketplace_dispositions')->exists()) {
            throw new RuntimeException('Rollback refused: marketplace disposition history exists.');
        }Schema::dropIfExists('customer_return_marketplace_dispositions');
    }
};
