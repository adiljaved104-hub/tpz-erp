<?php

namespace App\Contracts\Reports;

use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Models\User;

interface ReportHandler
{
    /** @param array<string, mixed> $filters */
    public function run(User $user, ReportDefinition $definition, array $filters, int $limit): ReportResult;
}
