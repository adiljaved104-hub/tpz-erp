<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_evidence', function (Blueprint $table): void {
            $table->timestamp('captured_at_device')->nullable()->after('duration_seconds');
            $table->string('capture_timezone', 64)->nullable()->after('captured_at_device');
        });

        Schema::create('customer_return_evidence_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_evidence_id')->unique('crel_evidence_unique')
                ->constrained('customer_return_evidence', 'id', 'crel_evidence_fk')->restrictOnDelete();
            $table->string('path', 255)->unique('crel_path_unique');
            $table->char('sha256', 64);
            $table->string('mime_type', 50);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Customer return evidence labels are permanent audit history.');
    }
};
