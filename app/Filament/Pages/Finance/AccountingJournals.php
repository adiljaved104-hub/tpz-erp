<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Services\Accounting\AccountingLedgerService;
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

    public function saveDraft(): void
    {
        try {
            app(AccountingLedgerService::class)->createDraft(['book_id' => $this->bookId, 'posting_date' => $this->postingDate, 'narration' => $this->narration, 'idempotency_key' => (string) Str::uuid(), 'lines' => $this->lines], $this->user());
            Notification::make()->success()->title('Journal draft saved')->send();
            $this->reset('narration');
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

        return ['book' => $book, 'accounts' => $book?->accounts()->where('active', true)->where('is_posting', true)->where('is_control', false)->orderBy('number')->get() ?? collect(), 'journals' => $book?->journals()->with('lines')->latest('id')->limit(30)->get() ?? collect()];
    }
}
