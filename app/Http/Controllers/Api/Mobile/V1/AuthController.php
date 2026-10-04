<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Exceptions\OtpChallengeException;
use App\Exceptions\OtpCooldownException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthenticationOtpService;
use App\Services\CompanyEmailPolicyService;
use App\Services\Security\LoginAttemptService;
use App\Services\Security\MfaPolicy;
use App\Services\Security\MobileMfaChallengeService;
use App\Services\Security\PasswordAgeService;
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
        private readonly LoginAttemptService $loginAttempts,
        private readonly MfaPolicy $mfaPolicy,
        private readonly MobileMfaChallengeService $mobileMfa,
        private readonly PasswordAgeService $passwordAge,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        if ($this->loginAttempts->isLocked($email, $request->ip())) {
            return response()->json(
                ['message' => 'Too many sign-in attempts. Try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $passwordIsValid = Hash::check(
            $validated['password'],
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if (! $user || ! $passwordIsValid || ! $this->emailPolicy->allowsAuthentication($user)) {
            $this->loginAttempts->recordFailure($email, $request->ip(), $user, 'mobile');
            $this->activity->log('mobile_auth.login_failed');

            return response()->json(
                ['message' => 'Invalid credentials.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($this->passwordAge->isExpired($user)) {
            $this->passwordAge->auditRotationRequired($user, 'mobile');

            return response()->json([
                'message' => 'Your password has expired. Reset it before signing in again.',
                'code' => 'password_rotation_required',
            ], Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (filled($user->email_two_factor_enabled_at) || $this->mfaPolicy->requires($user)) {
            try {
                $challenge = $this->mobileMfa->issue($user, $validated['device_name'], $request->ip());
            } catch (OtpCooldownException $exception) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'A verification code was recently sent. Use that code or try again when the cooldown ends.',
                    'retry_after' => $exception->secondsRemaining,
                ], Response::HTTP_TOO_MANY_REQUESTS);
            } catch (OtpChallengeException) {
                return response()->json([
                    'message' => 'Unable to send the verification code. Please try again.',
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $this->activity->log('mobile_auth.mfa_challenge_issued', $user, $user, [
                'channel' => 'mobile',
            ]);

            return response()->json([
                'mfa_required' => true,
                'challenge_id' => $challenge->id,
                'message' => 'Verification required. Enter the code sent to your company email.',
                'expires_in' => $challenge->expires_at->diffInSeconds(now()),
                'resend_after' => AuthenticationOtpService::RESEND_COOLDOWN_SECONDS,
            ], Response::HTTP_ACCEPTED);
        }

        return $this->completeLogin($user, trim($validated['device_name']), $email, $request->ip());
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $context = $this->mobileMfa->context($validated['challenge_id']);
        $user = $context === null
            ? null
            : User::query()->with('employee')->find($context['user_id']);

        if (! $user || ! $this->emailPolicy->allowsAuthentication($user)) {
            $this->mobileMfa->forget($validated['challenge_id']);

            return $this->invalidMfaResponse();
        }

        $email = mb_strtolower(trim((string) $user->email));

        if ($this->loginAttempts->isLocked($email, $request->ip())) {
            return response()->json(
                ['message' => 'Too many sign-in attempts. Try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        if ($this->passwordAge->isExpired($user)) {
            $this->mobileMfa->forget($validated['challenge_id']);
            $this->passwordAge->auditRotationRequired($user, 'mobile');

            return response()->json([
                'message' => 'Your password has expired. Reset it before signing in again.',
                'code' => 'password_rotation_required',
            ], Response::HTTP_PRECONDITION_REQUIRED);
        }

        $verified = $this->mobileMfa->verify($validated['challenge_id'], $validated['code']);

        if ($verified === null) {
            $this->activity->log('mobile_auth.mfa_failed');

            return $this->invalidMfaResponse();
        }

        return $this->completeLogin($verified['user'], $verified['device_name'], $email, $request->ip());
    }

    private function completeLogin(User $user, string $deviceName, string $email, ?string $ipAddress): JsonResponse
    {
        $this->loginAttempts->clear($email, $ipAddress);
        $token = $user->createToken($deviceName);
        $this->activity->log('mobile_auth.login_succeeded', $user, $user, [
            'device_name' => $deviceName,
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    private function invalidMfaResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid or expired verification code.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
