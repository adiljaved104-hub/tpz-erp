<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlertIncidentRecipient extends Model
{
    public const PRIMARY = 'primary';

    public const ESCALATION = 'escalation';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initial_notified_at' => 'immutable_datetime',
            'reminder_step' => 'integer',
            'last_reminded_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StockAlertIncident::class, 'stock_alert_incident_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
