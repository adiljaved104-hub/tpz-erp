<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAssistantConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function messages(): HasMany { return $this->hasMany(AiAssistantMessage::class, 'conversation_id'); }
}
