<?php

namespace App\Filament\Pages\Finance;

use App\Enums\OfficeFinanceAccountType;
use App\Enums\OfficeFinancePermission;
use App\Models\OfficeFinanceAccount;
use App\Services\Finance\OfficeFinanceDashboardService;
use App\Services\Finance\OfficeFinanceService;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class PakistanOfficeAccounts extends BaseOfficeFinancePage
{
    protected string $view = 'filament.pages.finance.pakistan-office-accounts';

    protected static ?string $slug = 'finance/pakistan-office/accounts';

    protected static ?string $navigationLabel = 'Office Accounts';

    protected static ?string $navigationParentItem = 'Pakistan Office Finance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 15;

    protected static OfficeFinancePermission $requiredPermission = OfficeFinancePermission::Update;

    public string $accountId = '';

    public string $name = '';

    public string $type = 'cash';

    public bool $active = true;

    public string $description = '';

    public function editAccount(int $id): void
    {
        $account = OfficeFinanceAccount::query()->findOrFail($id);
        $this->accountId = (string) $account->id;
        $this->name = $account->name;
        $this->type = $account->account_type->value;
        $this->active = $account->active;
        $this->description = $account->description ?? '';
    }

    public function saveAccount(): void
    {
        try {
            $data = ['name' => $this->name, 'account_type' => $this->type, 'active' => $this->active, 'description' => $this->description ?: null];
            $this->accountId
                ? app(OfficeFinanceService::class)->updateAccount(OfficeFinanceAccount::query()->findOrFail((int) $this->accountId), $data, $this->user())
                : app(OfficeFinanceService::class)->createAccount($data, $this->user());
            Notification::make()->success()->title($this->accountId ? 'Office Account updated' : 'Office Account created')->send();
            $this->resetForm();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function getViewData(): array
    {
        return [
            'accounts' => OfficeFinanceAccount::query()->withCount('transactions')->orderBy('account_type')->orderBy('name')->get()->map(function (OfficeFinanceAccount $account): OfficeFinanceAccount {
                $account->setAttribute('ledger_balance', app(OfficeFinanceDashboardService::class)->totalBalance($account->id));

                return $account;
            }),
            'types' => OfficeFinanceAccountType::options(),
        ];
    }

    private function resetForm(): void
    {
        $this->reset('accountId', 'name', 'description');
        $this->type = 'cash';
        $this->active = true;
        $this->resetValidation();
    }
}
