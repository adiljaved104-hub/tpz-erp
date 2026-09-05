<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('reference')->label('Order')->searchable()->sortable()
                ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            TextColumn::make('platform.name')->label('Platform')->placeholder('Manual'),
            TextColumn::make('warehouse.name')->label('Fulfilled From')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('external_order_number')->label('External Order')->placeholder('—')->searchable(),
            TextColumn::make('order_date')->date('d M Y')->sortable(),
            TextColumn::make('handledBy.name')->label('Handled By'),
            TextColumn::make('status')->badge(),
        ];

        if (self::allowed(OrderPermission::ViewSellingPrice)) {
            $columns[] = TextColumn::make('grand_total')->label('Total')->money('AED', decimalPlaces: 2);
        }

        return $table->columns($columns)
            ->filters([SelectFilter::make('status')->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->getLabel()])->all())])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No Orders found for the selected filters.');
    }

    private static function allowed(OrderPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, $permission);
    }
}
