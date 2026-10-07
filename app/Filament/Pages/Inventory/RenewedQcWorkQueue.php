<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\OrderPermission;
use App\Enums\QcPermission;
use App\Filament\Concerns\HandlesActionFeedback;
use App\Models\Order;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\RenewedQcDispatchService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class RenewedQcWorkQueue extends Page implements HasTable
{
    use HandlesActionFeedback;
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Renewed QC / Dispatch';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.inventory.renewed-qc-work-queue';

    public static function canAccess(): bool
    {
        return auth()->user() && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ViewDispatchQueue)
            && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::View);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(RenewedQcDispatchService::class)->queueQuery(auth()->user()))->columns([
            TextColumn::make('reference')->label('Order')->searchable(),
            TextColumn::make('external_order_number')->label('External Reference')->searchable()->wrap(),
            TextColumn::make('warehouse.name')->label('Warehouse'),
            TextColumn::make('qc_lines')->label('Renewed Products / Units')->state(fn (Order $record): string => collect(app(RenewedQcDispatchService::class)->readiness($record)['lines'])
                ->filter(fn ($line) => $line['required'])->map(fn ($line) => $line['sku'].' · '.$line['product'].' — '.$line['assigned'].' / '.$line['required'].' assigned; '.$line['remaining'].' remaining')->join("\n"))->wrap(),
            TextColumn::make('qc_readiness')->label('QC Readiness')->state(fn (Order $record): string => str(app(RenewedQcDispatchService::class)->readiness($record)['status'])->headline()->toString())->badge(),
            TextColumn::make('order_date')->date(),
            TextColumn::make('created_at')->label('Created')->formatStateUsing(fn ($state) => app(BusinessTimezone::class)->format($state)),
        ])->recordActions([
            Action::make('scanQc')->label('Scan / Assign Unit')->icon('heroicon-o-qr-code')
                ->visible(fn (Order $record): bool => app(QcOrderAssignmentService::class)->allows(auth()->user(), QcPermission::AssignOrderDevice, $record)
                    && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ScanDispatch))
                ->modalDescription('Select the exact Order Item, then scan its physical QC label or enter Serial / QC ID. Scanning assigns a unit; it never ships the Order.')
                ->schema(fn (Order $record): array => [
                    Select::make('order_item_id')->label('Renewed Order Item')->required()->live()->options(fn (): array => $record->items()->with('product')->get()
                        ->filter(fn ($item) => app(RenewedQcDispatchService::class)->requiresQc($item))->mapWithKeys(fn ($item) => [$item->id => $item->sku.' · '.$item->product_name.' · Qty '.$item->ordered_quantity])->all()),
                    TextInput::make('code')->label('Serial / IMEI / QC ID')->required()->maxLength(2048),
                    View::make('qc.dispatch-scanner'),
                ])
                ->action(fn (Order $record, array $data) => $this->performScan($record, (int) $data['order_item_id'], $data['code'])),
            Action::make('shipReadyOrder')->label('Mark Shipped')->color('success')->requiresConfirmation()
                ->visible(fn (Order $record): bool => app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::Fulfill, $record)
                    && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ShipDispatch))
                ->disabled(fn (Order $record): bool => app(RenewedQcDispatchService::class)->readiness($record)['status'] !== 'ready')
                ->tooltip('Every Renewed unit must have a valid, current QC assignment. Shipment revalidates all units and posts normal inventory fulfilment.')
                ->schema([Hidden::make('idempotency_key')->default(fn () => (string) str()->uuid())->required()->rules(['uuid'])])
                ->action(function (Order $record, array $data): void {
                    $result = $this->runWithActionFeedback(fn () => app(RenewedQcDispatchService::class)->ship($record, $data['idempotency_key'], auth()->user()), 'Order was not shipped');
                    if ($result === null) {
                        throw new Halt;
                    }
                    Notification::make()->success()->title('Order shipped')->body($record->reference)->send();
                }),
        ])->defaultPaginationPageOption(25);
    }

    public function resolveQcDispatchScan(string $code): bool
    {
        $action = $this->getMountedAction();
        if ($action?->getName() !== 'scanQc' || ! ($order = $action->getRecord()) instanceof Order) {
            throw ValidationException::withMessages(['code' => 'Open Scan / Assign Unit and select an Order Item first.']);
        }
        $schema = $this->getMountedActionSchema();
        $state = (array) $schema->getRawState();
        $this->performScan($order, (int) ($state['order_item_id'] ?? 0), $code);
        $schema->fill([...$state, 'code' => null]);

        return true;
    }

    private function performScan(Order $order, int $itemId, string $code): void
    {
        $result = $this->runWithActionFeedback(function () use ($order, $itemId, $code) {
            try {
                return app(RenewedQcDispatchService::class)->scan($order, $itemId, $code, auth()->user());
            } catch (ValidationException $e) {
                $path = $this->getMountedActionSchema()?->getStatePath();
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.($field === 'certificate_id' ? 'code' : $field) => $messages])->all());
            }
        }, 'QC unit was not assigned');
        if ($result === null) {
            throw new Halt;
        }
        Notification::make()->success()->title('QC unit assigned')->body($result['device']['qc_id'].' · '.$result['readiness']['assigned'].' / '.$result['readiness']['required'].' assigned. The Order remains Reserved.')->send();
    }
}
