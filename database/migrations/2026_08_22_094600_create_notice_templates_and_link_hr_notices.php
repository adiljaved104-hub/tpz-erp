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
            DB::statement("CREATE TABLE notice_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notice_category_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL,
                default_title VARCHAR(255) NOT NULL,
                default_content TEXT NOT NULL,
                default_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                default_acknowledgment_required TINYINT(1) NOT NULL DEFAULT 0,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INTEGER NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE(notice_category_id, normalized_name),
                CHECK(length(trim(name)) > 0 AND length(trim(default_title)) > 0 AND length(trim(default_content)) > 0 AND default_priority IN ('normal','important') AND default_acknowledgment_required IN (0,1) AND status IN (0,1)),
                FOREIGN KEY(notice_category_id) REFERENCES notice_categories(id) ON DELETE RESTRICT,
                FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            )");
            DB::statement('CREATE INDEX notice_templates_category_status_index ON notice_templates(notice_category_id, status)');
            DB::statement('ALTER TABLE hr_notices ADD COLUMN notice_template_id INTEGER NULL REFERENCES notice_templates(id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX hr_notices_notice_template_id_index ON hr_notices(notice_template_id)');

            return;
        }

        Schema::create('notice_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_category_id')->constrained('notice_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('default_title');
            $table->text('default_content');
            $table->string('default_priority', 20)->default('normal');
            $table->boolean('default_acknowledgment_required')->default(false);
            $table->boolean('status')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['notice_category_id', 'normalized_name']);
            $table->index(['notice_category_id', 'status'], 'notice_templates_category_status_index');
        });
        DB::statement("ALTER TABLE notice_templates ADD CONSTRAINT notice_templates_values_check CHECK (CHAR_LENGTH(TRIM(name)) > 0 AND CHAR_LENGTH(TRIM(default_title)) > 0 AND CHAR_LENGTH(TRIM(default_content)) > 0 AND default_priority IN ('normal','important') AND default_acknowledgment_required IN (0,1) AND status IN (0,1))");

        Schema::table('hr_notices', function (Blueprint $table): void {
            $table->foreignId('notice_template_id')->nullable()->after('notice_category_id')->index()
                ->constrained('notice_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('notice_templates') && DB::table('notice_templates')->exists()) {
            throw new RuntimeException('Rollback refused: Notice Templates contain permanent HR configuration history.');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX hr_notices_notice_template_id_index');
            DB::statement('ALTER TABLE hr_notices DROP COLUMN notice_template_id');
        } else {
            Schema::table('hr_notices', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('notice_template_id');
            });
        }
        Schema::dropIfExists('notice_templates');
    }
};
