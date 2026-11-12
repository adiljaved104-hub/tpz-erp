<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AiAssistantAudit extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'event', 'tool', 'metadata', 'ip_address'];
    protected function casts(): array { return ['metadata' => 'array']; }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('AI Assistant audit records are append-only.'));
        static::deleting(fn (): never => throw new LogicException('AI Assistant audit records are append-only.'));
    }
}
