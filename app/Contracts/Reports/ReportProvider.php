<?php

namespace App\Contracts\Reports;

use App\DTOs\Reports\ReportDefinition;

interface ReportProvider
{
    /** @return iterable<int, ReportDefinition> */
    public function definitions(): iterable;
}
