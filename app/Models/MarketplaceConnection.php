<?php

namespace App\Models;

use App\Enums\MarketplaceConnectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceConnection extends Model
{
    protected $fillable = ['marketplace_account_id', 'name', 'connection_type', 'driver', 'priority', 'enabled', 'health_status', 'credential_reference', 'configuration', 'last_health_checked_at', 'last_healthy_at'];

    protected $hidden = ['credential_reference'];

    protected function casts(): array
    {
        return ['connection_type' => MarketplaceConnectionType::class, 'enabled' => 'boolean', 'priority' => 'integer', 'configuration' => 'array', 'last_health_checked_at' => 'immutable_datetime', 'last_healthy_at' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(MarketplaceConnectionCapabilityRecord::class)->orderBy('capability');
    }
}
