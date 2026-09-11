<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthenticationOtpPurpose;
use App\Exceptions\OtpChallengeException;
use App\Models\AuthenticationOtpChallenge;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuthenticationOtpService;
use App\Services\LoginBrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmailOtpAuthenticationController extends Controller
{
    private const GENERIC_RESPONSE = 'If the account is eligible, a verification code has been sent.';

    public function requestLogin(LoginBrandingService $branding): View
    {
        return view('auth.otp-request', ['branding' => $branding->presentation(), 'mode' => 'login']);
    }

    public function sendLogin(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email'];
        $this->throttlePublicRequest($email, $request->ip());
        $challengeId = $this->issueForEmail($email, AuthenticationOtpPurpose::Login, $request, $challenges);
        $request->session()->put('auth_otp.login_challenge', $challengeId ?? (string) Str::uuid());

        return redirect()->route('auth.otp.verify')->with('status', self::GENERIC_RESPONSE);
    }

    public function verifyLoginForm(Request $request, LoginBrandingService $branding): View|RedirectResponse
    {
        if (! $request->session()->has('auth_otp.login_challenge')) {
            return redirect()->route('auth.otp.request');
        }

        return view('auth.otp-verify', $this->verificationViewData(
            $branding,
            $request,
            'auth_otp.login_challenge',
            AuthenticationOtpPurpose::Login,
            'login',
        ));
    }

    public function verifyLogin(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $challengeId = (string) $request->session()->get('auth_otp.login_challenge');
        $user = $challenges->verify($challengeId, AuthenticationOtpPurpose::Login, $code);

        if (! $user || ! $user->canAccessPanel(filament()->getPanel('admin'))) {
            return back()->withErrors(['code' => 'The verification code is invalid or has expired.']);
        }

        Auth::login($user);
        $request->session()->forget('auth_otp.login_challenge');
        $request->session()->forget('url.intended');
        $request->session()->regenerate();

        return redirect()->to(filament()->getPanel('admin')->getUrl());
    }

    public function resendLogin(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        return $this->resend($request, $challenges, 'auth_otp.login_challenge', AuthenticationOtpPurpose::Login);
    }

    public function forgotPassword(LoginBrandingService $branding): View
    {
        return view('auth.otp-request', ['branding' => $branding->presentation(), 'mode' => 'password_reset']);
    }

    public function sendPasswordReset(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'email:rfc', 'max:255']])['email'];
        $this->throttlePublicRequest($email, $request->ip());
        $challengeId = $this->issueForEmail($email, AuthenticationOtpPurpose::PasswordReset, $request, $challenges);
        $request->session()->put('auth_otp.password_reset_challenge', $challengeId ?? (string) Str::uuid());

        return redirect()->route('auth.password.verify')->with('status', self::GENERIC_RESPONSE);
    }

    public function verifyPasswordResetForm(Request $request, LoginBrandingService $branding): View|RedirectResponse
    {
        if (! $request->session()->has('auth_otp.password_reset_challenge')) {
            return redirect()->route('auth.password.request');
        }

        return view('auth.otp-verify', $this->verificationViewData(
            $branding,
            $request,
            'auth_otp.password_reset_challenge',
            AuthenticationOtpPurpose::PasswordReset,
            'password_reset',
        ));
    }

    public function resendPasswordReset(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        return $this->resend($request, $challenges, 'auth_otp.password_reset_challenge', AuthenticationOtpPurpose::PasswordReset);
    }

    public function verifyPasswordReset(Request $request, AuthenticationOtpService $challenges): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $user = $challenges->verify((string) $request->session()->get('auth_otp.password_reset_challenge'), AuthenticationOtpPurpose::PasswordReset, $code);
        if (! $user) {
            return back()->withErrors(['code' => 'The verification code is invalid or has expired.']);
        }

        $request->session()->forget('auth_otp.password_reset_challenge');
        $request->session()->put('auth_otp.password_reset_user', $user->id);

        return redirect()->route('auth.password.reset');
    }

    public function resetPasswordForm(Request $request, LoginBrandingService $branding): View|RedirectResponse
    {
        if (! $request->session()->has('auth_otp.password_reset_user')) {
            return redirect()->route('auth.password.request');
        }

        return view('auth.password-reset', ['branding' => $branding->presentation()]);
    }

    public function resetPassword(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $userId = (int) $request->session()->pull('auth_otp.password_reset_user');
        $user = User::query()->with('employee')->find($userId);
        if (! $user || $user->employee?->status !== true) {
            return redirect()->route('auth.password.request');
        }

        DB::transaction(function () use ($user, $validated, $activity): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
            ])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $locked->tokens()->delete();
            $activity->log('auth_security.password_reset_completed', $locked, $locked, ['actor_id' => $locked->id]);
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.auth.login')->with('status', 'Password updated. You can now sign in.');
    }

    private function issueForEmail(string $email, AuthenticationOtpPurpose $purpose, Request $request, AuthenticationOtpService $challenges): ?string
    {
        $user = $challenges->eligibleUserForCompanyEmail($email);
        if (! $user) {
            return null;
        }

        try {
            return $challenges->issue($user, $purpose, $request->ip())->id;
        } catch (OtpChallengeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Unable to send')) {
                throw ValidationException::withMessages(['email' => $exception->getMessage()]);
            }

            return null;
        }
    }

    private function throttlePublicRequest(string $email, ?string $ip): void
    {
        $key = 'public-auth-otp:'.hash('sha256', mb_strtolower(trim($email)).'|'.(string) $ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many verification requests. Please try again later.');
        }
        RateLimiter::hit($key, 600);
    }

    /** @return array<string, mixed> */
    private function verificationViewData(
        LoginBrandingService $branding,
        Request $request,
        string $sessionKey,
        AuthenticationOtpPurpose $purpose,
        string $mode,
    ): array {
        $challenge = AuthenticationOtpChallenge::query()->with('user')->find($request->session()->get($sessionKey));
        $resendSeconds = $challenge && $challenge->purpose === $purpose
            ? app(AuthenticationOtpService::class)->remainingCooldown($challenge->user, $purpose)
            : 0;

        return [
            'branding' => $branding->presentation(),
            'mode' => $mode,
            'expiryMinutes' => AuthenticationOtpService::EXPIRY_MINUTES,
            'resendSeconds' => $resendSeconds,
            'resendKey' => $challenge?->id ?? $purpose->value,
        ];
    }

    private function resend(
        Request $request,
        AuthenticationOtpService $challenges,
        string $sessionKey,
        AuthenticationOtpPurpose $purpose,
    ): RedirectResponse {
        $challenge = AuthenticationOtpChallenge::query()->with('user.employee')->find($request->session()->get($sessionKey));
        if ($challenge && $challenge->purpose === $purpose) {
            try {
                $new = $challenges->issue($challenge->user, $purpose, $request->ip());
                $request->session()->put($sessionKey, $new->id);
            } catch (OtpChallengeException $exception) {
                return back()->withErrors(['code' => $exception->getMessage()]);
            }
        }

        return back()->with('status', self::GENERIC_RESPONSE);
    }
}
