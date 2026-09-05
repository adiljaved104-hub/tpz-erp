<?php

namespace App\Services\Hikvision;

class HikvisionErrorSanitizer
{
    public function message(string $message): string
    {
        $secrets = array_filter([
            (string) config('hikvision.username'),
            (string) config('hikvision.password'),
        ]);
        $sanitized = str_ireplace($secrets, '[redacted]', $message);
        $sanitized = preg_replace('/(authorization|digest|password|username)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $sanitized) ?? '';
        $sanitized = preg_replace('#https?://[^\s/@:]+:[^\s/@]+@#i', '[redacted-url]', $sanitized) ?? '';

        return str($sanitized)->squish()->limit(500)->toString();
    }
}
