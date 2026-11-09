<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingAccountType;
use App\Enums\AccountingPermission;
use App\Models\AccountingAccount;
use App\Models\AccountingBook;
use App\Services\Accounting\AccountingLedgerService;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class AccountingChartOfAccounts extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-chart-of-accounts';

    protected static ?string $slug = 'finance/accounting/chart-of-accounts';

    protected static ?string $navigationLabel = 'UAE Chart of Accounts';

    protected static ?int $navigationSort = 10;

    protected static AccountingPermission $requiredPermission = AccountingPermission::View;

    public string $bookId = '';

    public string $number = '';

    public string $name = '';

    public string $type = 'asset';

    public bool $posting = true;

    public function saveAccount(): void
    {
        try {
            app(AccountingAuthorization::class)->authorize($this->user(), AccountingPermission::ManageChart);
            $book = AccountingBook::query()->findOrFail((int) $this->bookId);
            $account = AccountingAccount::query()->create([
                'accounting_book_id' => $book->id,
                'number' => trim($this->number),
                'name' => trim($this->name),
                'account_type' => $this->type,
                'normal_balance' => in_array($this->type, ['asset', 'cogs', 'expense'], true) ? 'debit' : 'credit',
                'is_posting' => $this->posting,
                'active' => true,
                'protected' => false,
            ]);
            app(ActivityLogger::class)->log('accounting.account_created', $this->user(), $account, ['account_number' => $account->number]);
            Notification::make()->success()->title('Account created')->send();
            $this->reset('number', 'name');
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    protected function getViewData(): array
    {
        $book = AccountingBook::query()->first();
        if ($book && $this->bookId === '') {
            $this->bookId = (string) $book->id;
        }

        return ['book' => $book, 'books' => AccountingBook::query()->orderBy('name')->get(), 'accounts' => $book ? $book->accounts()->with('parent')->orderBy('number')->get() : collect(), 'types' => collect(AccountingAccountType::cases())->mapWithKeys(fn ($type) => [$type->value => ucfirst($type->value)])];
    }
}
