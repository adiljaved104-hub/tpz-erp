<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_request_source_lines', function (Blueprint $table): void {
            $table->unsignedInteger('approved_quantity')->default(0)->after('proposed_quantity');
            $table->unsignedInteger('transferred_quantity')->default(0)->after('approved_quantity');
            $table->index(['stock_request_item_id', 'approved_quantity'], 'srsl_item_approved_idx');
        });

        Schema::table('stock_request_execution_lines', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique('srel_idempotency_key_uq')->after('execution_type');
        });

        Schema::create('stock_request_source_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_request_source_line_id');
            $table->foreignId('actor_user_id');
            $table->unsignedInteger('quantity');
            $table->string('decision', 20);
            $table->text('note')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('created_at');
            $table->foreign('stock_request_source_line_id', 'srsale_line_fk')->references('id')->on('stock_request_source_lines')->restrictOnDelete();
            $table->foreign('actor_user_id', 'srsale_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['stock_request_source_line_id', 'decision'], 'srsale_line_decision_idx');
        });

        DB::statement("UPDATE stock_request_source_lines SET approved_quantity = proposed_quantity WHERE status = 'approved'");
        DB::statement("UPDATE stock_request_source_lines AS line SET transferred_quantity = (SELECT COALESCE(SUM(quantity), 0) FROM stock_request_execution_lines AS execution_line WHERE execution_line.stock_request_source_line_id = line.id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_source_approval_events');
        Schema::table('stock_request_execution_lines', function (Blueprint $table): void {
            $table->dropUnique('srel_idempotency_key_uq');
            $table->dropColumn('idempotency_key');
        });
        Schema::table('stock_request_source_lines', function (Blueprint $table): void {
            $table->dropIndex('srsl_item_approved_idx');
            $table->dropColumn(['approved_quantity', 'transferred_quantity']);
        });
    }
};
