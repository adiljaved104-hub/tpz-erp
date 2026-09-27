<?php

namespace App\Services\Security;

use App\Enums\AuthenticationOtpPurpose;
use App\Models\AuthenticationOtpChallenge;
use App\Models\User;
use App\Services\AuthenticationOtpService;
use Illuminate\Support\Facades\Cache;

final class MobileMfaChallengeService
{
    private const string CACHE_KEY_PREFIX = 'mobile-auth:mfa:';

    public function __construct(private readonly AuthenticationOtpService $otp) {}

    public function issue(User $user, string $deviceName, ?string $ipAddress): AuthenticationOtpChallenge
    {
        $challenge = $this->otp->issue($user, AuthenticationOtpPurpose::TwoFactor, $ipAddress);

        Cache::put($this->cacheKey($challenge->id), [
            'user_id' => (int) $user->getKey(),
            'device_name' => trim($deviceName),
            'credential_fingerprint' => $this->credentialFingerprint($user),
        ], $challenge->expires_at);

        return $challenge;
    }

    /** @return array{user_id: int, device_name: string, credential_fingerprint: string}|null */
    public function context(string $challengeId): ?array
    {
        $context = Cache::get($this->cacheKey($challengeId));

        if (! is_array($context)
            || ! is_int($context['user_id'] ?? null)
            || ! is_string($context['device_name'] ?? null)
            || ! is_string($context['credential_fingerprint'] ?? null)) {
            return null;
        }

        return $context;
    }

    /** @return array{user: User, device_name: string}|null */
    public function verify(string $challengeId, string $code): ?array
    {
        $context = $this->context($challengeId);

        if ($context === null) {
            return null;
        }

        $user = User::query()->with('employee')->find($context['user_id']);

        if (! $user || ! hash_equals($context['credential_fingerprint'], $this->credentialFingerprint($user))) {
            $this->forget($challengeId);

            return null;
        }

        $verifiedUser = $this->otp->verify($challengeId, AuthenticationOtpPurpose::TwoFactor, $code);

        if (! $verifiedUser || (int) $verifiedUser->getKey() !== $context['user_id']) {
            return null;
        }

        $this->forget($challengeId);

        return [
            'user' => $verifiedUser,
            'device_name' => $context['device_name'],
        ];
    }

    public function forget(string $challengeId): void
    {
        Cache::forget($this->cacheKey($challengeId));
    }

    private function cacheKey(string $challengeId): string
    {
        return self::CACHE_KEY_PREFIX.hash('sha256', $challengeId);
    }

    private function credentialFingerprint(User $user): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                (string) $user->getKey(),
                (string) $user->getAuthPassword(),
                (string) $user->password_changed_at?->getTimestamp(),
            ]),
            (string) config('app.key'),
        );
    }
}
