<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 30)->default('mock');
            $table->string('model', 100)->default('gpt-6-luna');
            $table->decimal('daily_budget_usd', 8, 2)->default(2.00);
            $table->decimal('monthly_budget_usd', 8, 2)->default(30.00);
            $table->timestamps();
        });
        Schema::create('ai_assistant_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160)->default('New chat');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content')->nullable();
            $table->string('status', 20)->default('complete');
            $table->string('provider', 30)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('response_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_assistant_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_assistant_messages')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->string('status', 20);
            $table->timestamps();
            $table->index(['created_at', 'status']);
        });

        Schema::create('ai_assistant_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_assistant_conversations')->nullOnDelete();
            $table->string('event', 80)->index();
            $table->string('tool', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_audits');
        Schema::dropIfExists('ai_assistant_usage');
        Schema::dropIfExists('ai_assistant_messages');
        Schema::dropIfExists('ai_assistant_conversations');
        Schema::dropIfExists('ai_assistant_settings');
    }
};
