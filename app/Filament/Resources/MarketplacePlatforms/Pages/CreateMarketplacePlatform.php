<?php

namespace App\Filament\Resources\MarketplacePlatforms\Pages;

use App\Actions\Responsibilities\CreateMarketplacePlatform as CreateMarketplacePlatformAction;
use App\DTOs\Responsibilities\CreateMarketplacePlatformData;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMarketplacePlatform extends CreateRecord
{
    protected static string $resource = MarketplacePlatformResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $mode = $data['return_handling_mode'] ?? null;

        return app(CreateMarketplacePlatformAction::class)->handle(new CreateMarketplacePlatformData(
            name: $data['name'],
            code: $data['code'],
            returnHandlingMode: $mode instanceof MarketplaceReturnHandlingMode ? $mode : (filled($mode) ? MarketplaceReturnHandlingMode::from($mode) : null),
            defaultReturnReceivingWarehouseId: filled($data['default_return_receiving_warehouse_id'] ?? null) ? (int) $data['default_return_receiving_warehouse_id'] : null,
            customerReturnClaimsEnabled: (bool) ($data['customer_return_claims_enabled'] ?? false),
            claimProgramName: $data['claim_program_name'] ?? null,
        ), auth()->user());
    }
}
