<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Models\Supplier;
use App\Services\SupplierDuplicateWarningService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->live(onBlur: true),
            TextInput::make('contact_person')->label('Contact Person')->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(50)->live(onBlur: true),
            TextInput::make('email')->email()->maxLength(255)->live(onBlur: true),
            TextInput::make('vat_number')->label('VAT Number')->maxLength(100)->live(onBlur: true),
            Placeholder::make('duplicate_warning')
                ->label('Potential duplicates')
                ->content(function (Get $get, ?Supplier $record): string {
                    $candidates = app(SupplierDuplicateWarningService::class)->candidates(
                        $get->string('name', isNullable: true),
                        $get->string('email', isNullable: true),
                        $get->string('phone', isNullable: true),
                        $get->string('vat_number', isNullable: true),
                        $record,
                    );

                    return $candidates->isEmpty()
                        ? 'No exact field matches detected.'
                        : 'Review existing Suppliers: '.$candidates->pluck('name')->implode(', ').'. Creation is not blocked.';
                }),
            Textarea::make('address')->maxLength(2000)->rows(3)->columnSpanFull(),
            Textarea::make('notes')->maxLength(5000)->rows(4)->columnSpanFull(),
        ]);
    }
}
