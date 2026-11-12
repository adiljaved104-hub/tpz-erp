<?php

namespace Tests\Feature\AiAssistant;

use App\Models\AiAssistantAudit;
use App\Models\AiAssistantConversation;
use App\Models\AiAssistantSetting;
use App\Models\AiAssistantUsage;
use App\Models\User;
use App\Services\AiAssistant\AiAssistantService;
use App\Services\AiAssistant\AiAssistantSettings;
use App\Services\AiAssistant\AiAssistantStreamInterrupted;
use App\Services\AiAssistant\ResponsesProvider;
use App\Services\AiAssistant\SensitiveTextSanitizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiAssistantPersistenceTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $this->migration = require database_path('migrations/2026_11_12_090000_create_ai_assistant_tables.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_budget_reservations_count_against_the_hard_limit_for_the_next_request(): void
    {
        config([
            'ai_assistant.provider' => 'openai',
            'ai_assistant.model' => 'gpt-6-luna',
            'ai_assistant.max_output_tokens' => 1200,
        ]);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        AiAssistantSetting::query()->create([
            'id' => 1,
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'daily_budget_usd' => 0.09,
            'monthly_budget_usd' => 0.09,
        ]);
        $service = new AiAssistantService(
            $this->mock(ResponsesProvider::class),
            app(AiAssistantSettings::class),
            app(SensitiveTextSanitizer::class),
        );
        $reserve = new ReflectionMethod($service, 'reserveBudget');

        $first = $reserve->invoke($service, $user);

        self::assertSame('reserved', $first->status);
        self::assertEqualsWithDelta(0.0536, (float) $first->estimated_cost_usd, 0.000001);
        self::assertSame(1, AiAssistantUsage::query()->where('status', 'reserved')->count());

        try {
            $reserve->invoke($service, $user);
            self::fail('The next request should be blocked by the remaining hard budget.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('budget', $exception->errors());
        }

        self::assertSame(1, AiAssistantUsage::query()->where('status', 'reserved')->count());
    }

    public function test_retention_prunes_old_conversations_and_preserves_audit_history_without_orphans(): void
    {
        config(['ai_assistant.retention_days' => 30]);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        $expired = AiAssistantConversation::query()->create(['user_id' => $user->id, 'title' => 'Expired']);
        $recent = AiAssistantConversation::query()->create(['user_id' => $user->id, 'title' => 'Recent']);
        $expiredAt = now()->subDays(31);
        $expired->messages()->create(['role' => 'user', 'content' => 'Old prompt']);
        DB::table('ai_assistant_conversations')->where('id', $expired->id)->update([
            'created_at' => $expiredAt,
            'updated_at' => $expiredAt,
        ]);
        $audit = AiAssistantAudit::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $expired->id,
            'event' => 'message.completed',
        ]);

        Artisan::call('ai-assistant:prune-history');

        self::assertDatabaseMissing('ai_assistant_conversations', ['id' => $expired->id]);
        self::assertDatabaseHas('ai_assistant_conversations', ['id' => $recent->id]);
        self::assertDatabaseMissing('ai_assistant_messages', ['conversation_id' => $expired->id]);
        self::assertDatabaseHas('ai_assistant_audits', ['id' => $audit->id, 'conversation_id' => null]);
    }

    public function test_history_rejects_another_users_conversation(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password']);
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test-password']);
        $conversation = AiAssistantConversation::query()->create(['user_id' => $owner->id, 'title' => 'Private']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Private business question']);
        $service = new AiAssistantService(
            $this->mock(ResponsesProvider::class),
            app(AiAssistantSettings::class),
            app(SensitiveTextSanitizer::class),
        );

        try {
            $service->history($other, $conversation);
            self::fail('A different user must not read this conversation.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }

        self::assertCount(1, $service->history($owner, $conversation));
    }

    public function test_rename_requires_conversation_ownership_and_persists_the_title(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'rename-owner@example.test', 'password' => 'test-password']);
        $other = User::query()->create(['name' => 'Other', 'email' => 'rename-other@example.test', 'password' => 'test-password']);
        $conversation = AiAssistantConversation::query()->create(['user_id' => $owner->id, 'title' => 'New chat']);
        $service = new AiAssistantService(
            $this->mock(ResponsesProvider::class),
            app(AiAssistantSettings::class),
            app(SensitiveTextSanitizer::class),
        );

        self::assertSame('Monthly sales', $service->rename($owner, $conversation, '  Monthly sales  ')->title);
        self::assertDatabaseHas('ai_assistant_conversations', ['id' => $conversation->id, 'title' => 'Monthly sales']);

        try {
            $service->rename($other, $conversation, 'Should not persist');
            self::fail('A different user must not rename this conversation.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_stopped_response_keeps_partial_content_and_marks_usage_as_reserved_failure(): void
    {
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'mock', 'ai_assistant.model' => 'gpt-6-luna']);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'stopped-owner@example.test', 'password' => 'test-password']);
        AiAssistantSetting::query()->create(['id' => 1, 'enabled' => true, 'provider' => 'mock', 'model' => 'gpt-6-luna']);
        $conversation = AiAssistantConversation::query()->create(['user_id' => $user->id]);
        $provider = $this->mock(ResponsesProvider::class);
        $provider->shouldReceive('stream')->once()->andReturnUsing(function ($user, $history, $emit): never {
            $emit('partial answer');
            throw new AiAssistantStreamInterrupted('client stopped');
        });
        $service = new AiAssistantService($provider, app(AiAssistantSettings::class), app(SensitiveTextSanitizer::class));

        try {
            $service->send($user, $conversation, 'Show a report', fn () => null);
            self::fail('The stopped stream should be recorded and surfaced as interrupted.');
        } catch (AiAssistantStreamInterrupted) {
            self::assertDatabaseHas('ai_assistant_messages', ['conversation_id' => $conversation->id, 'content' => 'partial answer', 'status' => 'interrupted', 'error_code' => 'client_stopped']);
            self::assertDatabaseHas('ai_assistant_usage', ['user_id' => $user->id, 'status' => 'failed']);
            self::assertDatabaseHas('ai_assistant_audits', ['user_id' => $user->id, 'event' => 'message.interrupted']);
        }
    }
}
