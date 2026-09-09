<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\QuotationSourcingService;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $instructions = app(QuotationSourcingService::class)->formInstructions($this->record, auth()->user());
        $data['items'] = $this->record->items->map(fn ($item) => [
            ...$item->only(['product_id', 'description', 'quantity', 'unit_price_including_vat', 'discount_amount', 'vat_rate']),
            ...($instructions[$item->id] ?? []),
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(QuotationService::class)->updateDraft($record, $data, auth()->user());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Quotation updated';
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('view', ['record' => $this->record]);
    }
}
