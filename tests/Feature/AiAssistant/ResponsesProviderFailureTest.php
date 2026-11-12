<?php

namespace Tests\Feature\AiAssistant;

use App\Models\User;
use App\Services\AiAssistant\ErpReportToolRegistry;
use App\Services\AiAssistant\ResponsesProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ResponsesProviderFailureTest extends TestCase
{
    public function test_openai_errors_are_returned_without_exposing_credentials(): void
    {
        config([
            'ai_assistant.provider' => 'openai',
            'ai_assistant.api_key' => 'test-secret-api-key-value',
            'ai_assistant.base_url' => 'https://api.openai.test/v1',
        ]);
        Http::fake(['https://api.openai.test/v1/responses' => Http::response(['error' => 'private detail'], 503)]);
        $provider = new ResponsesProvider(\Mockery::mock(ErpReportToolRegistry::class));

        try {
            $provider->stream(new User, [['role' => 'user', 'content' => 'hello']], fn (): null => null);
            self::fail('Expected a safe provider failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('OpenAI request failed with HTTP 503', $exception->getMessage());
            self::assertStringNotContainsString('test-secret-api-key-value', $exception->getMessage());
        }
    }
}
