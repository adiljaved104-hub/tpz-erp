<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Qc\RenewedQcDispatchService;
use App\Services\Search\SearchQuery;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('reference')->label('Order')->searchable(query: fn (Builder $query, string $search): Builder => self::applySearch($query, $search))->sortable()
                ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            TextColumn::make('platform.name')->label('Platform')->placeholder('Manual'),
            TextColumn::make('warehouse.name')->label('Fulfilled From')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('external_order_number')->label('External Order')->placeholder('—')->searchable(),
            TextColumn::make('items.product.sku')->label('Product SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('items.product.name')->label('Product')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('items.product.model')->label('Model')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('order_date')->date('d M Y')->sortable(),
            TextColumn::make('handledBy.name')->label('Handled By')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('qc_readiness')->label('Renewed QC')->state(fn (Order $record) => str(app(RenewedQcDispatchService::class)->readiness($record)['status'])->headline())->badge()->toggleable(),
        ];

        if (self::allowed(OrderPermission::ViewSellingPrice)) {
            $columns[] = TextColumn::make('grand_total')->label('Total')->money('AED', decimalPlaces: 2);
        }

        return $table->columns($columns)
            ->filters([
                SelectFilter::make('status')->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->getLabel()])->all()),
                Filter::make('order_date_range')->label('Order Date Range')->schema([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('through')->label('Through'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->whereDate('orders.order_date', '>=', $data['from']))
                    ->when(filled($data['through'] ?? null), fn (Builder $query): Builder => $query->whereDate('orders.order_date', '<=', $data['through']))),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No Orders found for the selected filters.');
    }

    private static function allowed(OrderPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, $permission);
    }

    private static function applySearch(Builder $query, string $search): Builder
    {
        foreach (SearchQuery::tokens($search) as $token) {
            $query->where(fn (Builder $match): Builder => $match
                ->where(fn (Builder $order): Builder => SearchQuery::matchModel($order, ['orders.reference', 'orders.external_order_number'], $token))
                ->orWhereHas('items.product', fn (Builder $product): Builder => SearchQuery::matchModel($product, ['products.sku', 'products.name', 'products.model'], $token))
                ->orWhereHas('handledBy', fn (Builder $employee): Builder => SearchQuery::matchModel($employee, ['employees.name', 'employees.employee_id'], $token)));
        }

        return $query;
    }
}
