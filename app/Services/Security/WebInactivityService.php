<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

final class WebInactivityService
{
    public const string SESSION_KEY = 'auth_security.last_activity_at';

    public function timeoutSeconds(): int
    {
        return ApplicationSecurityPolicy::webInactivityMinutes() * 60;
    }

    public function lastActivityAt(Request $request): int
    {
        return (int) $request->session()->get(self::SESSION_KEY, now()->timestamp);
    }

    public function expiresAt(Request $request): int
    {
        return $this->lastActivityAt($request) + $this->timeoutSeconds();
    }

    public function hasExpired(Request $request): bool
    {
        return now()->timestamp >= $this->expiresAt($request);
    }

    public function record(Request $request): int
    {
        $timestamp = now()->timestamp;
        $request->session()->put(self::SESSION_KEY, $timestamp);

        return $timestamp;
    }

    public function initialize(Request $request): void
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            $this->record($request);
        }
    }

    public function isForegroundNavigation(Request $request): bool
    {
        return $request->isMethodSafe()
            && $request->acceptsHtml()
            && (! $request->expectsJson())
            && (! $request->hasHeader('X-Livewire'));
    }
}
