<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Services\Accounting\AccountingLedgerService;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingJournals extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-journals';

    protected static ?string $slug = 'finance/accounting/journals';

    protected static ?string $navigationLabel = 'General Journal';

    protected static ?int $navigationSort = 20;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewJournals;

    public string $bookId = '';

    public string $postingDate = '';

    public string $narration = '';

    public array $lines = [];

    public function mount(): void
    {
        parent::mount();
        $this->postingDate = now()->toDateString();
        $this->lines = [['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''], ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => '']];
        $this->bookId = (string) (AccountingBook::query()->value('id') ?? '');
    }

    public function addLine(): void
    {
        $this->lines[] = ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2 || ! array_key_exists($index, $this->lines)) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveDraft(): void
    {
        try {
            $lines = array_map(static function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }
                foreach (['debit', 'credit'] as $side) {
                    $amount = $line[$side] ?? '';
                    if (is_scalar($amount) && trim((string) $amount) === '') {
                        $line[$side] = '0';
                    }
                }

                return $line;
            }, $this->lines);

            app(AccountingLedgerService::class)->createDraft(['book_id' => $this->bookId, 'posting_date' => $this->postingDate, 'narration' => $this->narration, 'idempotency_key' => (string) Str::uuid(), 'lines' => $lines], $this->user());
            Notification::make()->success()->title('Journal draft saved')->send();
            $this->reset('narration');
            $this->lines = [['account_id' => '', 'debit' => '', 'credit' => '', 'description' => ''], ['account_id' => '', 'debit' => '', 'credit' => '', 'description' => '']];
            $this->resetValidation();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function postJournal(int $id): void
    {
        app(AccountingLedgerService::class)->post(AccountingJournal::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Journal posted')->send();
    }

    protected function getViewData(): array
    {
        $book = AccountingBook::query()->find((int) $this->bookId) ?? AccountingBook::query()->first();
        $authorization = app(AccountingAuthorization::class);
        $user = $this->user();
        [$debitTotal, $creditTotal, $invalidAmount] = $this->lineTotals();

        return [
            'book' => $book,
            'books' => AccountingBook::query()->orderBy('name')->get(),
            'accounts' => $book?->accounts()->where('active', true)->where('is_posting', true)->where('is_control', false)->orderBy('number')->get() ?? collect(),
            'journals' => $book?->journals()->with(['lines', 'reversal', 'reversalOf', 'period'])->latest('id')->limit(30)->get() ?? collect(),
            'canCreateJournal' => $authorization->allows($user, AccountingPermission::CreateJournal),
            'canPostJournal' => $authorization->allows($user, AccountingPermission::PostJournal) && config('accounting.posting_activation_approved', false) === true && $book?->posting_enabled === true,
            'debitTotal' => $debitTotal,
            'creditTotal' => $creditTotal,
            'invalidAmount' => $invalidAmount,
            'isBalancedPreview' => ! $invalidAmount && bccomp($debitTotal, $creditTotal, 2) === 0 && bccomp($debitTotal, '0.00', 2) > 0,
        ];
    }

    /** @return array{string,string,bool} */
    private function lineTotals(): array
    {
        $debitTotal = '0.00';
        $creditTotal = '0.00';
        $invalidAmount = false;

        foreach ($this->lines as $line) {
            if (! is_array($line)) {
                $invalidAmount = true;

                continue;
            }

            foreach (['debit', 'credit'] as $side) {
                $rawAmount = $line[$side] ?? '';
                if (! is_scalar($rawAmount)) {
                    $invalidAmount = true;

                    continue;
                }
                $amount = trim((string) $rawAmount);
                if ($amount === '') {
                    continue;
                }
                if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount) !== 1) {
                    $invalidAmount = true;

                    continue;
                }

                if ($side === 'debit') {
                    $debitTotal = bcadd($debitTotal, $amount, 2);
                } else {
                    $creditTotal = bcadd($creditTotal, $amount, 2);
                }
            }
        }

        return [$debitTotal, $creditTotal, $invalidAmount];
    }
}
