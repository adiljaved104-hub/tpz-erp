<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Services\Invoices\TaxInvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewTaxInvoice extends ViewRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Invoices')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(TaxInvoiceResource::getUrl('index')),
            Action::make('pdf')->label('Download PDF')->url(fn () => route('tax-invoices.pdf', ['invoice' => $this->record]))->openUrlInNewTab()->visible(fn () => auth()->user()->can(InvoicePermission::DownloadPdf->value)),
            Action::make('print')->label('Print')->icon('heroicon-o-printer')->url(fn () => route('tax-invoices.pdf', ['invoice' => $this->record, 'print' => 1]))->openUrlInNewTab()->visible(fn () => auth()->user()->can(InvoicePermission::DownloadPdf->value)),
            Action::make('void')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()])->visible(fn () => $this->record->status === 'issued' && auth()->user()->can(InvoicePermission::Void->value))->action(fn (array $data) => app(TaxInvoiceService::class)->void($this->record, $data['reason'], auth()->user())),
        ];
    }
}
