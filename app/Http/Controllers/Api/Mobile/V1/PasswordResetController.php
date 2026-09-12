<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\AuthenticationOtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthenticationOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class PasswordResetController extends Controller
{
    private const GENERIC_RESPONSE = 'If the email is eligible, a verification code has been sent.';

    private const RESET_TOKEN_MINUTES = 10;

    public function __construct(
        private readonly AuthenticationOtpService $challenges,
        private readonly ActivityLogger $activity,
    ) {}

    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $challengeId = (string) Str::uuid();

        $user = $this->challenges->eligibleUserForCompanyEmail($email);

        if ($user) {
            try {
                $challenge = $this->challenges->issue(
                    $user,
                    AuthenticationOtpPurpose::PasswordReset,
                    $request->ip(),
                );

                $challengeId = (string) $challenge->id;
            } catch (Throwable) {
                // Keep the public response generic to avoid account enumeration.
            }
        }

        return response()->json([
            'message' => self::GENERIC_RESPONSE,
            'challenge_id' => $challengeId,
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:100'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->challenges->verify(
            $validated['challenge_id'],
            AuthenticationOtpPurpose::PasswordReset,
            $validated['code'],
        );

        if (! $user) {
            return response()->json([
                'message' => 'The verification code is invalid or has expired.',
            ], 422);
        }

        $payload = [
            'user_id' => $user->id,
            'password_fingerprint' => $this->passwordFingerprint($user->password),
            'expires_at' => now()->addMinutes(self::RESET_TOKEN_MINUTES)->timestamp,
        ];

        $resetToken = Crypt::encryptString(
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $this->activity->log(
            'mobile_auth.password_reset_verified',
            $user,
            $user,
        );

        return response()->json([
            'reset_token' => $resetToken,
            'expires_in' => self::RESET_TOKEN_MINUTES * 60,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $payload = json_decode(
                Crypt::decryptString($validated['reset_token']),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return $this->invalidResetTokenResponse();
        }

        if (
            ! is_array($payload)
            || ! isset($payload['user_id'], $payload['password_fingerprint'], $payload['expires_at'])
            || (int) $payload['expires_at'] < now()->timestamp
        ) {
            return $this->invalidResetTokenResponse();
        }

        $updated = DB::transaction(function () use ($payload, $validated): bool {
            $locked = User::query()
                ->with('employee')
                ->lockForUpdate()
                ->find((int) $payload['user_id']);

            if (
                ! $locked
                || ! $this->challenges->isEligible($locked)
                || ! hash_equals(
                    (string) $payload['password_fingerprint'],
                    $this->passwordFingerprint($locked->password),
                )
            ) {
                return false;
            }

            $locked->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $locked->tokens()->delete();

            $this->activity->log(
                'auth_security.password_reset_completed',
                $locked,
                $locked,
                ['actor_id' => $locked->id],
            );

            return true;
        });

        if (! $updated) {
            return $this->invalidResetTokenResponse();
        }

        return response()->json([
            'message' => 'Password updated. You can now sign in.',
        ]);
    }

    private function passwordFingerprint(string $passwordHash): string
    {
        return hash_hmac(
            'sha256',
            $passwordHash,
            (string) config('app.key'),
        );
    }

    private function invalidResetTokenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The password reset session is invalid or has expired.',
        ], 422);
    }
}

