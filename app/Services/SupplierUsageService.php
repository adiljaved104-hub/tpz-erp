<?php

namespace App\Services;

use App\DTOs\Usage\UsageCheckResult;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierUsageService
{
    public function check(Supplier $supplier): UsageCheckResult
    {
        $sources = [];

        if (Schema::hasTable('purchases') && DB::table('purchases')->where('supplier_id', $supplier->id)
            ->whereIn('status', ['draft', 'approved', 'partially_received'])->exists()) {
            $sources[] = 'open_purchases';
        }

        return $sources === [] ? UsageCheckResult::unused() : new UsageCheckResult(true, $sources);
    }
}
