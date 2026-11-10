<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingAccountType;
use App\Enums\AccountingPermission;
use App\Models\AccountingAccount;
use App\Models\AccountingBook;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;
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
            $validated = $this->validate([
                'bookId' => ['required', 'integer', 'exists:accounting_books,id'],
                'number' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9][A-Za-z0-9.-]*$/', Rule::unique('accounting_accounts', 'number')->where(fn ($query) => $query->where('accounting_book_id', (int) $this->bookId))],
                'name' => ['required', 'string', 'min:2', 'max:160'],
                'type' => ['required', Rule::in(array_column(AccountingAccountType::cases(), 'value'))],
                'posting' => ['boolean'],
            ]);
            $book = AccountingBook::query()->findOrFail((int) $validated['bookId']);
            $account = AccountingAccount::query()->create([
                'accounting_book_id' => $book->id,
                'number' => trim($validated['number']),
                'name' => trim($validated['name']),
                'account_type' => $validated['type'],
                'normal_balance' => in_array($validated['type'], ['asset', 'cogs', 'expense'], true) ? 'debit' : 'credit',
                'is_posting' => (bool) $validated['posting'],
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
        $books = AccountingBook::query()->orderBy('name')->get();
        $book = AccountingBook::query()->find((int) $this->bookId) ?? $books->first();
        if ($book && $this->bookId === '') {
            $this->bookId = (string) $book->id;
        }

        return ['book' => $book, 'books' => $books, 'accounts' => $book ? $book->accounts()->with('parent')->orderBy('number')->get() : collect(), 'types' => collect(AccountingAccountType::cases())->mapWithKeys(fn ($type) => [$type->value => ucfirst($type->value)])];
    }
}
