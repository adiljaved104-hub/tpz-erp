<?php

namespace App\Filament\Pages;

use App\Enums\AuthenticationOtpPurpose;
use App\Exceptions\OtpChallengeException;
use App\Models\Employee;
use App\Models\LoginEmailChangeRequest;
use App\Models\User;
use App\Services\AuthenticationOtpService;
use App\Services\CompanyEmailPolicyService;
use App\Services\LoginEmailChangeService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

class ChangeLoginEmail extends Page
{
    protected string $view = 'filament.pages.change-login-email';

    protected static ?string $slug = 'security/change-login-email';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Change Login Email';

    public int $targetUserId;

    public ?string $requestId = null;

    public string $step = 'start';

    public string $currentCode = '';

    public string $newEmail = '';

    public string $newCode = '';

    public int $currentResendSeconds = 0;

    public int $newResendSeconds = 0;

    public int $currentCooldownVersion = 0;

    public int $newCooldownVersion = 0;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && Schema::hasTable('login_email_change_requests');
    }

    public function mount(LoginEmailChangeService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && static::canAccess(), 403);
        $employeeId = request()->integer('employee');
        $target = $employeeId > 0 ? Employee::query()->with('user.employee')->findOrFail($employeeId)->user : $actor;
        abort_unless($target instanceof User, 404);
        $service->authorize($actor, $target);
        $this->targetUserId = $target->id;
    }

    public function start(LoginEmailChangeService $service): void
    {
        try {
            $request = $service->start($this->actor(), $this->target(), request()->ip());
            $this->requestId = $request->id;
            $this->step = 'current';
            $this->currentResendSeconds = $this->remainingCooldown(AuthenticationOtpPurpose::EmailChangeCurrent);
            $this->currentCooldownVersion++;
            Notification::make()->success()->title('Verification code sent to the current login email')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function resendCurrent(LoginEmailChangeService $service): void
    {
        try {
            $service->resendCurrentEmailCode($this->changeRequest(), $this->actor(), request()->ip());
            $this->currentResendSeconds = $this->remainingCooldown(AuthenticationOtpPurpose::EmailChangeCurrent);
            $this->currentCooldownVersion++;
            Notification::make()->success()->title('A new verification code was sent')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function verifyCurrent(LoginEmailChangeService $service): void
    {
        $this->validate(['currentCode' => ['required', 'digits:6']]);
        $request = $service->verifyCurrent($this->changeRequest(), $this->currentCode, $this->actor());
        $this->requestId = $request->id;
        $this->reset('currentCode');
        $this->resetValidation();
        $this->step = 'new';
    }

    public function sendNewCode(LoginEmailChangeService $service): void
    {
        $this->validate(['newEmail' => ['required', 'email:rfc', 'max:255']]);
        try {
            $service->sendNewEmailCode($this->changeRequest(), $this->newEmail, $this->actor(), request()->ip());
            $this->step = 'verify-new';
            $this->newResendSeconds = $this->remainingCooldown(AuthenticationOtpPurpose::EmailChangeNew);
            $this->newCooldownVersion++;
            Notification::make()->success()->title('Verification code sent to the new company email')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function resendNew(LoginEmailChangeService $service): void
    {
        try {
            $service->resendNewEmailCode($this->changeRequest(), $this->actor(), request()->ip());
            $this->newResendSeconds = $this->remainingCooldown(AuthenticationOtpPurpose::EmailChangeNew);
            $this->newCooldownVersion++;
            Notification::make()->success()->title('A new verification code was sent')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function complete(LoginEmailChangeService $service): void
    {
        $this->validate(['newCode' => ['required', 'digits:6']]);
        $actor = $this->actor();
        $selfChange = $actor->id === $this->targetUserId;
        $service->complete($this->changeRequest(), $this->newCode, $actor, request()->session()->getId());
        if ($selfChange) {
            request()->session()->migrate(true);
        }
        $this->step = 'complete';
        Notification::make()->success()->title('Login email changed successfully')->send();
    }

    public function getViewData(): array
    {
        $target = $this->target();

        return [
            'target' => $target,
            'domain' => app(CompanyEmailPolicyService::class)->allowedDomain(),
            'status' => app(CompanyEmailPolicyService::class)->statusFor($target),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        throw_unless($actor instanceof User, AuthorizationException::class);

        return $actor;
    }

    private function target(): User
    {
        return User::query()->with('employee')->findOrFail($this->targetUserId);
    }

    private function changeRequest(): LoginEmailChangeRequest
    {
        return LoginEmailChangeRequest::query()->with('user.employee')->findOrFail($this->requestId);
    }

    private function remainingCooldown(AuthenticationOtpPurpose $purpose): int
    {
        return app(AuthenticationOtpService::class)->remainingCooldown($this->target(), $purpose);
    }
}
