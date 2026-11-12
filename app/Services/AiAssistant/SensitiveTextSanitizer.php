<?php

namespace App\Services\AiAssistant;

class SensitiveTextSanitizer
{
    public function sanitize(string $text): string
    {
        $patterns = [
            '/\bsk-[A-Za-z0-9_-]{20,}\b/' => '[REDACTED API KEY]',
            '/\bAKIA[0-9A-Z]{16}\b/' => '[REDACTED ACCESS KEY]',
            '/(?i)\b(password|secret|api[_ -]?key|token)\s*[:=]\s*[^\s,;]+/' => '$1=[REDACTED]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? '[Message removed]';
    }
}
