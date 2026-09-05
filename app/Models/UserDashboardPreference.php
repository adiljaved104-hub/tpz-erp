<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'dashboard_key', 'layout', 'hidden_widgets'])]
class UserDashboardPreference extends Model
{
    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'hidden_widgets' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
