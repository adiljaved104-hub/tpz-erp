<?php

namespace App\Services\Accounting;

use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountingPermission;
use App\Enums\EmployeeRole;
use App\Models\AccountingAccount;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingLedgerService
{
    public function __construct(
        private readonly AccountingAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string,mixed> $data */
    public function createDraft(array $data, User $actor): AccountingJournal
    {
        $this->authorization->authorize($actor, AccountingPermission::CreateJournal);
        $validated = $this->validateJournalInput($data);

        return DB::transaction(function () use ($validated, $actor): AccountingJournal {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($validated['book_id']);
            $existing = AccountingJournal::query()->where('accounting_book_id', $book->id)->where('idempotency_key', $validated['idempotency_key'])->with('lines')->first();
            if ($existing !== null) {
                if ($this->sameDraftPayload($existing, $validated) === false) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used with different journal content.']);
                }

                return $existing;
            }
            $period = $this->periodForDate($book, $validated['posting_date']);

            $journal = AccountingJournal::query()->create([
                'accounting_book_id' => $book->id,
                'accounting_period_id' => $period->id,
                'reference' => 'JRN-'.strtoupper(Str::random(18)),
                'posting_date' => $validated['posting_date'],
                'status' => AccountingJournalStatus::Draft,
                'narration' => $validated['narration'],
                'idempotency_key' => $validated['idempotency_key'],
                'source_type' => $validated['source_type'] ?? null,
                'source_key' => $validated['source_key'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            $this->replaceDraftLines($journal, $validated['lines'], $book);
            $this->activity->log('accounting.journal_draft_created', $actor, $journal, ['reference' => $journal->reference]);

            return $journal->load('lines');
        });
    }

    public function post(AccountingJournal $journal, User $actor): AccountingJournal
    {
        $this->authorization->authorize($actor, AccountingPermission::PostJournal);

        return DB::transaction(function () use ($journal, $actor): AccountingJournal {
            $journal = AccountingJournal::query()->with('lines')->lockForUpdate()->findOrFail($journal->id);
            if ($journal->status === AccountingJournalStatus::Posted) {
                return $journal;
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($journal->accounting_book_id);
            if ($book->active === false || $book->posting_enabled === false) {
                throw ValidationException::withMessages(['book' => 'This accounting book is inactive or posting is not enabled.']);
            }
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($journal->accounting_period_id);
            if ($period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['posting_date' => 'The accounting period is closed or locked.']);
            }
            $this->validateBalancedLines($journal->lines, $book);
            $journal->forceFill(['status' => AccountingJournalStatus::Posted, 'posted_at' => now('UTC'), 'posted_by_user_id' => $actor->id])->saveQuietly();
            $this->activity->log('accounting.journal_posted', $actor, $journal, ['reference' => $journal->reference]);

            return $journal->refresh()->load('lines.account');
        });
    }

    public function reverse(AccountingJournal $journal, string $reason, User $actor, string|null $targetPostingDate = null): AccountingJournal
    {
        $this->authorization->authorize($actor, AccountingPermission::ReverseJournal);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Enter a reversal reason of at least 5 characters.']);
        }

        return DB::transaction(function () use ($journal, $reason, $actor, $targetPostingDate): AccountingJournal {
            $journal = AccountingJournal::query()->with('lines')->lockForUpdate()->findOrFail($journal->id);
            if ($journal->status !== AccountingJournalStatus::Posted) {
                throw ValidationException::withMessages(['journal' => 'Only a posted journal can be reversed.']);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($journal->accounting_book_id);
            if (! $book->active || ! $book->posting_enabled) {
                throw ValidationException::withMessages(['book' => 'This accounting book is inactive or posting is not enabled.']);
            }
            $targetDate = $targetPostingDate ?? now('UTC')->toDateString();
            $period = $this->periodForDate($book, $targetDate);
            if ($period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['posting_date' => 'The reversal target period is closed or locked.']);
            }
            $existing = AccountingJournal::query()->where('reversal_of_journal_id', $journal->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing->load('lines');
            }

            $reversal = AccountingJournal::query()->create([
                'accounting_book_id' => $book->id,
                'accounting_period_id' => $period->id,
                'reference' => 'REV-'.$journal->reference,
                'posting_date' => $targetDate,
                'status' => AccountingJournalStatus::Draft,
                'narration' => 'Reversal of '.$journal->reference.': '.$reason,
                'idempotency_key' => (string) Str::uuid(),
                'reversal_of_journal_id' => $journal->id,
                'created_by_user_id' => $actor->id,
            ]);
            $reversal->lines()->createMany($journal->lines->map(fn ($line): array => [
                'account_id' => $line->account_id,
                'line_number' => $line->line_number,
                'description' => 'Reversal of '.$journal->reference,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all());
            $this->validateBalancedLines($reversal->lines()->get(), $book);
            $reversal->forceFill(['status' => AccountingJournalStatus::Posted, 'posted_at' => now('UTC'), 'posted_by_user_id' => $actor->id])->saveQuietly();
            $this->activity->log('accounting.journal_reversed', $actor, $reversal, ['reference' => $journal->reference, 'reason' => $reason, 'target_period' => $period->name]);

            return $reversal->load('lines');
        });
    }

    public function enablePosting(AccountingBook $book, User $actor): AccountingBook
    {
        $this->authorization->authorize($actor, AccountingPermission::ManagePeriods);
        if ($actor->employee?->role !== EmployeeRole::Owner || config('accounting.posting_activation_approved', false) !== true) {
            throw ValidationException::withMessages(['posting' => 'Posting activation requires explicit Owner approval and remains disabled until the activation gate is enabled.']);
        }

        return DB::transaction(function () use ($book, $actor): AccountingBook {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($book->id);
            if ($book->active === false) {
                throw ValidationException::withMessages(['book' => 'An inactive accounting book cannot be enabled for posting.']);
            }
            $book->forceFill(['posting_enabled' => true])->save();
            $this->activity->log('accounting.book_posting_enabled', $actor, $book, ['book_code' => $book->code]);

            return $book->refresh();
        });
    }

    public function closePeriod(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        $this->authorization->authorize($actor, AccountingPermission::ManagePeriods);

        return DB::transaction(function () use ($period, $actor): AccountingPeriod {
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['period' => 'Only an open accounting period can be closed.']);
            }
            $period->forceFill(['status' => AccountingPeriodStatus::Closed, 'closed_at' => now('UTC'), 'closed_by_user_id' => $actor->id])->save();
            $this->activity->log('accounting.period_closed', $actor, $period, ['period' => $period->name]);

            return $period->refresh();
        });
    }

    /** @return Collection<int,array<string,mixed>> */
    public function trialBalance(AccountingBook $book, string $asOfDate): Collection
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->authorization->authorize($user, AccountingPermission::ViewTrialBalance);
        $rows = AccountingAccount::query()->where('accounting_book_id', $book->id)->orderBy('number')->get();
        $totals = DB::table('accounting_journal_lines as line')
            ->join('accounting_journals as journal', 'journal.id', '=', 'line.journal_id')
            ->where('journal.accounting_book_id', $book->id)
            ->where('journal.status', AccountingJournalStatus::Posted->value)
            ->whereDate('journal.posting_date', '<=', $asOfDate)
            ->groupBy('line.account_id')
            ->select('line.account_id', DB::raw('SUM(line.debit) as debit'), DB::raw('SUM(line.credit) as credit'))
            ->get()->keyBy('account_id');

        return $rows->map(fn (AccountingAccount $account): array => $this->trialBalanceRow(
            $account,
            (string) ($totals[$account->id]->debit ?? '0.00'),
            (string) ($totals[$account->id]->credit ?? '0.00'),
        ));
    }

    /** @return EloquentCollection<int,AccountingJournalLine> */
    public function generalLedger(AccountingBook $book, string $from, string $to, ?int $accountId = null): EloquentCollection
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $this->authorization->authorize($user, AccountingPermission::View);

        $lines = \App\Models\AccountingJournalLine::query()->with(['journal', 'account'])
            ->whereHas('journal', fn ($query) => $query->where('accounting_book_id', $book->id)->where('status', AccountingJournalStatus::Posted)->whereBetween('posting_date', [$from, $to]))
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->orderBy('journal_id')->orderBy('line_number')->get();

        $opening = [];
        if ($lines->isNotEmpty()) {
            $opening = DB::table('accounting_journal_lines as line')
                ->join('accounting_journals as journal', 'journal.id', '=', 'line.journal_id')
                ->where('journal.accounting_book_id', $book->id)
                ->where('journal.status', AccountingJournalStatus::Posted->value)
                ->whereDate('journal.posting_date', '<', $from)
                ->when($accountId, fn ($query) => $query->where('line.account_id', $accountId))
                ->groupBy('line.account_id')
                ->select('line.account_id', DB::raw('SUM(line.debit - line.credit) as balance'))
                ->pluck('balance', 'account_id')
                ->all();
        }
        $running = [];
        return $lines->map(function ($line) use (&$opening, &$running): mixed {
            $accountId = $line->account_id;
            if (array_key_exists($accountId, $opening) === false) {
                $opening[$accountId] = '0.00';
            }
            $running[$accountId] = bcadd((string) ($running[$accountId] ?? $opening[$accountId]), bcsub((string) $line->debit, (string) $line->credit, 2), 2);
            $line->setAttribute('opening_balance', (string) $opening[$accountId]);
            $line->setAttribute('running_balance', (string) $running[$accountId]);
            return $line;
        });
    }

    /** @return array<string,mixed> */
    private function validateJournalInput(array $data): array
    {
        $validated = Validator::make($data, [
            'book_id' => ['required', 'integer', 'exists:accounting_books,id'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'narration' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_key' => ['nullable', 'string', 'max:190'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'lines.*.debit' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'lines.*.description' => ['nullable', 'string', 'max:250'],
        ])->validate();

        return $validated;
    }

    private function periodForDate(AccountingBook $book, string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('accounting_book_id', $book->id)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if ($period === null) {
            throw ValidationException::withMessages(['posting_date' => 'The posting date is outside the selected accounting book periods.']);
        }
        if ($period->status !== AccountingPeriodStatus::Open) {
            throw ValidationException::withMessages(['posting_date' => 'The accounting period is closed or locked.']);
        }

        return $period;
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function replaceDraftLines(AccountingJournal $journal, array $lines, AccountingBook $book): void
    {
        if ($journal->status !== AccountingJournalStatus::Draft) {
            throw ValidationException::withMessages(['journal' => 'Only draft journals can be edited.']);
        }
        $accounts = AccountingAccount::query()->where('accounting_book_id', $book->id)->whereIn('id', collect($lines)->pluck('account_id'))->get()->keyBy('id');
        foreach ($lines as $index => $line) {
            $account = $accounts[(int) $line['account_id']] ?? null;
            if ($account === null || $account->active === false || $account->is_posting === false || $account->is_control) {
                throw ValidationException::withMessages(['lines' => 'Every journal line must use an active posting account in the selected book.']);
            }
            $debit = $this->decimal($line['debit'] ?? '0');
            $credit = $this->decimal($line['credit'] ?? '0');
            if (($debit === '0.00' && $credit === '0.00') || ($debit !== '0.00' && $credit !== '0.00')) {
                throw ValidationException::withMessages(['lines' => 'Each line must contain either a positive debit or a positive credit.']);
            }
            $journal->lines()->create(['account_id' => $account->id, 'line_number' => $index + 1, 'description' => $line['description'] ?? null, 'debit' => $debit, 'credit' => $credit]);
        }
        $this->validateBalancedLines($journal->lines()->get(), $book);
    }

    private function validateBalancedLines(EloquentCollection $lines, AccountingBook $book): void
    {
        if ($lines->count() < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal requires at least two lines.']);
        }
        $debit = '0.00';
        $credit = '0.00';
        foreach ($lines as $line) {
            $account = AccountingAccount::query()->where('accounting_book_id', $book->id)->find($line->account_id);
            if (! $account || ! $account->active || ! $account->is_posting || $account->is_control) {
                throw ValidationException::withMessages(['lines' => 'Journal lines must use active posting accounts in the selected book.']);
            }
            $debit = bcadd($debit, (string) $line->debit, 2);
            $credit = bcadd($credit, (string) $line->credit, 2);
        }
        if (bccomp($debit, $credit, 2) !== 0 || bccomp($debit, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['lines' => 'Journal debits and credits must balance to a positive amount.']);
        }
    }

    private function decimal(string|int|float|null $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value) !== 1) {
            throw ValidationException::withMessages(['lines' => 'Amounts must be non-negative decimal values with at most two places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 16) {
            throw ValidationException::withMessages(['lines' => 'Amount exceeds the DECIMAL(18,2) accounting limit.']);
        }

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function sameDraftPayload(AccountingJournal $journal, array $validated): bool
    {
        if (($journal->posting_date === null ? null : $journal->posting_date->toDateString()) !== $validated['posting_date']
            || $journal->narration !== $validated['narration']
            || $journal->source_type !== ($validated['source_type'] ?? null)
            || $journal->source_key !== ($validated['source_key'] ?? null)) {
            return false;
        }

        $existing = $journal->lines->sortBy('line_number')->values();
        $incoming = collect($validated['lines'])->values();
        if ($existing->count() !== $incoming->count()) {
            return false;
        }

        return $existing->values()->zip($incoming)->every(function ($pair): bool {
            [$line, $input] = $pair;
            return (int) $line->account_id === (int) $input['account_id']
                && bccomp((string) $line->debit, $this->decimal($input['debit'] ?? '0'), 2) === 0
                && bccomp((string) $line->credit, $this->decimal($input['credit'] ?? '0'), 2) === 0
                && ($line->description ?? null) === ($input['description'] ?? null);
        });
    }

    /** @return array<string,mixed> */
    private function trialBalanceRow(AccountingAccount $account, string $debit, string $credit): array
    {
        $net = bcsub($debit, $credit, 2);

        return [
            'account' => $account,
            'debit' => $debit,
            'credit' => $credit,
            'net_debit' => bccomp($net, '0.00', 2) > 0 ? $net : '0.00',
            'net_credit' => bccomp($net, '0.00', 2) < 0 ? bcmul($net, '-1', 2) : '0.00',
            'balance' => $this->normalBalance($account, $debit, $credit),
        ];
    }

    private function normalBalance(AccountingAccount $account, string $debit, string $credit): string
    {
        return $account->normal_balance === 'debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);
    }
}
