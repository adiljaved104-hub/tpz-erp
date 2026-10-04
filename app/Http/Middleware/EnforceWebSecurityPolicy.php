<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security\PasswordAgeService;
use App\Services\Security\WebInactivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceWebSecurityPolicy
{
    public function __construct(
        private readonly PasswordAgeService $passwordAge,
        private readonly WebInactivityService $inactivity,
    ) {}

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

        $this->inactivity->initialize($request);

        if ($this->inactivity->hasExpired($request)) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your session expired due to inactivity. Please sign in again.';
            $request->session()->flash('status', $message);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'code' => 'session_inactive',
                    'redirect' => route('filament.admin.auth.login'),
                ], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('filament.admin.auth.login')
                ->with('status', $message);
        }

        if ($this->inactivity->isForegroundNavigation($request)) {
            $this->inactivity->record($request);
        }

        return $next($request);
    }
}
