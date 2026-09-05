<?php

namespace App\Models;

use App\Enums\AuthenticationOtpPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthenticationOtpChallenge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['code_hash', 'requested_ip_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => AuthenticationOtpPurpose::class,
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
