<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportsExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, ReportExportService $exports): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $exports->export($user, $report, $format, $request->only([
            'from', 'to', 'status', 'employee_id', 'team_id', 'platform_id',
            'product_id', 'brand_id', 'warehouse_id', 'supplier_id',
            'category', 'cost_center', 'channel',
            'office_account_id',
        ]));
    }
}
