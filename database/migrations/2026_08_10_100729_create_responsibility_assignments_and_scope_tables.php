<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsibility_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('team_id_at_assignment')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('team_name_at_assignment')->nullable();
            $table->enum('assignment_mode', ['scope', 'quantity']);
            $table->enum('status', ['active', 'inactive', 'transferred', 'superseded'])->default('active');
            $table->string('active_fingerprint', 64)->nullable()->unique();
            $table->timestamp('effective_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('predecessor_assignment_id')->nullable()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['team_id_at_assignment', 'status']);
            $table->index(['assignment_mode', 'status']);
            $table->index('effective_at');
            $table->index('ended_at');
        });

        Schema::create('responsibility_assignment_brands', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->foreignId('product_brand_id');
            $table->foreign('product_brand_id', 'ra_brands_brand_fk')->references('id')->on('product_brands')->restrictOnDelete();
            $table->index('product_brand_id', 'ra_brands_brand_idx');
            $table->timestamps();
        });

        Schema::create('responsibility_assignment_platforms', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->foreignId('marketplace_platform_id');
            $table->foreign('marketplace_platform_id', 'ra_platforms_platform_fk')->references('id')->on('marketplace_platforms')->restrictOnDelete();
            $table->index('marketplace_platform_id', 'ra_platforms_platform_idx');
            $table->timestamps();
        });

        Schema::create('responsibility_assignment_products', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->foreignId('product_id');
            $table->foreign('product_id', 'ra_products_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->index('product_id', 'ra_products_product_idx');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TABLE inventory_responsibility_quantities (
                    assignment_id INTEGER NOT NULL PRIMARY KEY,
                    product_inventory_id INTEGER NOT NULL,
                    assigned_quantity INTEGER NOT NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    CONSTRAINT inventory_responsibility_quantities_positive_check CHECK (assigned_quantity > 0),
                    FOREIGN KEY (assignment_id) REFERENCES responsibility_assignments (id) ON DELETE RESTRICT,
                    FOREIGN KEY (product_inventory_id) REFERENCES product_inventories (id) ON DELETE RESTRICT
                )
                SQL);
            DB::statement('CREATE INDEX inventory_responsibility_quantities_product_inventory_id_index ON inventory_responsibility_quantities (product_inventory_id)');
        } else {
            Schema::create('inventory_responsibility_quantities', function (Blueprint $table): void {
                $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
                $table->foreignId('product_inventory_id');
                $table->foreign('product_inventory_id', 'inventory_resp_qty_inventory_fk')->references('id')->on('product_inventories')->restrictOnDelete();
                $table->index('product_inventory_id', 'inventory_resp_qty_inventory_idx');
                $table->unsignedInteger('assigned_quantity');
                $table->timestamps();
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE responsibility_assignments ADD CONSTRAINT responsibility_assignments_values_check CHECK (assignment_mode IN ('scope', 'quantity') AND status IN ('active', 'inactive', 'transferred', 'superseded') AND ((status = 'active' AND active_fingerprint IS NOT NULL) OR (status <> 'active' AND active_fingerprint IS NULL)))");
            DB::statement('ALTER TABLE inventory_responsibility_quantities ADD CONSTRAINT inventory_responsibility_quantities_positive_check CHECK (assigned_quantity > 0)');
        }
    }

    public function down(): void
    {
        foreach (['inventory_responsibility_quantities', 'responsibility_assignment_products', 'responsibility_assignment_platforms', 'responsibility_assignment_brands', 'responsibility_assignments'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: {$table} contains responsibility history.");
            }
        }

        Schema::dropIfExists('inventory_responsibility_quantities');
        Schema::dropIfExists('responsibility_assignment_products');
        Schema::dropIfExists('responsibility_assignment_platforms');
        Schema::dropIfExists('responsibility_assignment_brands');
        Schema::dropIfExists('responsibility_assignments');
    }
};
