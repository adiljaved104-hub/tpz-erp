<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CompanyEmailPolicyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureEligibleEmployee
{
    public function __construct(private readonly CompanyEmailPolicyService $emailPolicy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $accessToken = $user?->currentAccessToken();

        if (! $user instanceof User || ! $accessToken instanceof PersonalAccessToken || ! $this->emailPolicy->allowsAuthentication($user)) {
            if ($accessToken instanceof PersonalAccessToken) {
                $accessToken->delete();
            }

            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
