<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE responsibility_assignment_conditions (
 assignment_id INTEGER NOT NULL PRIMARY KEY,
 product_condition VARCHAR(32) NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK(product_condition IN ('new','renewed','used','open_box','refurbished')),
 FOREIGN KEY(assignment_id) REFERENCES responsibility_assignments(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX responsibility_assignment_conditions_condition_idx ON responsibility_assignment_conditions(product_condition)');

            return;
        }

        Schema::create('responsibility_assignment_conditions', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->primary()->constrained('responsibility_assignments')->restrictOnDelete();
            $table->string('product_condition', 32);
            $table->timestamps();
            $table->index('product_condition', 'responsibility_assignment_conditions_condition_idx');
        });

        DB::statement("ALTER TABLE responsibility_assignment_conditions ADD CONSTRAINT responsibility_assignment_conditions_value_chk CHECK (product_condition IN ('new','renewed','used','open_box','refurbished'))");
    }

    public function down(): void
    {
        if (Schema::hasTable('responsibility_assignment_conditions')
            && DB::table('responsibility_assignment_conditions')->exists()) {
            throw new RuntimeException('Rollback refused: Condition Responsibility history exists.');
        }

        Schema::dropIfExists('responsibility_assignment_conditions');
    }
};
