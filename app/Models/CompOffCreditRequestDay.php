<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffCreditRequestDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['off_date' => 'immutable_date', 'credits_required' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CompOffCreditRequest::class, 'comp_off_credit_request_id');
    }
}
