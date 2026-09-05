<?php

namespace App\Filament\Resources\ProductBrands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductBrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(255)]);
    }
}
