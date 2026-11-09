<?php

namespace App\Filament\Resources\StockRequests\Tables;

use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestStatus;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Models\StockRequest;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable()->url(fn (StockRequest $record): string => StockRequestResource::getUrl('view', ['record' => $record])),
            TextColumn::make('purpose')->badge()->toggleable(),
            TextColumn::make('order.reference')->label('Order')->placeholder('—')->searchable()->toggleable(),
            TextColumn::make('requester.name')->label('Requested By')->searchable()->toggleable(),
            TextColumn::make('items_count')->label('Products')->numeric()->toggleable(),
            TextColumn::make('status')->badge()->toggleable(),
            TextColumn::make('created_at')->label('Requested At')->dateTime('d M Y, h:i A')->sortable()->toggleable(),
        ])->filters([
            SelectFilter::make('purpose')->options(collect(StockRequestPurpose::cases())->mapWithKeys(fn ($purpose): array => [$purpose->value => $purpose->getLabel()])->all()),
            SelectFilter::make('status')->options(collect(StockRequestStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->getLabel()])->all()),
            SelectFilter::make('requested_by_employee_id')->label('Requested By')->relationship('requester', 'name')->searchable()->preload(),
            SelectFilter::make('assigned_to')->label('Assigned To')->options(fn (): array => User::query()->whereIn('id', StockRequestSourceLine::query()->whereNotNull('decided_by_user_id')->distinct()->pluck('decided_by_user_id'))->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $value): Builder => $query->whereHas('sourceLines.decidedBy', fn (Builder $users): Builder => $users->whereKey((int) $value)))),
            Filter::make('date_range')->schema([DatePicker::make('from')->label('Date From'), DatePicker::make('to')->label('Date To')])->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                    ->when($data['to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
            Filter::make('product')->schema([TextInput::make('search')->label('Product / SKU')])
                ->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['search'] ?? null), fn (Builder $query) => $query->whereHas('items.inventory.product', fn (Builder $product) => $product->where('sku', 'like', '%'.$data['search'].'%')->orWhere('name', 'like', '%'.$data['search'].'%')))),
        ])->recordActions([ViewAction::make()])->persistColumnsInSession()->defaultSort('id', 'desc')->emptyStateHeading('No Stock Requests found.');
    }
}
