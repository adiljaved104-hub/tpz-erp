<?php

namespace App\Filament\Resources\WebSalesOrders\Tables;

use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WebSalesOrdersTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('reference')->label('Order Ref')->searchable()->sortable()
                ->url(fn (Order $record): string => WebSalesOrderResource::getUrl('view', ['record' => $record])),
            TextColumn::make('order_date')->label('Date')->date('d M Y')->sortable(),
            TextColumn::make('customer_name')->label('Customer')->searchable()->limit(32)->tooltip(fn (Order $record): string => $record->customer_name),
            TextColumn::make('customer_phone')->label('WhatsApp / Phone')->searchable(),
            TextColumn::make('web_sales_channel')->label('Channel')->badge(),
            TextColumn::make('product_summary')->label('Products')->state(fn (Order $record): string => $record->items->map(fn ($item): string => $item->sku.' × '.$item->ordered_quantity)->join(', '))
                ->limit(42)->tooltip(fn (Order $record): string => $record->items->map(fn ($item): string => $item->sku.' · '.$item->product_name.' × '.$item->ordered_quantity)->join("\n"))
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('items', fn (Builder $items): Builder => $items->where('sku', 'like', "%{$search}%")->orWhere('product_name', 'like', "%{$search}%"))),
            TextColumn::make('units')->label('Units')->numeric()->sortable(),
            TextColumn::make('web_status')->label('Status')->state(fn (Order $record): string => $record->webSalesStatusLabel())->badge(),
            TextColumn::make('delivery_type')->label('Delivery')->badge(),
            TextColumn::make('courier_name')->label('Courier')->placeholder('—')->toggleable(),
            TextColumn::make('tracking_number')->label('Tracking / AWB')->placeholder('—')->searchable()->toggleable(),
            TextColumn::make('handledBy.name')->label('Employee')->toggleable(),
        ];

        if (self::allowed(WebSalesPermission::ViewRevenue)) {
            $columns[] = TextColumn::make('grand_total')->label('Revenue')->money('AED')->sortable();
        }
        if (self::allowed(WebSalesPermission::ViewGrossProfit)) {
            $columns[] = TextColumn::make('gross_profit')->label('Gross Profit')->money('AED');
        }

        $filters = [
            SelectFilter::make('period')->options(['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'month' => 'This Month'])
                ->query(function (Builder $query, array $data): Builder {
                    $today = now(config('app.timezone'))->startOfDay();

                    return match ($data['value'] ?? null) {
                        'today' => $query->whereDate('order_date', $today),
                        'yesterday' => $query->whereDate('order_date', $today->copy()->subDay()),
                        'week' => $query->whereBetween('order_date', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()]),
                        'month' => $query->whereBetween('order_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()]),
                        default => $query,
                    };
                }),
            Filter::make('date_range')->schema([DatePicker::make('from')->label('Date From'), DatePicker::make('to')->label('Date To')])->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('order_date', '>=', $date))
                    ->when($data['to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('order_date', '<=', $date))),
            SelectFilter::make('status')->options(['new' => 'New', 'confirmed' => 'Confirmed', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'new' => $query->where('status', 'draft'),
                    'confirmed' => $query->whereIn('status', ['pending_review', 'confirmed', 'reserved', 'processing']),
                    'shipped' => $query->where('status', 'fulfilled')->whereNull('delivered_at'),
                    'delivered' => $query->where('status', 'fulfilled')->whereNotNull('delivered_at'),
                    'cancelled' => $query->where('status', 'cancelled'),
                    default => $query,
                }),
            SelectFilter::make('web_sales_channel')->label('Channel')->options(WebSalesChannel::class),
            SelectFilter::make('delivery_type')->label('Delivery')->options(WebSalesDeliveryType::class),
        ];
        if (self::allowed(WebSalesPermission::ViewAll)) {
            $filters[] = SelectFilter::make('handled_by_employee_id')->label('Employee')->options(fn (): array => Employee::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())->searchable();
        }

        return $table->columns($columns)->filters($filters)->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc')->emptyStateHeading('No Web Sales orders found.');
    }

    private static function allowed(WebSalesPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, $permission);
    }
}
