<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_evidence', function (Blueprint $table): void {
            // Reported by the employee after reviewing the recording; original
            // media bytes and metadata are never altered. Null = legacy upload.
            $table->unsignedSmallInteger('video_rotation_degrees')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Evidence orientation metadata is permanent audit history.');
    }
};
