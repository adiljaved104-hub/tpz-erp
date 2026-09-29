<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceOperationIncident extends Model
{
    public const FEATURED_OFFER_LOST = 'featured_offer_lost';

    public const STOCK_EXPOSURE = 'stock_exposure';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'exposed_listing_count' => 'integer', 'usable_quantity' => 'integer',
            'opened_at' => 'immutable_datetime', 'last_evaluated_at' => 'immutable_datetime',
            'regained_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ProductMarketplaceListing::class, 'listing_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    public function responsibility(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class, 'responsibility_assignment_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function responsibleTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'responsible_team_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketplaceOperationIncidentRecipient::class, 'incident_id');
    }
}
