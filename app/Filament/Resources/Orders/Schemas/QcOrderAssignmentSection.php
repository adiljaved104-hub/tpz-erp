<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\QcPermission;
use App\Models\Order;
use App\Services\Qc\QcOrderAssignmentService;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;

class QcOrderAssignmentSection
{
    public static function make(): Section
    {
        return Section::make('QC Unit Traceability')
            ->description('Certified device identity only — separate from stock ownership and reservations.')
            ->visible(fn (?Order $record): bool => $record !== null && auth()->user() !== null
                && app(QcOrderAssignmentService::class)->allows(auth()->user(), QcPermission::ViewOrderAssignments, $record))
            ->schema([View::make('qc.order-assignments')])->columnSpanFull();
    }
}
