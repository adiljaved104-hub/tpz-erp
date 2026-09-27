<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security\ApplicationSecurityPolicy;
use App\Services\Security\PasswordAgeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceWebSecurityPolicy
{
    public function __construct(private readonly PasswordAgeService $passwordAge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->passwordAge->isExpired($user)) {
            $this->passwordAge->auditRotationRequired($user, 'web');
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your password has expired. Reset it before signing in again.',
                    'code' => 'password_rotation_required',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('auth.password.request')
                ->with('status', 'Your password has expired. Reset it before signing in again.');
        }

        $lastActivityAt = (int) $request->session()->get('auth_security.last_activity_at', now()->timestamp);
        $inactiveFor = now()->timestamp - $lastActivityAt;

        if ($inactiveFor >= ApplicationSecurityPolicy::WEB_INACTIVITY_MINUTES * 60) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired due to inactivity. Please sign in again.',
                    'code' => 'session_inactive',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('filament.admin.auth.login')
                ->with('status', 'Your session expired due to inactivity. Please sign in again.');
        }

        $request->session()->put('auth_security.last_activity_at', now()->timestamp);

        return $next($request);
    }
}
