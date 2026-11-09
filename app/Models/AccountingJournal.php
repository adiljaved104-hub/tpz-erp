<?php

namespace App\Models;

use App\Enums\AccountingJournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AccountingJournal extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $journal): void {
            if ($journal->getRawOriginal('status') === AccountingJournalStatus::Posted->value) {
                throw new LogicException('Posted accounting journals are immutable; create a reversal instead.');
            }
        });
        static::deleting(function (self $journal): void {
            if ($journal->status === AccountingJournalStatus::Posted) {
                throw new LogicException('Posted accounting journals cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['status' => AccountingJournalStatus::class, 'posting_date' => 'immutable_date', 'posted_at' => 'immutable_datetime'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(AccountingBook::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'journal_id')->orderBy('line_number');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
