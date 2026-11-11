<?php

namespace App\Models;

use App\Enums\AccountingJournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AccountingJournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $journal = AccountingJournal::query()->find($line->journal_id);
            if ($journal?->status === AccountingJournalStatus::Posted) {
                throw new LogicException('Posted accounting journals cannot receive additional lines.');
            }
        });
        static::updating(fn (): never => throw new LogicException('Accounting journal lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Accounting journal lines are immutable.'));
    }

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }
}
