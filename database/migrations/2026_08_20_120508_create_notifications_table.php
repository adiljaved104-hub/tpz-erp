<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
                'notifications_inbox_index',
            );
            $table->index(
                ['notifiable_type', 'notifiable_id', 'type', 'created_at'],
                'notifications_type_index',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications') && DB::table('notifications')->exists()) {
            throw new RuntimeException('Rollback refused: persistent notification records exist.');
        }

        Schema::dropIfExists('notifications');
    }
};
