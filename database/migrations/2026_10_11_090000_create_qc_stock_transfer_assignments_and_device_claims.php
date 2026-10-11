<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate existing Order claims before DDL, since MySQL may commit
        // table creation even when a later backfill check aborts the migration.
        $invalid = DB::table('qc_order_assignments')
            ->whereNull('released_at')->whereNotNull('active_device_id')
            ->whereColumn('active_device_id', '<>', 'qc_device_id')->exists();

        $duplicates = DB::table('qc_order_assignments')
            ->select('active_device_id')->whereNull('released_at')->whereNotNull('active_device_id')
            ->groupBy('active_device_id')->havingRaw('COUNT(*) > 1')->exists();

        if ($invalid || $duplicates) {
            throw new RuntimeException('Active QC Order assignments cannot be safely represented in the global device claim ledger.');
        }

        Schema::create('qc_stock_transfer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('stock_transfer_item_id');
            $table->unsignedBigInteger('qc_device_id');
            $table->unsignedBigInteger('qc_certificate_id');
            $table->unsignedBigInteger('active_device_id')->nullable();
            $table->unsignedInteger('certificate_version');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->timestamp('assigned_at');
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            foreach ([
                'stock_transfer_id' => ['stock_transfers', 'transfer'],
                'stock_transfer_item_id' => ['stock_transfer_items', 'item'],
                'qc_device_id' => ['qc_devices', 'device'],
                'qc_certificate_id' => ['qc_certificates', 'certificate'],
                'active_device_id' => ['qc_devices', 'active'],
                'assigned_by_user_id' => ['users', 'actor'],
                'released_by_user_id' => ['users', 'releaser'],
            ] as $column => [$target, $name]) {
                $table->foreign($column, 'qcsta_'.$name.'_fk')->references('id')->on($target)->restrictOnDelete();
            }

            $table->unique('active_device_id', 'qcsta_active_device_uq');
            $table->index(['stock_transfer_item_id', 'released_at'], 'qcsta_item_state_idx');
            $table->index(['qc_device_id', 'assigned_at'], 'qcsta_device_hist_idx');
            $table->index('stock_transfer_id', 'qcsta_transfer_idx');
        });

        Schema::create('qc_device_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('qc_device_id');
            $table->unsignedBigInteger('active_device_id')->nullable();
            $table->unsignedBigInteger('qc_order_assignment_id')->nullable();
            $table->unsignedBigInteger('qc_stock_transfer_assignment_id')->nullable();
            $table->unsignedBigInteger('claimed_by_user_id');
            $table->timestamp('claimed_at');
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            $table->foreign('qc_device_id', 'qcdc_device_fk')->references('id')->on('qc_devices')->restrictOnDelete();
            $table->foreign('active_device_id', 'qcdc_active_fk')->references('id')->on('qc_devices')->restrictOnDelete();
            $table->foreign('qc_order_assignment_id', 'qcdc_order_assignment_fk')->references('id')->on('qc_order_assignments')->restrictOnDelete();
            $table->foreign('qc_stock_transfer_assignment_id', 'qcdc_transfer_assignment_fk')->references('id')->on('qc_stock_transfer_assignments')->restrictOnDelete();
            $table->foreign('claimed_by_user_id', 'qcdc_claimed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by_user_id', 'qcdc_released_by_fk')->references('id')->on('users')->restrictOnDelete();

            // This nullable unique key is the database-enforced mutex shared by Orders and Stock Transfers.
            $table->unique('active_device_id', 'qcdc_active_device_uq');
            $table->unique('qc_order_assignment_id', 'qcdc_order_assignment_uq');
            $table->unique('qc_stock_transfer_assignment_id', 'qcdc_transfer_assignment_uq');
            $table->index(['qc_device_id', 'claimed_at'], 'qcdc_device_hist_idx');
        });

        $activeAssignments = DB::table('qc_order_assignments')
            ->whereNull('released_at')->whereNotNull('active_device_id')->orderBy('id')->get();

        foreach ($activeAssignments as $assignment) {
            DB::table('qc_device_claims')->insert([
                'qc_device_id' => $assignment->qc_device_id,
                'active_device_id' => $assignment->active_device_id,
                'qc_order_assignment_id' => $assignment->id,
                'qc_stock_transfer_assignment_id' => null,
                'claimed_by_user_id' => $assignment->assigned_by_user_id,
                'claimed_at' => $assignment->assigned_at,
            ]);
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('qc_device_claims') && DB::table('qc_device_claims')->exists())
            || (Schema::hasTable('qc_stock_transfer_assignments') && DB::table('qc_stock_transfer_assignments')->exists())) {
            throw new RuntimeException('QC device claim and transfer assignment history must not be removed by rollback.');
        }

        Schema::dropIfExists('qc_device_claims');
        Schema::dropIfExists('qc_stock_transfer_assignments');
    }
};
