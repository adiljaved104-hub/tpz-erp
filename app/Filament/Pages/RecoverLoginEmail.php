<?php

namespace App\Filament\Pages;

use App\Exceptions\OtpChallengeException;
use App\Models\Employee;
use App\Models\LoginEmailRecoveryRequest;
use App\Models\User;
use App\Services\CompanyEmailPolicyService;
use App\Services\LoginEmailRecoveryService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

class RecoverLoginEmail extends Page
{
    protected string $view = 'filament.pages.recover-login-email';

    protected static ?string $slug = 'security/recover-login-email';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Recover Login Email';

    public int $targetUserId;

    public ?string $requestId = null;

    public string $step = 'reauth';

    public string $ownerPassword = '';

    public string $ownerTwoFactorCode = '';

    public string $reason = '';

    public string $newEmail = '';

    public string $newCode = '';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && Schema::hasTable('login_email_recovery_requests');
    }

    public function mount(LoginEmailRecoveryService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && static::canAccess(), 403);
        $target = Employee::query()->with('user.employee')->findOrFail(request()->integer('employee'))->user;
        abort_unless($target instanceof User, 404);
        $service->authorize($actor, $target);
        $this->targetUserId = $target->id;
    }

    public function start(LoginEmailRecoveryService $service): void
    {
        $this->validate(['ownerPassword' => ['required', 'string'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        try {
            $request = $service->start($this->actor(), $this->target(), $this->ownerPassword, $this->reason, request()->ip());
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }
        $this->requestId = $request->id;
        $this->reset('ownerPassword');
        $this->step = $request->owner_2fa_verified_at === null ? 'owner-2fa' : 'new-email';
    }

    public function verifyOwner(LoginEmailRecoveryService $service): void
    {
        $this->validate(['ownerTwoFactorCode' => ['required', 'digits:6']]);
        $service->verifyOwnerTwoFactor($this->recoveryRequest(), $this->ownerTwoFactorCode, $this->actor());
        $this->reset('ownerTwoFactorCode');
        $this->step = 'new-email';
    }

    public function sendNewCode(LoginEmailRecoveryService $service): void
    {
        $this->validate(['newEmail' => ['required', 'email:rfc', 'max:255']]);
        try {
            $service->sendNewEmailCode($this->recoveryRequest(), $this->newEmail, $this->actor(), request()->ip());
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }
        $this->step = 'verify-new';
        Notification::make()->success()->title('Verification code sent to the new company email')->send();
    }

    public function resendNewCode(LoginEmailRecoveryService $service): void
    {
        try {
            $service->resendNewEmailCode($this->recoveryRequest(), $this->actor(), request()->ip());
            Notification::make()->success()->title('A new verification code was sent')->send();
        } catch (OtpChallengeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function complete(LoginEmailRecoveryService $service): void
    {
        $this->validate(['newCode' => ['required', 'digits:6']]);
        $service->complete($this->recoveryRequest(), $this->newCode, $this->actor());
        $this->step = 'complete';
        Notification::make()->success()->title('Login email recovered successfully')->send();
    }

    public function getViewData(): array
    {
        return ['target' => $this->target(), 'domain' => app(CompanyEmailPolicyService::class)->allowedDomain()];
    }

    private function actor(): User
    {
        return auth()->user();
    }

    private function target(): User
    {
        return User::query()->with('employee')->findOrFail($this->targetUserId);
    }

    private function recoveryRequest(): LoginEmailRecoveryRequest
    {
        return LoginEmailRecoveryRequest::query()->with('user.employee')->findOrFail($this->requestId);
    }
}
