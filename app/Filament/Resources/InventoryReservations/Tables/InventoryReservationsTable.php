<?php

namespace App\Filament\Resources\InventoryReservations\Tables;

use App\Actions\Inventory\ReleaseInventoryReservation;
use App\DTOs\Inventory\ReleaseReservationData;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class InventoryReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('product.sku')->label('SKU')->searchable()->copyable(),
            TextColumn::make('product.name')->label('Product')->searchable()->limit(48)
                ->wrap()->tooltip(fn (InventoryReservation $record): ?string => $record->product?->name),
            TextColumn::make('warehouse.name')->label('Warehouse'),
            TextColumn::make('quantity'),
            TextColumn::make('status')->badge(),
            TextColumn::make('reserved_at')->dateTime('d M Y, h:i A')->sortable(),
            TextColumn::make('released_at')->dateTime('d M Y, h:i A')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->options([
                InventoryReservationStatus::Active->value => 'Active',
                InventoryReservationStatus::Released->value => 'Released',
            ]),
        ])->recordActions([
            ViewAction::make(),
            Action::make('release')
                ->label('Release')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (InventoryReservation $record): bool => $record->status === InventoryReservationStatus::Active && self::allowed($record))
                ->authorize(fn (InventoryReservation $record): bool => self::allowed($record))
                ->action(fn (InventoryReservation $record, array $data) => app(ReleaseInventoryReservation::class)->handle(
                    $record,
                    new ReleaseReservationData($data['reason'], (string) Str::uuid()),
                    auth()->user(),
                )),
        ])->toolbarActions([]);
    }

    private static function allowed(InventoryReservation $reservation): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows(
            $user,
            InventoryPermission::ReleaseReservation,
            $reservation->inventory,
        );
    }
}
