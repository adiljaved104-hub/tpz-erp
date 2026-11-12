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

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static ?int $navigationSort = 10;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewChart;

    public string $bookId = '';

    public string $number = '';

    public string $name = '';

    public string $type = 'asset';

    public bool $posting = true;

    public string $parentId = '';

    public string $search = '';

    public string $typeFilter = '';

    public string $statusFilter = 'active';

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
                'parentId' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $parent = AccountingAccount::query()->whereKey((int) $value)
                        ->where('accounting_book_id', (int) $this->bookId)
                        ->where('active', true)
                        ->where('is_posting', false)
                        ->first();

                    if (! $parent || $parent->account_type->value !== $this->type) {
                        $fail('Choose an active, non-posting parent account of the same type in this book.');
                    }
                }],
            ]);
            $book = AccountingBook::query()->findOrFail((int) $validated['bookId']);
            $account = AccountingAccount::query()->create([
                'accounting_book_id' => $book->id,
                'parent_id' => $validated['parentId'] ?: null,
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
            $this->reset('number', 'name', 'parentId');
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

        $accounts = $book ? $book->accounts()->with('parent')->orderBy('number')->get() : collect();
        $hierarchy = collect();
        $visited = [];
        $appendChildren = function (?int $parentId, int $depth) use (&$appendChildren, &$hierarchy, &$visited, $accounts): void {
            foreach ($accounts->where('parent_id', $parentId)->sortBy('number') as $account) {
                if (isset($visited[$account->id])) {
                    continue;
                }
                $visited[$account->id] = true;
                $account->setAttribute('hierarchy_depth', $depth);
                $hierarchy->push($account);
                $appendChildren((int) $account->id, $depth + 1);
            }
        };
        $appendChildren(null, 0);
        $query = mb_strtolower(trim($this->search));
        $filteredAccounts = $hierarchy->filter(function (AccountingAccount $account) use ($query): bool {
            $matchesSearch = $query === '' || str_contains(mb_strtolower($account->number.' '.$account->name.' '.($account->parent?->name ?? '')), $query);
            $matchesType = $this->typeFilter === '' || $account->account_type->value === $this->typeFilter;
            $matchesStatus = $this->statusFilter === 'all' || ($this->statusFilter === 'inactive' ? ! $account->active : $account->active);

            return $matchesSearch && $matchesType && $matchesStatus;
        });

        return [
            'book' => $book,
            'books' => $books,
            'accounts' => $filteredAccounts,
            'types' => collect(AccountingAccountType::cases())->mapWithKeys(fn ($type) => [$type->value => ucfirst($type->value)]),
            'parentAccounts' => $accounts->filter(fn (AccountingAccount $account): bool => $account->active && ! $account->is_posting && $account->account_type->value === $this->type),
            'canManageChart' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ManageChart),
        ];
    }
}
