<?php

namespace App\Models;

use App\Enums\MarketplaceConnectionCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceConnectionCapabilityRecord extends Model
{
    protected $table = 'marketplace_connection_capabilities';

    public $timestamps = false;

    protected $fillable = ['marketplace_connection_id', 'capability', 'enabled'];

    protected function casts(): array
    {
        return ['capability' => MarketplaceConnectionCapability::class, 'enabled' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }
}
