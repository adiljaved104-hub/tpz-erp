<?php

namespace App\Filament\Resources\CustomerReturns\Pages;

use App\Actions\Returns\CancelCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\TaskLinkedType;
use App\Exceptions\CustomerReturnException;
use App\Exceptions\ReturnRefundException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturnExceptionCase;
use App\Models\CustomerReturnItem;
use App\Models\Warehouse;
use App\Services\Returns\CustomerReturnExceptionResolutionService;
use App\Services\Returns\CustomerReturnQuarantineService;
use App\Services\Returns\MarketplaceReturnService;
use App\Services\Returns\ReturnRefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ViewCustomerReturn extends ViewRecord
{
    protected static string $resource = CustomerReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::CustomerReturn, $this->record),
            Action::make('reviewIdentityException')->label('Review Wrong / Different Item')->color('warning')
                ->visible(fn (): bool => in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                    && auth()->user()->can('return.inspect', $this->record)
                    && $this->record->status === CustomerReturnStatus::QcPending
                    && $this->record->fulfillmentWarehouse?->location_type !== InventoryLocationType::MarketplaceFulfilment
                    && $this->record->items()->whereHas('findings', fn ($query) => $query->whereIn('problem_type', ['wrong_item', 'serial_mismatch', 'empty_package']))->exists())
                ->schema([
                    Select::make('item_id')->label('Return item')->live()->options(fn (): array => $this->record->items()
                        ->whereHas('findings', fn ($query) => $query->whereIn('problem_type', ['wrong_item', 'serial_mismatch', 'empty_package']))
                        ->whereDoesntHave('quarantine', fn ($query) => $query->where('status', 'resolved'))
                        ->get()->mapWithKeys(fn ($item): array => [$item->id => "{$item->sku_snapshot} — {$item->product_name_snapshot}"])->all())->required(),
                    Select::make('disposition')->label('Owner/Admin decision')->options([
                        'retain_in_quarantine' => 'Keep Item for Review',
                        'returned_to_sender' => 'Actual item returned to sender',
                        'empty_package_confirmed' => 'Confirm empty package',
                    ])->required(),
                    TextInput::make('actual_custody_quantity')->label('Actual items received')
                        ->integer()->minValue(0)
                        ->visible(fn (Get $get): bool => $get('item_id') !== null
                            && ! CustomerReturnItem::query()->find((int) $get('item_id'))?->quarantine()->exists())
                        ->required(fn (Get $get): bool => $get('item_id') !== null
                            && ! CustomerReturnItem::query()->find((int) $get('item_id'))?->quarantine()->exists()),
                    Textarea::make('review_notes')->label('Reason for final decision')->required()->maxLength(5000),
                ])->action(fn (array $data) => $this->run('Cannot review wrong / different item', fn () => app(CustomerReturnQuarantineService::class)->review(
                    CustomerReturnItem::findOrFail($data['item_id']),
                    [...$data, 'review_key' => (string) str()->uuid()], auth()->user(),
                ))),
            Action::make('resolveQcException')->label('Review QC Exception')->color('danger')
                ->visible(fn (): bool => in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                    && auth()->user()->can('return.inspect', $this->record)
                    && $this->record->items()->whereHas('exceptionCases', fn ($query) => $query
                        ->whereNotNull('original_qc_result')->whereNull('resolved_at'))->exists())
                ->schema([
                    Select::make('case_id')->label('Exception to review')->live()->options(fn (): array => CustomerReturnExceptionCase::query()
                        ->whereHas('item', fn ($query) => $query->where('customer_return_id', $this->record->id))
                        ->whereNotNull('original_qc_result')->whereNull('resolved_at')->get()
                        ->mapWithKeys(fn ($case): array => [$case->id => $case->item->sku_snapshot.' — '.str($case->new_problem_type)->replace('_', ' ')->headline()])->all())->required(),
                    Select::make('decision')->label('Owner/Admin decision')->options(function (Get $get): array {
                        $options = ['original_qc_correct' => 'Original QC was correct'];
                        $case = CustomerReturnExceptionCase::query()->find((int) $get('case_id'));
                        if ($case?->kind === 'qc_conflict' && $case->protection_status === 'held') {
                            $options['damage_confirmed'] = 'Damage confirmed — correct safely held stock';
                        }
                        if ($case?->kind === 'identity' && $case->protection_status === 'held'
                            && $case->item->customerReturn->marketplace_platform_id === null) {
                            $options['identity_confirmed'] = 'Different item confirmed — correct original stock';
                        }

                        return $options;
                    })->required(),
                    Select::make('disposition')->label('Actual item disposition')->options([
                        'retain_in_quarantine' => 'Keep actual item in quarantine',
                        'returned_to_sender' => 'Return actual item to sender',
                        'empty_package_confirmed' => 'Confirm empty package',
                    ])->visible(fn (Get $get): bool => $get('decision') === 'identity_confirmed')
                        ->required(fn (Get $get): bool => $get('decision') === 'identity_confirmed'),
                    TextInput::make('actual_custody_quantity')->label('Actual items physically received')
                        ->integer()->minValue(0)->visible(fn (Get $get): bool => $get('decision') === 'identity_confirmed')
                        ->required(fn (Get $get): bool => $get('decision') === 'identity_confirmed'),
                    Textarea::make('reason')->label('Reason for decision')->required()->maxLength(5000),
                ])->action(fn (array $data) => $this->run('Cannot resolve QC exception', function () use ($data): void {
                    $case = CustomerReturnExceptionCase::query()->findOrFail((int) $data['case_id']);
                    if ($case->item->customer_return_id !== $this->record->id) {
                        throw new CustomerReturnException('This exception is not part of the open Return.');
                    }
                    app(CustomerReturnExceptionResolutionService::class)->resolve($case,
                        [...$data, 'resolution_key' => (string) str()->uuid()], auth()->user());
                })),
            Action::make('recordRefund')->label('Record Customer Refund')->color('warning')
                ->visible(fn (): bool => $this->record->refund === null && auth()->user()->can('return.record_refund', $this->record))
                ->schema([
                    TextInput::make('refund_amount')->label('Refund Amount')->prefix('AED')->numeric()->required()->minValue(0.01)
                        ->default(fn (): ?string => app(ReturnRefundService::class)->suggestedAmountForReturn($this->record)),
                    DatePicker::make('refund_date')->label('Refund Date')->default(today())->maxDate(today())->required(),
                    TextInput::make('external_refund_reference')->label('External Refund Reference')->maxLength(255),
                    Textarea::make('note')->label('Note')->maxLength(5000),
                ])->action(function (array $data): void {
                    try {
                        app(ReturnRefundService::class)->recordForReturn(
                            $this->record,
                            (string) $data['refund_amount'],
                            $data['refund_date'],
                            $data['external_refund_reference'] ?? null,
                            $data['note'] ?? null,
                            (string) str()->uuid(),
                            auth()->user(),
                        );
                        $this->record->refresh()->load(['refund', 'claims']);
                        Notification::make()->success()->title('Customer refund recorded')->send();
                    } catch (ReturnRefundException|ValidationException|AuthorizationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? collect($exception->errors())->flatten()->first()
                            : ($exception->getMessage() ?: 'You are not authorized to record this refund.');
                        Notification::make()->danger()->title('Cannot record customer refund')->body($message)->send();
                        throw new Halt;
                    }
                }),
            Action::make('marketplaceDisposition')->label('Record Marketplace Disposition')->schema([
                Select::make('item_id')->label('Return Item')->options(fn () => $this->record->items->mapWithKeys(fn ($i) => [$i->id => "{$i->sku_snapshot} — {$i->product_name_snapshot}"]))->required(),
                TextInput::make('sellable_quantity')->label('Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('non_sellable_quantity')->label('Non-Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.record_marketplace_disposition', $this->record))->action(fn (array $data) => $this->run('Cannot record marketplace disposition', fn () => app(MarketplaceReturnService::class)->disposition(CustomerReturnItem::findOrFail($data['item_id']), (int) $data['sellable_quantity'], (int) $data['non_sellable_quantity'], (string) str()->uuid(), auth()->user(), $data['notes'] ?? null))),
            Action::make('requestRemoval')->label('Request Return / Removal')->schema([
                Select::make('item_id')->label('Return Item')->options(fn () => $this->record->items->mapWithKeys(fn ($i) => [$i->id => "{$i->sku_snapshot} — {$i->product_name_snapshot}"]))->required(),
                TextInput::make('quantity')->integer()->minValue(1)->required(),
                Select::make('destination_id')->label('Company Receiving Location')->options(fn () => Warehouse::query()->active()->whereNotIn('location_type', [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment])->pluck('name', 'id'))->default(fn () => $this->record->platform?->default_return_receiving_warehouse_id)->required(),
                TextInput::make('external_reference')->label('External Removal Reference')->maxLength(255), Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => auth()->user()->can('marketplace_return.request_removal'))->action(fn (array $data) => $this->run('Cannot request marketplace removal', fn () => app(MarketplaceReturnService::class)->requestRemoval(CustomerReturnItem::findOrFail($data['item_id']), (int) $data['quantity'], (int) $data['destination_id'], (string) str()->uuid(), auth()->user(), $data['external_reference'] ?? null, $data['notes'] ?? null))),
            Action::make('receive')->label('Receive Return')->color('warning')->requiresConfirmation()->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.receive', $this->record))->action(fn () => $this->run('Cannot receive return', fn () => app(ReceiveCustomerReturn::class)->handle($this->record, auth()->user()))),
            Action::make('inspect')->label('Continue QC')->color('success')->schema([
                Select::make('item_id')->label('Return Item')->options(fn (): array => $this->qcRemainingItems())->required(),
                TextInput::make('sellable_quantity')->label('Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('damaged_quantity')->label('Damaged Qty')->integer()->minValue(0)->default(0)->live()->required(),
                Select::make('problem_type')->label('Recorded problem')->options([
                    'damaged' => 'Damaged', 'defective' => 'Defective',
                    'missing_accessories' => 'Missing Accessories', 'other' => 'Other',
                ])->visible(fn (Get $get): bool => (int) $get('damaged_quantity') > 0),
                Textarea::make('notes')->label('QC remarks')->maxLength(2000),
            ])->visible(fn (): bool => $this->record->status === CustomerReturnStatus::QcPending
                && auth()->user()->can('return.inspect', $this->record)
                && $this->qcRemainingItems() !== [])->action(fn (array $data) => $this->run('Cannot inspect return', function () use ($data): void {
                    if (! array_key_exists((int) $data['item_id'], $this->qcRemainingItems())) {
                        throw new CustomerReturnException('This item has no ordinary QC quantity remaining.');
                    }
                    app(InspectCustomerReturnItem::class)->handle(CustomerReturnItem::findOrFail($data['item_id']), new InspectCustomerReturnItemData((int) $data['sellable_quantity'], (int) $data['damaged_quantity'], (string) str()->uuid(), $data['notes'] ?? null, $data['problem_type'] ?? null), auth()->user());
                })),
            Action::make('cancel')->label('Cancel Draft')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.cancel', $this->record))->action(fn (array $data) => $this->run('Cannot cancel return', fn () => app(CancelCustomerReturn::class)->handle($this->record, $data['reason'], auth()->user()))),
        ];
    }

    /** @return array<int, string> */
    private function qcRemainingItems(): array
    {
        return $this->record->items()->with('quarantine:id,customer_return_item_id')
            ->get()->filter(function (CustomerReturnItem $item): bool {
                if ($item->quarantine !== null || $item->hasIdentityDiscrepancy()) {
                    return false;
                }
                $marketplaceDispositioned = (int) $item->marketplaceDispositions()->sum('quantity');
                $ceiling = $marketplaceDispositioned > 0
                    ? (int) $item->removalItems()->sum('received_quantity')
                    : $item->return_quantity;

                return $ceiling > $item->inspectedQuantity();
            })->mapWithKeys(fn (CustomerReturnItem $item): array => [
                $item->id => "{$item->sku_snapshot} — {$item->product_name_snapshot}",
            ])->all();
    }

    private function run(string $title, callable $operation): void
    {
        try {
            $operation();
            $this->record->refresh();
        } catch (CustomerReturnException $e) {
            Notification::make()->danger()->title($title)->body($e->getMessage())->send();
            throw new Halt;
        }
    }
}
