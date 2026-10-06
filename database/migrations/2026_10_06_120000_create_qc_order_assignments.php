<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_order_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('qc_device_id');
            $table->unsignedBigInteger('qc_certificate_id');
            $table->unsignedBigInteger('active_device_id')->nullable()->unique('qoa_active_device_uq');
            $table->unsignedInteger('certificate_version');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->text('release_reason')->nullable();
            foreach (['order_id' => ['orders', 'order'], 'order_item_id' => ['order_items', 'item'], 'qc_device_id' => ['qc_devices', 'device'], 'qc_certificate_id' => ['qc_certificates', 'certificate'], 'active_device_id' => ['qc_devices', 'active'], 'assigned_by_user_id' => ['users', 'actor'], 'released_by_user_id' => ['users', 'releaser']] as $column => [$target, $name]) {
                $table->foreign($column, 'qoa_'.$name.'_fk')->references('id')->on($target)->restrictOnDelete();
            }
            $table->index(['order_item_id', 'released_at'], 'qoa_item_state_idx');
            $table->index(['qc_device_id', 'assigned_at'], 'qoa_device_history_idx');
            $table->index('order_id', 'qoa_order_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('qc_order_assignments') && DB::table('qc_order_assignments')->exists()) {
            throw new RuntimeException('QC assignment history must not be removed by rollback.');
        }
        Schema::dropIfExists('qc_order_assignments');
    }
};
