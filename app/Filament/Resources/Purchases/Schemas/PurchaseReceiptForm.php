<?php

namespace App\Filament\Resources\Purchases\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PurchaseReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Goods Received Note')->schema([
                DateTimePicker::make('received_at')->default(now())->required(), TextInput::make('supplier_delivery_note')->maxLength(255),
                Textarea::make('notes')->maxLength(2000)->columnSpanFull(), Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                Repeater::make('items')->schema([
                    Hidden::make('purchase_item_id'), TextInput::make('product_label')->disabled()->dehydrated(false),
                    TextInput::make('outstanding_quantity')->disabled()->dehydrated(false),
                    TextInput::make('accepted_quantity')->integer()->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('damaged_quantity')->integer()->numeric()->minValue(0)->default(0)->required(),
                    TextInput::make('rejected_quantity')->integer()->numeric()->minValue(0)->default(0)->required(),
                    Textarea::make('notes')->maxLength(2000),
                ])->addable(false)->deletable(false)->reorderable(false)->columns(3)->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
