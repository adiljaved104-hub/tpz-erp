<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CreateTaxInvoice extends CreateRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    protected static bool $canCreateAnother = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected bool $downloadAfterCreate = false;

    protected bool $pdfDownloadFailed = false;

    protected ?string $pendingPdfContent = null;

    protected ?string $pendingPdfFilename = null;

    protected ?string $createdInvoiceReference = null;

    public function getTitle(): string|Htmlable
    {
        return 'Create Tax Invoice';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            'Enter VAT-inclusive prices. VAT and totals are calculated automatically.'
            .'<span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Invoice number will be assigned when saved.</span>',
        );
    }

    public function create(bool $another = false): void
    {
        $this->resetDownloadState();

        parent::create($another);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(TaxInvoiceService::class)->create($data, auth()->user());
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Invoice could not be created')
                ->body('No Invoice was created. Please review the form and try again.')
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->color('gray')
            ->icon('heroicon-o-check')
            ->label(new HtmlString(
                '<span wire:loading.remove wire:target="create">Save Invoice</span>'
                .'<span wire:loading wire:target="create">Saving...</span>',
            ));
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('saveDownloadAndNew')
                ->label(new HtmlString(
                    '<span wire:loading.remove wire:target="saveDownloadAndNew">Save, Download &amp; New</span>'
                    .'<span wire:loading wire:target="saveDownloadAndNew">Creating Invoice...</span>',
                ))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->livewireTarget('saveDownloadAndNew')
                ->action('saveDownloadAndNew'),
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->pdfDownloadFailed) {
            return Notification::make()
                ->danger()
                ->title('Invoice saved, but PDF download failed')
                ->body("Invoice {$this->createdInvoiceReference} was saved successfully, but the PDF could not be downloaded. Open the Invoice from the list and use its PDF action to try again.");
        }

        return Notification::make()
            ->success()
            ->title('Invoice created')
            ->body("{$this->createdInvoiceReference} was created successfully.");
    }

    protected function afterCreate(): void
    {
        $this->createdInvoiceReference = $this->record->invoice_number;

        if (! $this->downloadAfterCreate) {
            return;
        }

        try {
            $documents = app(TaxInvoiceDocumentService::class);
            $this->pendingPdfContent = $documents->pdf($this->record)->output();
            $this->pendingPdfFilename = $documents->filename($this->record);
        } catch (Throwable $exception) {
            report($exception);
            $this->pdfDownloadFailed = true;
        }
    }

    protected function getRedirectUrl(): string
    {
        return TaxInvoiceResource::getUrl('view', ['record' => $this->record]);
    }

    public function saveDownloadAndNew(): ?StreamedResponse
    {
        $this->resetDownloadState();
        $this->downloadAfterCreate = true;

        parent::create(another: true);

        $this->downloadAfterCreate = false;

        if ($this->pendingPdfContent === null || $this->pendingPdfFilename === null) {
            return null;
        }

        $content = $this->pendingPdfContent;
        $filename = $this->pendingPdfFilename;
        $this->pendingPdfContent = null;
        $this->pendingPdfFilename = null;

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function resetDownloadState(): void
    {
        $this->downloadAfterCreate = false;
        $this->pdfDownloadFailed = false;
        $this->pendingPdfContent = null;
        $this->pendingPdfFilename = null;
        $this->createdInvoiceReference = null;
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        $itemKeys = array_keys($this->form->getRawState()['items'] ?? []);
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $path = $this->visibleFormErrorPath($key, $itemKeys);

            foreach ($errors as $message) {
                $messages[] = $message;

                if ($path !== null) {
                    $this->addError($path, $message);
                }
            }
        }

        $this->dispatch('form-validation-error', livewireId: $this->getId());

        Notification::make()
            ->danger()
            ->title('Invoice could not be created')
            ->body(implode(' ', array_unique($messages)))
            ->send();
    }

    /** @param array<int, int|string> $itemKeys */
    private function visibleFormErrorPath(string $key, array $itemKeys): ?string
    {
        if (in_array($key, ['customer_name', 'customer_address', 'customer_trn', 'order_reference', 'invoice_date'], true)) {
            return "data.{$key}";
        }

        if (! preg_match('/^items\.(\d+)\.(description|quantity|unit_price_including_vat)$/', $key, $matches)) {
            return null;
        }

        $index = (int) $matches[1];
        $itemKey = $itemKeys[$index] ?? $index;

        return "data.items.{$itemKey}.{$matches[2]}";
    }
}
