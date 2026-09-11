<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CompanyEmailPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$OrDLeLwy3qwA3N5kt9Z26ea5dXUm5KvNAiObTeL906B9JSYAgO9e6';

    public function __construct(
        private readonly CompanyEmailPolicyService $emailPolicy,
        private readonly ActivityLogger $activity,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $passwordIsValid = Hash::check(
            $validated['password'],
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if (! $user || ! $passwordIsValid || filled($user->email_two_factor_enabled_at) || ! $this->emailPolicy->allowsAuthentication($user)) {
            $this->activity->log('mobile_auth.login_failed');

            return response()->json(
                ['message' => 'Invalid credentials.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $token = $user->createToken(trim($validated['device_name']));
        $this->activity->log('mobile_auth.login_succeeded', $user, $user, [
            'device_name' => trim($validated['device_name']),
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('employee');

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'employee' => [
                    'id' => $user->employee->id,
                    'employee_id' => $user->employee->employee_id,
                    'name' => $user->employee->name,
                    'designation' => $user->employee->designation,
                    'role' => $user->employee->role->value,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $this->activity->log('mobile_auth.logout_succeeded', $user, $user);

        return response()->json(['message' => 'Logged out.']);
    }
}
