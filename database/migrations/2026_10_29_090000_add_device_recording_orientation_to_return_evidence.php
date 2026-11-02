<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_return_evidence', function (Blueprint $table): void {
            $table->string('device_recording_orientation', 24)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recorded evidence orientation is immutable audit metadata.');
    }
};
