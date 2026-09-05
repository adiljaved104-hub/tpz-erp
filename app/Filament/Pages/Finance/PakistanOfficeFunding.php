<?php

namespace App\Filament\Pages\Finance;

use App\Enums\OfficeFinancePermission;
use App\Models\OfficeFinanceAccount;
use App\Models\OfficeFinanceTransaction;
use App\Services\Finance\OfficeFinanceService;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class PakistanOfficeFunding extends BaseOfficeFinancePage
{
    protected string $view = 'filament.pages.finance.pakistan-office-funding';

    protected static ?string $slug = 'finance/pakistan-office/funding';

    protected static ?string $navigationLabel = 'Funding Received';

    protected static ?string $navigationParentItem = 'Pakistan Office Finance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 13;

    protected static OfficeFinancePermission $requiredPermission = OfficeFinancePermission::ViewFunding;

    public string $fundingDate = '';

    public string $source = '';

    public string $aedAmount = '';

    public string $exchangeRate = '';

    public string $actualPkr = '';

    public string $charges = '';

    public string $accountId = '';

    public string $transferReference = '';

    public string $note = '';

    public string $idempotencyKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->resetForm();
    }

    public function postFunding(): void
    {
        try {
            app(OfficeFinanceService::class)->postFunding([
                'transaction_date' => $this->fundingDate, 'description' => 'Funding received from '.trim($this->source),
                'amount_pkr' => $this->actualPkr, 'office_finance_account_id' => $this->accountId,
                'external_reference' => $this->transferReference ?: null, 'note' => $this->note ?: null,
                'funding_source' => $this->source, 'aed_amount' => $this->aedAmount,
                'exchange_rate_pkr_per_aed' => $this->exchangeRate, 'fx_bank_charges_pkr' => $this->charges ?: null,
                'idempotency_key' => $this->idempotencyKey,
            ], $this->user());
            Notification::make()->success()->title('Funding received posted')->send();
            $this->resetForm();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str($field)->camel()->toString(), $messages[0]);
            }
        }
    }

    public function getCalculatedPkrProperty(): string
    {
        return is_numeric($this->aedAmount) && is_numeric($this->exchangeRate) ? bcadd(bcmul($this->aedAmount, $this->exchangeRate, 6), '0', 2) : '0.00';
    }

    public function getDifferenceProperty(): string
    {
        return is_numeric($this->actualPkr) ? bcsub(bcadd($this->actualPkr, '0', 2), $this->calculatedPkr, 2) : '0.00';
    }

    public function getViewData(): array
    {
        return [
            'accounts' => OfficeFinanceAccount::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'funding' => OfficeFinanceTransaction::query()->with(['account:id,name', 'createdBy:id,name'])->where('transaction_type', 'funding_received')->latest('transaction_date')->latest('id')->paginate(25),
            'canCreate' => $this->allows(OfficeFinancePermission::Create),
        ];
    }

    private function resetForm(): void
    {
        $this->reset('source', 'aedAmount', 'exchangeRate', 'actualPkr', 'charges', 'accountId', 'transferReference', 'note');
        $this->fundingDate = today()->toDateString();
        $this->idempotencyKey = (string) str()->uuid();
        $this->resetValidation();
    }
}
