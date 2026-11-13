<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantUsage extends Model
{
    protected $table = 'ai_assistant_usage';

    protected $fillable = ['user_id', 'message_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
