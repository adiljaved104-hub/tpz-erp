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
        if (! DB::connection()->pretending()) {
            $main = DB::table('warehouses')->where('code', 'MAIN')->first();

            if ($main === null || $main->name !== 'Main Warehouse' || ! (bool) $main->status || ! (bool) $main->is_default) {
                throw new RuntimeException('MAIN must be the active default Main Warehouse before Inventory Locations can be enabled.');
            }

            if (DB::table('warehouses')->where('status', true)->where('is_default', true)->count() !== 1) {
                throw new RuntimeException('Exactly one active default Warehouse must exist before Inventory Locations can be enabled.');
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->addSqliteLocationColumns();

            return;
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('location_type', 50)->default('company_warehouse')->index();
            $table->foreignId('marketplace_platform_id')->nullable()->after('location_type')
                ->constrained('marketplace_platforms')->restrictOnDelete();
            $table->string('fulfillment_tag', 50)->nullable()->after('marketplace_platform_id');
            $table->foreignId('created_by_user_id')->nullable()->after('fulfillment_tag')
                ->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $specializedLocationsExist = DB::table('warehouses')
            ->where('location_type', '!=', 'company_warehouse')
            ->orWhereNotNull('marketplace_platform_id')
            ->orWhereNotNull('fulfillment_tag')
            ->orWhereNotNull('created_by_user_id')
            ->exists();

        if ($specializedLocationsExist) {
            throw new RuntimeException('Rollback refused because Inventory Location metadata is in use.');
        }

        if (DB::getDriverName() === 'sqlite') {
            throw new RuntimeException(
                'SQLite rollback refused: removing Inventory Location foreign-key columns would require rebuilding the referenced warehouses table. Use a separately reviewed rollback migration.'
            );
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropIndex(['location_type']);
            $table->dropForeign(['marketplace_platform_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['location_type', 'marketplace_platform_id', 'fulfillment_tag', 'created_by_user_id']);
        });
    }

    private function addSqliteLocationColumns(): void
    {
        DB::transaction(function (): void {
            DB::statement("ALTER TABLE \"warehouses\" ADD COLUMN \"location_type\" VARCHAR(50) NOT NULL DEFAULT 'company_warehouse'");
            DB::statement('ALTER TABLE "warehouses" ADD COLUMN "marketplace_platform_id" INTEGER NULL DEFAULT NULL REFERENCES "marketplace_platforms"("id") ON DELETE RESTRICT');
            DB::statement('ALTER TABLE "warehouses" ADD COLUMN "fulfillment_tag" VARCHAR(50) NULL DEFAULT NULL');
            DB::statement('ALTER TABLE "warehouses" ADD COLUMN "created_by_user_id" INTEGER NULL DEFAULT NULL REFERENCES "users"("id") ON DELETE RESTRICT');
            DB::statement('CREATE INDEX "warehouses_location_type_index" ON "warehouses" ("location_type")');
            DB::statement('CREATE INDEX "warehouses_marketplace_platform_id_index" ON "warehouses" ("marketplace_platform_id")');
            DB::statement('CREATE INDEX "warehouses_created_by_user_id_index" ON "warehouses" ("created_by_user_id")');
        });
    }
};
