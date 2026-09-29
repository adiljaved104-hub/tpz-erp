<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceMonitoringSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monitoring_enabled' => 'boolean', 'monitoring_interval_minutes' => 'integer', 'employee_reminder_minutes' => 'integer', 'acknowledgement_stops_reminders' => 'boolean', 'escalation_threshold_minutes' => 'integer', 'escalation_channels' => 'array', 'summary_times' => 'array', 'summary_channels' => 'array', 'event_channels' => 'array'];
    }
}
