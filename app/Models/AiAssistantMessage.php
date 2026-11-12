<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'content', 'status', 'provider', 'model', 'response_id', 'input_tokens', 'output_tokens', 'error_code'];
    public function conversation(): BelongsTo { return $this->belongsTo(AiAssistantConversation::class, 'conversation_id'); }
}
