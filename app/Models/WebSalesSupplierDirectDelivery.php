<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebSalesSupplierDirectDelivery extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['evidence' => 'array', 'confirmed_at' => 'immutable_datetime']; }
    public function line(): BelongsTo { return $this->belongsTo(WebSalesManualSourcingLine::class, 'line_id'); }
    public function confirmedBy(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by_user_id'); }
}
