<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['expo_token', 'token_hash'];

    protected function casts(): array
    {
        return ['expo_token' => 'encrypted', 'last_seen_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
