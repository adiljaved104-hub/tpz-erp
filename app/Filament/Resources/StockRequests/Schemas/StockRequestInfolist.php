<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;

class StockRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        // The custom view retains the StockRequestService::visibleItems scope
        // and derives source decision actions from eligiblePendingLines.
        return $schema->components([
            ViewEntry::make('stock_request_cards')
                ->hiddenLabel()
                ->view('filament.infolists.stock-request-card-view')
                ->columnSpanFull(),
        ]);
    }
}
