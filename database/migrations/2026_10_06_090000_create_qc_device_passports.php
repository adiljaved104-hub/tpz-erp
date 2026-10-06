<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique('qcd_reference_uq');
            $table->string('serial', 100);
            $table->char('serial_key', 64)->unique('qcd_serial_uq');
            $table->string('device_type', 30)->default('laptop');
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id', 'qcd_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('qc_inspections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('active_device_id')->nullable()->unique('qci_active_uq');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('technician_user_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->json('product_snapshot');
            $table->json('order_snapshot')->nullable();
            $table->json('original_configuration');
            $table->json('requested_configuration')->nullable();
            $table->json('final_configuration')->nullable();
            $table->json('features');
            $table->string('grade', 10)->nullable();
            $table->text('public_remarks')->nullable();
            $table->text('internal_remarks')->nullable();
            $table->text('reinspection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'version'], 'qci_device_version_uq');
            $table->unique(['technician_user_id', 'idempotency_key'], 'qci_retry_uq');
            $table->index(['status', 'created_at'], 'qci_status_idx');
            $table->index('order_item_id', 'qci_item_idx');
            $table->index('warehouse_id', 'qci_location_idx');
            foreach (['device_id' => ['qc_devices', 'device'], 'active_device_id' => ['qc_devices', 'active'], 'technician_user_id' => ['users', 'technician'], 'warehouse_id' => ['warehouses', 'warehouse'], 'order_id' => ['orders', 'order'], 'order_item_id' => ['order_items', 'item']] as $column => [$target, $name]) {
                $foreign = $table->foreign($column, 'qci_'.$name.'_fk')->references('id')->on($target);
                in_array($column, ['order_id', 'order_item_id'], true) ? $foreign->nullOnDelete() : $foreign->restrictOnDelete();
            }
        });
        Schema::create('qc_check_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inspection_id');
            $table->string('check_key', 80);
            $table->unsignedSmallInteger('sort_order');
            $table->json('definition');
            $table->boolean('applicable');
            $table->string('result', 10)->nullable();
            $table->decimal('measurement', 12, 3)->nullable();
            $table->text('detail')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('inspection_id', 'qcr_inspection_fk')->references('id')->on('qc_inspections')->restrictOnDelete();
            $table->unique(['inspection_id', 'check_key'], 'qcr_check_uq');
            $table->index(['inspection_id', 'sort_order'], 'qcr_order_idx');
        });
        Schema::create('qc_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique('qce_public_uq');
            $table->unsignedBigInteger('inspection_id');
            $table->unsignedBigInteger('uploaded_by');
            $table->string('kind', 30);
            $table->boolean('customer_visible')->default(true);
            $table->string('original_path');
            $table->string('customer_path');
            $table->char('checksum', 64);
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->foreign('inspection_id', 'qce_inspection_fk')->references('id')->on('qc_inspections')->restrictOnDelete();
            $table->foreign('uploaded_by', 'qce_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['inspection_id', 'kind'], 'qce_kind_idx');
        });
        Schema::create('qc_certificates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inspection_id')->unique('qcc_inspection_uq');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('version');
            $table->char('public_token', 64)->unique('qcc_token_uq');
            $table->json('snapshot');
            $table->timestamp('certified_at');
            $table->foreign('inspection_id', 'qcc_inspection_fk')->references('id')->on('qc_inspections')->restrictOnDelete();
            $table->foreign('device_id', 'qcc_device_fk')->references('id')->on('qc_devices')->restrictOnDelete();
            $table->unique(['device_id', 'version'], 'qcc_version_uq');
        });
        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $suffix) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER qcc_{$suffix}_guard BEFORE {$operation} ON qc_certificates BEGIN SELECT RAISE(ABORT, 'QC certificates are immutable'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                try {
                    DB::unprepared("CREATE TRIGGER qcc_{$suffix}_guard BEFORE {$operation} ON qc_certificates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC certificates are immutable'");
                } catch (QueryException $exception) {
                    // Triggers are defense-in-depth and may be unavailable on restricted MySQL
                    // hosting with binary logging. Application immutability remains mandatory.
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1419) {
                        throw $exception;
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('qc_certificates') && DB::table('qc_certificates')->exists()) {
            throw new RuntimeException('Certified QC history must not be removed by rollback.');
        }
        foreach (['qc_certificates', 'qc_evidence', 'qc_check_results', 'qc_inspections', 'qc_devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
