<?php

namespace App\Events;

use App\Models\PurchaseReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PurchaseReceipt $receipt) {}
}
