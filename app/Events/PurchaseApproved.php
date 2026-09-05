<?php

namespace App\Events;

use App\Models\Purchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Purchase $purchase) {}
}
