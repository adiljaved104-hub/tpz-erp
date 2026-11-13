<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use App\Models\StockRequestSourceLine;
use App\Services\UserTimezoneResolver;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stock Request')->columns(2)->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('purpose'),
                TextEntry::make('order.reference')->label('Order')->placeholder('—'),
                TextEntry::make('requester.name')->label('Requested By'),
                TextEntry::make('created_at')->label('Requested At')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state, 'd M Y, h:i A')),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
            Section::make('Requested Products')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(['default' => 1, 'md' => 12])->schema([
                    TextEntry::make('sku')->label('SKU')->columnSpanFull(),
                    TextEntry::make('product_name')->label('Product')->columnSpan(['default' => 1, 'md' => 8]),
                    TextEntry::make('warehouse_name')->label('Warehouse')->columnSpan(['default' => 1, 'md' => 2]),
                    TextEntry::make('quantity')->label('Requested Qty')->columnSpan(['default' => 1, 'md' => 2]),
                    TextEntry::make('approved_quantity')->label('Approved Qty')->state(fn ($record): int => (int) $record->sourceLines->sum('approved_quantity')),
                    TextEntry::make('transferred_quantity')->label('Transferred Qty')->state(fn ($record): int => (int) $record->sourceLines->sum('transferred_quantity')),
                    TextEntry::make('remaining_quantity')->label('Remaining Qty')->state(fn ($record): int => max(0, (int) $record->quantity - (int) $record->sourceLines->sum('transferred_quantity'))),
                    RepeatableEntry::make('sourceLines')->label('Available Sources / Approval Status')->schema([
                        TextEntry::make('source_label')->label('Source'),
                        TextEntry::make('proposed_quantity')->label('Proposed Qty'),
                        TextEntry::make('approved_quantity')->label('Approved Qty'),
                        TextEntry::make('transferred_quantity')->label('Transferred Qty'),
                        TextEntry::make('remaining_quantity')->label('Remaining Qty')->state(fn ($record): int => max(0, (int) $record->approved_quantity - (int) $record->transferred_quantity)),
                        TextEntry::make('approval_route')->label('Approval Route')
                            ->state(fn (StockRequestSourceLine $record): string => $record->requiresOwnerAdminApproval() ? 'Owner/Admin' : 'Allocation Holder'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('decidedBy.employee.name')->label('Decided By')->placeholder('—'),
                        TextEntry::make('decided_at')->label('Decided At')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state, 'd M Y, h:i A'))->placeholder('—'),
                        TextEntry::make('decision_note')->label('Note / Reason')->placeholder('—'),
                    ])->table([
                        TableColumn::make('Source'),
                        TableColumn::make('Proposed Qty'),
                        TableColumn::make('Approval Route'),
                        TableColumn::make('Status'),
                        TableColumn::make('Decided By'),
                        TableColumn::make('Decided At'),
                        TableColumn::make('Note / Reason'),
                    ])->columnSpanFull(),
                ])->columnSpanFull(),
            ]),
            Section::make('Execution')->columns(2)->schema([
                TextEntry::make('execution.execution_type')->label('Execution Type')->placeholder('Not executed')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'order_reservation' => 'Order Reservation',
                    'permanent_transfer' => 'Permanent Transfer',
                    default => $state ?? 'Not executed',
                }),
                TextEntry::make('execution.executed_at')->label('Executed At')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state, 'd M Y, h:i A'))->placeholder('—'),
                TextEntry::make('execution.executedBy.employee.name')->label('Executed By')->placeholder('—'),
                TextEntry::make('execution.order.reference')->label('Reserved Order')->placeholder('—'),
                RepeatableEntry::make('execution.lines')->label('Transferred From → Transferred To')->schema([
                    TextEntry::make('item.sku')->label('SKU'),
                    TextEntry::make('item.product_name')->label('Product'),
                    TextEntry::make('quantity')->label('Quantity'),
                    TextEntry::make('sourceAccount.name')->label('Transferred From')->placeholder('System / Unassigned'),
                    TextEntry::make('destinationAccount.name')->label('Transferred To')->placeholder('—'),
                ])->table([
                    TableColumn::make('SKU'),
                    TableColumn::make('Product'),
                    TableColumn::make('Quantity'),
                    TableColumn::make('Transferred From'),
                    TableColumn::make('Transferred To'),
                ])->columnSpanFull(),
            ])->visible(fn ($record): bool => $record->execution()->exists()),
        ]);
    }
}
