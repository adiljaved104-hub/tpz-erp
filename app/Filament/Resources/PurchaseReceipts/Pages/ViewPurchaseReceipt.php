<?php

namespace App\Filament\Resources\PurchaseReceipts\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\PurchaseReceiptCorrection;
use App\Models\PurchaseReceiptItem;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseReceiptCorrectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ViewPurchaseReceipt extends ViewRecord
{
    protected static string $resource = PurchaseReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('correctReceivedQuantity')
                ->label('Correct Received Quantity')
                ->visible(fn (): bool => app(PurchaseAuthorization::class)->allows(auth()->user(), PurchasePermission::ViewReceipts, $this->record->purchase)
                    && app(PurchaseAuthorization::class)->allows(auth()->user(), PurchasePermission::CorrectReceipt, $this->record->purchase))
                ->modalHeading('Correct Posted GRN Quantity')
                ->modalDescription('This records an immutable downward correction. Use normal Purchase Receiving for additional genuine stock.')
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('receipt_item_id')
                        ->label('Product')
                        ->options(fn (): array => $this->record->items()->with('product:id,name,sku')->orderBy('id')->get()
                            ->filter(fn (PurchaseReceiptItem $item): bool => $this->effectiveQuantity($item) > 0)
                            ->mapWithKeys(fn (PurchaseReceiptItem $item): array => [$item->id => "{$item->product->sku} — {$item->product->name}"])
                            ->all())
                        ->required()
                        ->searchable()
                        ->live(),
                    Placeholder::make('receipt_summary')
                        ->label('Receipt quantities')
                        ->content(function (Get $get): HtmlString {
                            $item = PurchaseReceiptItem::query()->find($get('receipt_item_id'));
                            if (! $item || $item->purchase_receipt_id !== $this->record->id) {
                                return new HtmlString('Select a Product to review its quantities.');
                            }
                            $reduced = $item->accepted_quantity - $this->effectiveQuantity($item);

                            return new HtmlString(
                                'Original received: <strong>'.$item->accepted_quantity.'</strong><br>'.
                                'Prior corrections: <strong>-'.$reduced.'</strong><br>'.
                                'Current effective: <strong>'.$this->effectiveQuantity($item).'</strong>',
                            );
                        }),
                    TextInput::make('corrected_quantity')
                        ->label('Correct quantity')
                        ->numeric()->integer()->minValue(0)->required(),
                    Textarea::make('reason')
                        ->label('Correction reason')
                        ->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $item = $this->record->items()->findOrFail($data['receipt_item_id']);
                    app(PurchaseReceiptCorrectionService::class)->correct(
                        $item,
                        (int) $data['corrected_quantity'],
                        $data['reason'],
                        $data['idempotency_key'],
                        auth()->user(),
                    );
                    $this->record->refresh();
                    Notification::make()->success()->title('GRN correction recorded')->send();
                }),
        ];
    }

    private function effectiveQuantity(PurchaseReceiptItem $item): int
    {
        $reduced = (int) PurchaseReceiptCorrection::query()
            ->where('purchase_receipt_item_id', $item->id)
            ->selectRaw('COALESCE(SUM(-adjustment_quantity), 0) AS aggregate')
            ->value('aggregate');

        return $item->accepted_quantity - $reduced;
    }
}
