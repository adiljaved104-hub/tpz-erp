<?php

namespace Tests\Feature\AiAssistant;

use App\Models\User;
use App\Services\AiAssistant\AiAssistantSettings;
use App\Services\AiAssistant\ErpReportToolRegistry;
use App\Services\AiAssistant\ResponsesProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ResponsesProviderFailureTest extends TestCase
{
    public function test_openai_mode_without_a_key_fails_instead_of_returning_mock_data(): void
    {
        config(['ai_assistant.provider' => 'openai', 'ai_assistant.api_key' => null]);
        $provider = new ResponsesProvider(\Mockery::mock(ErpReportToolRegistry::class));

        try {
            $provider->stream(new User, [['role' => 'user', 'content' => 'What were sales?']], fn (): null => null);
            self::fail('Expected a missing-key failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('OpenAI is selected but the server API key is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_responses_api_text_stream_collects_deltas_and_usage(): void
    {
        config([
            'ai_assistant.provider' => 'openai',
            'ai_assistant.model' => 'gpt-6-luna',
            'ai_assistant.api_key' => 'test-api-key',
            'ai_assistant.base_url' => 'https://api.openai.test/v1',
        ]);
        $this->mock(AiAssistantSettings::class)->shouldReceive('current')->andReturn([
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'daily_budget_usd' => 2,
            'monthly_budget_usd' => 30,
        ]);
        $tools = \Mockery::mock(ErpReportToolRegistry::class);
        $tools->shouldReceive('definitions')->once()->andReturn([]);
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response(implode("\n\n", [
                'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Today ']),
                'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'was quiet.']),
                'data: '.json_encode(['type' => 'response.completed', 'response' => [
                    'id' => 'resp_test',
                    'usage' => ['input_tokens' => 11, 'output_tokens' => 4],
                ]]),
            ])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);
        $emitted = '';

        $result = (new ResponsesProvider($tools))->stream(
            new User,
            [['role' => 'user', 'content' => 'What were sales?']],
            function (string $chunk) use (&$emitted): void {
                $emitted .= $chunk;
            },
        );

        self::assertSame('Today was quiet.', $result['text']);
        self::assertSame('Today was quiet.', $emitted);
        self::assertSame(11, $result['input_tokens']);
        self::assertSame(4, $result['output_tokens']);
        self::assertSame('resp_test', $result['response_id']);
    }

    public function test_responses_api_tool_calls_are_validated_and_replied_to_by_call_id(): void
    {
        config([
            'ai_assistant.provider' => 'openai',
            'ai_assistant.model' => 'gpt-6-luna',
            'ai_assistant.api_key' => 'test-api-key',
            'ai_assistant.base_url' => 'https://api.openai.test/v1',
        ]);
        $user = new User;
        $this->mock(AiAssistantSettings::class)->shouldReceive('current')->andReturn([
            'enabled' => true,
            'provider' => 'openai',
            'model' => 'gpt-6-luna',
            'daily_budget_usd' => 2,
            'monthly_budget_usd' => 30,
        ]);
        $tools = \Mockery::mock(ErpReportToolRegistry::class);
        $tools->shouldReceive('definitions')->twice()->andReturn([['type' => 'function', 'name' => 'sales_orders_report']]);
        $tools->shouldReceive('call')->once()->with($user, 'sales_orders_report', ['from' => '2026-11-12'])->andReturn([
            'period' => ['from' => '2026-11-12', 'to' => '2026-11-12', 'timezone' => 'Asia/Dubai'],
            'currency' => 'AED',
            'filters' => [],
            'source' => 'orders',
            'rows' => [],
        ]);
        $functionCall = [
            'type' => 'response.output_item.done',
            'item' => [
                'type' => 'function_call',
                'name' => 'sales_orders_report',
                'arguments' => '{"from":"2026-11-12"}',
                'call_id' => 'call_demo_1',
            ],
        ];
        $firstResponse = implode("\n\n", [
            'data: '.json_encode($functionCall),
            'data: '.json_encode(['type' => 'response.completed', 'response' => ['id' => 'resp_tool', 'usage' => ['input_tokens' => 8, 'output_tokens' => 2]]]),
        ])."\n\n";
        $secondResponse = implode("\n\n", [
            'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'No matching orders.']),
            'data: '.json_encode(['type' => 'response.completed', 'response' => ['id' => 'resp_final', 'usage' => ['input_tokens' => 13, 'output_tokens' => 4]]]),
        ])."\n\n";
        Http::fakeSequence()
            ->push($firstResponse, 200, ['Content-Type' => 'text/event-stream'])
            ->push($secondResponse, 200, ['Content-Type' => 'text/event-stream']);

        $result = (new ResponsesProvider($tools))->stream($user, [['role' => 'user', 'content' => 'Show today’s orders']], fn (): null => null);

        self::assertSame('No matching orders.', $result['text']);
        self::assertSame(21, $result['input_tokens']);
        self::assertSame(6, $result['output_tokens']);
        self::assertSame('resp_final', $result['response_id']);
        Http::assertSent(function (Request $request): bool {
            $input = $request->data()['input'] ?? [];
            $call = collect($input)->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call');
            $output = collect($input)->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output');

            return ($call['call_id'] ?? null) === 'call_demo_1'
                && ($output['call_id'] ?? null) === 'call_demo_1';
        });
    }

    public function test_openai_errors_are_returned_without_exposing_credentials(): void
    {
        config([
            'ai_assistant.provider' => 'openai',
            'ai_assistant.api_key' => 'test-secret-api-key-value',
            'ai_assistant.base_url' => 'https://api.openai.test/v1',
        ]);
        Http::fake(['https://api.openai.test/v1/responses' => Http::response(['error' => 'private detail'], 503)]);
        $tools = \Mockery::mock(ErpReportToolRegistry::class);
        $tools->shouldReceive('definitions')->once()->andReturn([]);
        $provider = new ResponsesProvider($tools);

        try {
            $provider->stream(new User, [['role' => 'user', 'content' => 'hello']], fn (): null => null);
            self::fail('Expected a safe provider failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('OpenAI request failed with HTTP 503', $exception->getMessage());
            self::assertStringNotContainsString('test-secret-api-key-value', $exception->getMessage());
        }
    }
}
