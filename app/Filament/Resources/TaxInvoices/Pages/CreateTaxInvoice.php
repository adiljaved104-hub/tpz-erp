<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Services\Invoices\TaxInvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateTaxInvoice extends CreateRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    protected static bool $canCreateAnother = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    public bool $printAfterCreate = false;

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
        $this->printAfterCreate = false;

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
            Action::make('saveAndPrint')
                ->label(new HtmlString(
                    '<span wire:loading.remove wire:target="saveAndPrint">Save &amp; Print</span>'
                    .'<span wire:loading wire:target="saveAndPrint">Creating Invoice...</span>',
                ))
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->livewireTarget('saveAndPrint')
                ->action('saveAndPrint'),
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Invoice created')
            ->body($this->printAfterCreate
                ? "{$this->record->invoice_number} was created successfully. The PDF is opening in a new tab; if your browser blocks it, download it from the Invoice list."
                : "{$this->record->invoice_number} was created successfully.");
    }

    protected function afterCreate(): void
    {
        if (! $this->printAfterCreate) {
            return;
        }

        $pdfUrl = route('tax-invoices.pdf', ['invoice' => $this->record]);

        $this->js('window.open('.Js::from($pdfUrl).', "_blank", "noopener,noreferrer");');
        $this->data = [];
    }

    protected function getRedirectUrl(): string
    {
        if ($this->printAfterCreate) {
            return TaxInvoiceResource::getUrl('index');
        }

        return TaxInvoiceResource::getUrl('view', ['record' => $this->record]);
    }

    public function saveAndPrint(): void
    {
        $this->printAfterCreate = true;

        parent::create();
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
