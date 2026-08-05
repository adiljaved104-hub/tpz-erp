<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
    $table->id();

    $table->string('employee_id')->unique();

    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');

    $table->string('phone')->nullable();

    $table->foreignId('team_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete();

    $table->string('designation');

    $table->enum('role', [
        'Owner',
        'Admin',
        'Manager',
        'Staff',
    ])->default('Staff');

    $table->boolean('status')->default(true);

    $table->date('joining_date')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
