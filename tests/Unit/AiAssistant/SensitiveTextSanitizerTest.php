<?php

namespace Tests\Unit\AiAssistant;

use App\Services\AiAssistant\SensitiveTextSanitizer;
use PHPUnit\Framework\TestCase;

class SensitiveTextSanitizerTest extends TestCase
{
    public function test_credentials_are_redacted_before_persistence_or_provider_use(): void
    {
        $text = (new SensitiveTextSanitizer)->sanitize('api_key=secret-value password: hunter2 sk-123456789012345678901234');
        self::assertStringNotContainsString('secret-value', $text);
        self::assertStringNotContainsString('hunter2', $text);
        self::assertStringNotContainsString('sk-123456789012345678901234', $text);
        self::assertStringContainsString('[REDACTED API KEY]', $text);
    }
}
