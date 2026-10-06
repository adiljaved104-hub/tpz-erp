<?php

namespace App\Filament\Resources\WebSalesOrders\Pages;

use App\Enums\InvoicePermission;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductTitleMode;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Exceptions\DefaultWarehouseException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Concerns\InteractsWithQcOrderAssignments;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\WebSalesService;
use App\Services\Orders\WebSalesTaxInvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewWebSalesOrder extends ViewRecord
{
    use InteractsWithQcOrderAssignments;

    protected static string $resource = WebSalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->qcOrderActions(),
            Action::make('taxInvoice')
                ->label(fn (): string => $this->record->taxInvoices()->exists() ? 'View Tax Invoice' : 'Generate Tax Invoice')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('primary')
                ->requiresConfirmation(fn (): bool => ! $this->record->taxInvoices()->exists())
                ->modalDescription('Creates an immutable Tax Invoice snapshot using the existing Order lines and product title rules.')
                ->schema(fn (): array => $this->record->taxInvoices()->exists() ? [] : [
                    Select::make('title_mode')->label('Product Title Mode')
                        ->options(collect(ProductTitleMode::cases())->mapWithKeys(fn (ProductTitleMode $mode): array => [$mode->value => $mode->label()])->all())
                        ->default(ProductTitleMode::Auto->value)->required(),
                ])
                ->visible(fn (): bool => $this->canUseTaxInvoice())
                ->action(function (array $data): void {
                    try {
                        $invoice = app(WebSalesTaxInvoiceService::class)->generateOrFind(
                            $this->record,
                            auth()->user(),
                            $data['title_mode'] ?? ProductTitleMode::Auto,
                        );
                        $this->redirect(TaxInvoiceResource::getUrl('view', ['record' => $invoice]));
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Tax Invoice could not be generated')
                            ->body(collect($exception->errors())->flatten()->unique()->join(' '))->send();
                        throw new Halt;
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('Tax Invoice could not be generated')
                            ->body('No Tax Invoice was created. Please try again or contact an administrator.')->send();
                        throw new Halt;
                    }
                }),
            Action::make('updateDelivery')->label('Courier / Tracking')->schema([
                Select::make('delivery_type')->options(WebSalesDeliveryType::class)->required()->live(),
                TextInput::make('courier_name')->required(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier')->visible(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier'),
                TextInput::make('tracking_number')->label('Tracking / AWB')->visible(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier'),
            ])->fillForm(fn (): array => $this->record->only(['delivery_type', 'courier_name', 'tracking_number']))
                ->visible(fn (): bool => $this->allowed(WebSalesPermission::Update))->action(fn (array $data) => $this->run('Could not update delivery', fn () => app(WebSalesService::class)->updateDelivery($this->record, $data, auth()->user()))),
            Action::make('ship')->label('Mark Shipped')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === OrderStatus::Reserved && $this->canFulfill())
                ->action(fn () => $this->run('Cannot ship Web Sale', fn () => app(WebSalesService::class)->ship($this->record, (string) str()->uuid(), auth()->user()))),
            Action::make('deliver')->label('Mark Delivered')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === OrderStatus::Fulfilled && $this->record->delivered_at === null && $this->allowed(WebSalesPermission::Update))
                ->action(fn () => $this->run('Cannot mark Delivered', fn () => app(WebSalesService::class)->deliver($this->record, auth()->user()))),
            Action::make('createReturn')->label('Create Return')->url(fn (): string => CustomerReturnResource::getUrl('create', ['order_id' => $this->record->id]))
                ->visible(fn (): bool => $this->record->status === OrderStatus::Fulfilled && auth()->user()?->can('create', CustomerReturn::class) === true),
            Action::make('cancel')->label('Cancel Order')->color('danger')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Draft, OrderStatus::Reserved], true) && $this->allowed(WebSalesPermission::Cancel))
                ->action(fn (array $data) => $this->run('Cannot cancel Web Sale', fn () => app(WebSalesService::class)->cancel($this->record, $data['reason'], (string) str()->uuid(), auth()->user()))),
        ];
    }

    private function run(string $title, callable $callback): void
    {
        try {
            $this->record = $callback();
            Notification::make()->success()->title('Web Sale updated')->send();
        } catch (ValidationException|InvalidOrderTransitionException|DefaultWarehouseException $exception) {
            Notification::make()->danger()->title($title)->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->join(' ') : $exception->getMessage())->send();
            throw new Halt;
        }
    }

    private function allowed(WebSalesPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, $permission, $this->record);
    }

    private function canFulfill(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill, $this->record);
    }

    private function canUseTaxInvoice(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $invoice = $this->record->taxInvoices()->oldest('id')->first();

        return $invoice !== null
            ? app(InvoiceAuthorization::class)->allows($user, InvoicePermission::View, $invoice)
            : app(InvoiceAuthorization::class)->allows($user, InvoicePermission::Create);
    }

    private function deliveryValue(mixed $value): mixed
    {
        return $value instanceof WebSalesDeliveryType ? $value->value : $value;
    }
}
