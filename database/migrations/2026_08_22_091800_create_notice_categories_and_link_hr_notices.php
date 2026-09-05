<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->boolean('status')->default(true)->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE hr_notices ADD COLUMN notice_category_id INTEGER NULL REFERENCES notice_categories(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX hr_notices_notice_category_id_index ON hr_notices(notice_category_id)');
        } else {
            Schema::table('hr_notices', function (Blueprint $table): void {
                $table->foreignId('notice_category_id')->nullable()->after('id')->index()
                    ->constrained('notice_categories')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notice_categories') && DB::table('notice_categories')->exists()) {
            throw new RuntimeException('Rollback refused: Notice Categories contain permanent HR configuration history.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX hr_notices_notice_category_id_index');
            DB::statement('ALTER TABLE hr_notices DROP COLUMN notice_category_id');
        } else {
            Schema::table('hr_notices', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('notice_category_id');
            });
        }
        Schema::dropIfExists('notice_categories');
    }
};
