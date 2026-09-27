<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyEmailPolicyService;
use App\Services\Security\PasswordAgeService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureEligibleEmployee
{
    public function __construct(
        private readonly CompanyEmailPolicyService $emailPolicy,
        private readonly PasswordAgeService $passwordAge,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();

        if (! $user instanceof User || ! $accessToken instanceof PersonalAccessToken || ! $this->emailPolicy->allowsAuthentication($user)) {
            if ($user instanceof User) {
                $user->tokens()->delete();
            }

            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($this->passwordAge->isExpired($user)) {
            $this->passwordAge->auditRotationRequired($user, 'mobile_api');

            return new JsonResponse([
                'message' => 'Your password has expired. Reset it before continuing.',
                'code' => 'password_rotation_required',
            ], Response::HTTP_PRECONDITION_REQUIRED);
        }

        return $next($request);
    }
}
