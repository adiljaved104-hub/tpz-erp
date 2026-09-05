<?php

namespace App\Filament\Resources\Components\Pages;

use App\Filament\Resources\Components\ComponentResource;
use App\Models\Component;
use App\Services\Components\ComponentCatalogService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditComponent extends EditRecord
{
    protected static string $resource = ComponentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Component $component */
        $component = $this->record->loadMissing('product');

        return [...$data, ...$component->product->only(['sku', 'name', 'brand_id', 'category_id', 'model', 'description'])];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ComponentCatalogService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
