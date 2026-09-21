<?php

namespace App\Filament\Pages\Administration;

use App\Enums\InvoicePermission;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\InvoiceSettingsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class InvoiceSettings extends Page
{
    protected string $view = 'filament.pages.administration.invoice-settings';

    protected static ?string $slug = 'administration/invoice-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Invoice Settings';

    public string $invoicePrefix = 'TP-INV';

    public int $nextInvoiceNumber = 9153;

    public int $expectedNextInvoiceNumber = 9153;

    public string $vatRate = '5.00';

    public string $termsEn = '';

    public string $termsAr = '';

    public string $quotationTermsEn = '';

    public string $quotationTermsAr = '';

    public string $proformaTermsEn = '';

    public string $proformaTermsAr = '';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(InvoiceAuthorization::class)->allows($u, InvoicePermission::SettingsView);
    }

    public function mount(InvoiceSettingsService $service): void
    {
        abort_unless(static::canAccess(), 403);
        $s = $service->settings();
        $this->invoicePrefix = $s->invoice_prefix;
        $this->nextInvoiceNumber = $service->nextInvoiceNumber($s);
        $this->expectedNextInvoiceNumber = $this->nextInvoiceNumber;
        $this->vatRate = (string) $s->vat_rate;
        $this->termsEn = $s->terms_en;
        $this->termsAr = (string) $s->terms_ar;
        $this->quotationTermsEn = $s->quotation_terms_en ?? $s->terms_en;
        $this->quotationTermsAr = (string) ($s->quotation_terms_ar ?? $s->terms_ar);
        $this->proformaTermsEn = $s->proforma_terms_en ?? $s->terms_en;
        $this->proformaTermsAr = (string) ($s->proforma_terms_ar ?? $s->terms_ar);
    }

    public function save(InvoiceSettingsService $service): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);
        $data = $this->validate(['invoicePrefix' => ['required', 'regex:/^[A-Z0-9 -]+$/', 'max:30'], 'nextInvoiceNumber' => ['required', 'integer', 'min:1'], 'vatRate' => ['required', 'decimal:0,2', 'gt:0', 'lte:100'], 'termsEn' => ['required', 'string', 'max:10000'], 'termsAr' => ['nullable', 'string', 'max:10000'], 'quotationTermsEn' => ['required', 'string', 'max:10000'], 'quotationTermsAr' => ['nullable', 'string', 'max:10000'], 'proformaTermsEn' => ['required', 'string', 'max:10000'], 'proformaTermsAr' => ['nullable', 'string', 'max:10000']]);
        try {
            $settings = $service->save(['invoice_prefix' => $data['invoicePrefix'], 'next_invoice_number' => $data['nextInvoiceNumber'], 'expected_next_invoice_number' => $this->expectedNextInvoiceNumber, 'vat_rate' => $data['vatRate'], 'terms_en' => $data['termsEn'], 'terms_ar' => $data['termsAr'], 'quotation_terms_en' => $data['quotationTermsEn'], 'quotation_terms_ar' => $data['quotationTermsAr'], 'proforma_terms_en' => $data['proformaTermsEn'], 'proforma_terms_ar' => $data['proformaTermsAr']], $u);
        } catch (ValidationException $exception) {
            foreach ($exception->errors()['next_invoice_number'] ?? [] as $message) {
                $this->addError('nextInvoiceNumber', $message);
            }

            return;
        }
        $this->nextInvoiceNumber = $service->nextInvoiceNumber($settings);
        $this->expectedNextInvoiceNumber = $this->nextInvoiceNumber;
        Notification::make()->success()->title('Invoice Settings saved')->send();
    }
}
