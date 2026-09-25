<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_source_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_request_id');
            $table->foreign('stock_request_id', 'srsl_request_fk')->references('id')->on('stock_requests')->restrictOnDelete();
            $table->foreignId('stock_request_item_id');
            $table->foreign('stock_request_item_id', 'srsl_item_fk')->references('id')->on('stock_request_items')->restrictOnDelete();
            $table->foreignId('inventory_allocation_account_id');
            $table->foreign('inventory_allocation_account_id', 'srsl_account_fk')->references('id')->on('inventory_allocation_accounts')->restrictOnDelete();
            $table->string('source_type', 20);
            $table->string('source_label', 255);
            $table->unsignedInteger('proposed_quantity');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable();
            $table->foreign('decided_by_user_id', 'srsl_decider_fk')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('decision_idempotency_key')->nullable()->unique('srsl_decision_key_uq');
            $table->timestamps();
            $table->unique(['stock_request_item_id', 'inventory_allocation_account_id'], 'srsl_item_account_uq');
            $table->index(['stock_request_id', 'status'], 'srsl_request_status_idx');
            $table->index(['inventory_allocation_account_id', 'status'], 'srsl_account_status_idx');
        });

        DB::table('stock_request_items')->orderBy('id')->chunkById(100, function ($items): void {
            foreach ($items as $item) {
                $sources = json_decode((string) $item->proposed_sources, true);
                foreach (is_array($sources) ? $sources : [] as $source) {
                    if (empty($source['account_id']) || empty($source['proposed_quantity'])) {
                        continue;
                    }

                    DB::table('stock_request_source_lines')->insertOrIgnore([
                        'stock_request_id' => $item->stock_request_id,
                        'stock_request_item_id' => $item->id,
                        'inventory_allocation_account_id' => $source['account_id'],
                        'source_type' => $source['type'] ?? 'employee',
                        'source_label' => $source['label'] ?? 'Allocation Holder',
                        'proposed_quantity' => $source['proposed_quantity'],
                        'status' => 'pending',
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                }
            }
        }, 'id');
    }

    public function down(): void
    {
        DB::table('stock_requests')->where('status', '<>', 'pending')->update(['status' => 'pending']);
        Schema::dropIfExists('stock_request_source_lines');
    }
};
