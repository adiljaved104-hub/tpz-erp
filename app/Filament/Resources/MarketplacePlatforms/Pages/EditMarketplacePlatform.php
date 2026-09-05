<?php

namespace App\Filament\Resources\MarketplacePlatforms\Pages;

use App\Actions\Responsibilities\RenameMarketplacePlatform;
use App\Actions\Responsibilities\UpdateMarketplaceReturnConfiguration;
use App\DTOs\Responsibilities\RenameMarketplacePlatformData;
use App\DTOs\Responsibilities\UpdateMarketplaceReturnConfigurationData;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Filament\Resources\MarketplacePlatforms\Actions\ChangeMarketplacePlatformCodeAction;
use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditMarketplacePlatform extends EditRecord
{
    protected static string $resource = MarketplacePlatformResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $mode = $data['return_handling_mode'] ?? null;

        return DB::transaction(function () use ($record, $data, $mode): Model {
            app(UpdateMarketplaceReturnConfiguration::class)->handle($record, new UpdateMarketplaceReturnConfigurationData(
                returnHandlingMode: $mode instanceof MarketplaceReturnHandlingMode ? $mode : (filled($mode) ? MarketplaceReturnHandlingMode::from($mode) : null),
                defaultReturnReceivingWarehouseId: filled($data['default_return_receiving_warehouse_id'] ?? null) ? (int) $data['default_return_receiving_warehouse_id'] : null,
                customerReturnClaimsEnabled: (bool) ($data['customer_return_claims_enabled'] ?? false),
                claimProgramName: $data['claim_program_name'] ?? null,
            ), auth()->user());

            return app(RenameMarketplacePlatform::class)->handle($record->refresh(), new RenameMarketplacePlatformData($data['name']), auth()->user());
        });
    }

    protected function getHeaderActions(): array
    {
        return [ChangeMarketplacePlatformCodeAction::make(), ViewAction::make()];
    }
}
