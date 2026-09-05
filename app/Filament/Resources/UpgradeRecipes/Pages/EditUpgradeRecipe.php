<?php

namespace App\Filament\Resources\UpgradeRecipes\Pages;

use App\Filament\Resources\UpgradeRecipes\UpgradeRecipeResource;
use App\Services\Upgrades\UpgradeRecipeService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUpgradeRecipe extends EditRecord
{
    protected static string $resource = UpgradeRecipeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $fields = ['id', 'sequence', 'operation', 'source_slot_key', 'target_slot_key', 'install_component_id', 'recovered_component_id', 'quantity_per_laptop', 'recovery_valuation_method'];
        if (UpgradeRecipeResource::recoveryAllowed()) {
            array_push($fields, 'recovery_value_override', 'override_reason');
        }

        return [...$data, 'lines' => $this->record->lines()->select($fields)->get()->toArray()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(UpgradeRecipeService::class)->update($record, $data, auth()->user());
    }
}
