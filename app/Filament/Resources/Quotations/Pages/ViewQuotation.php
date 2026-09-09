<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationEmailService;
use App\Services\Quotations\QuotationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Js;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        $status = $this->record->effectiveStatus();

        return [
            EditAction::make()->visible(fn () => QuotationResource::canEdit($this->record)),
            Action::make('pdf')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')->url(fn () => route('quotations.pdf', $this->record))->openUrlInNewTab()->visible(fn () => QuotationResource::allowed(QuotationPermission::Export, $this->record)),
            Action::make('print')->icon('heroicon-o-printer')->url(fn () => route('quotations.pdf', ['quotation' => $this->record, 'print' => 1]))->openUrlInNewTab()->visible(fn () => QuotationResource::allowed(QuotationPermission::Export, $this->record)),
            Action::make('whatsapp')->label('Copy WhatsApp Message')->icon('heroicon-o-clipboard')->action(function (): void {
                $q = $this->record;
                $text = "Quotation {$q->reference}\nCustomer: {$q->customer_name}\nTotal: AED ".number_format((float) $q->grand_total, 2)."\nValid Until: {$q->valid_until->format('d M Y')}\nPlease find the attached {$q->document_type->label()}.";
                $this->js('navigator.clipboard.writeText('.Js::from($text).')');
                Notification::make()->success()->title('WhatsApp message copied')->send();
            }),
            Action::make('email')->label('Send Email')->icon('heroicon-o-envelope')->schema([TextInput::make('email')->email()->required()->default(fn () => $this->record->customer_email), Hidden::make('idempotency_key')->default(fn () => (string) str()->uuid())])
                ->visible(fn () => QuotationResource::allowed(QuotationPermission::Send, $this->record) && in_array($status, [QuotationStatus::Draft, QuotationStatus::Sent], true))
                ->action(function (array $data): void {
                    app(QuotationEmailService::class)->queue($this->record, $data['email'], auth()->user(), $data['idempotency_key']);
                    Notification::make()->success()->title('Quotation email queued')->send();
                    $this->record->refresh();
                }),
            Action::make('markSent')->label('Mark Sent')->requiresConfirmation()->visible(fn () => QuotationResource::allowed(QuotationPermission::Send, $this->record) && $status === QuotationStatus::Draft)->action(fn () => app(QuotationService::class)->transition($this->record, QuotationStatus::Sent, auth()->user())),
            Action::make('accept')->color('success')->requiresConfirmation()->visible(fn () => QuotationResource::allowed(QuotationPermission::Accept, $this->record) && $status === QuotationStatus::Sent)->action(fn () => app(QuotationService::class)->transition($this->record, QuotationStatus::Accepted, auth()->user())),
            Action::make('reject')->color('danger')->schema([Textarea::make('reason')->required()])->visible(fn () => QuotationResource::allowed(QuotationPermission::Reject, $this->record) && $status === QuotationStatus::Sent)->action(fn (array $d) => app(QuotationService::class)->transition($this->record, QuotationStatus::Rejected, auth()->user(), $d['reason'])),
            Action::make('cancel')->color('danger')->schema([Textarea::make('reason')->required()])->visible(fn () => QuotationResource::allowed(QuotationPermission::Cancel, $this->record) && in_array($status, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Accepted], true))->action(fn (array $d) => app(QuotationService::class)->transition($this->record, QuotationStatus::Cancelled, auth()->user(), $d['reason'])),
            Action::make('convertOrder')->label('Convert to Order')->icon('heroicon-o-shopping-cart')->schema([Select::make('warehouse_id')->label('Fulfilment Warehouse')->default(fn () => $this->record->warehouse_id)->disabled(fn () => $this->record->warehouse_id !== null)->dehydrated()->options(fn () => app(OrderFulfillmentLocationService::class)->options(null))->required()])->visible(fn () => QuotationResource::allowed(QuotationPermission::ConvertOrder, $this->record) && $status === QuotationStatus::Accepted && ! $this->record->order_id)->action(function (array $d): void {
                $o = app(QuotationConversionService::class)->toOrder($this->record, (int) $d['warehouse_id'], auth()->user());
                Notification::make()->success()->title("Converted to Order {$o->reference}")->send();
                $this->record->refresh();
            }),
            Action::make('convertInvoice')->label('Convert to Tax Invoice')->icon('heroicon-o-document-currency-dollar')->requiresConfirmation()->visible(fn () => QuotationResource::allowed(QuotationPermission::ConvertInvoice, $this->record) && $status === QuotationStatus::Accepted && ! $this->record->tax_invoice_id && QuotationResource::canConvertDirectlyToInvoice($this->record))->action(function (): void {
                $i = app(QuotationConversionService::class)->toInvoice($this->record, auth()->user());
                Notification::make()->success()->title("Converted to Invoice {$i->invoice_number}")->send();
                $this->record->refresh();
            }),
        ];
    }
}
