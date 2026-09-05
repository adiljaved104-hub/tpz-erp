<?php

namespace App\Filament\Pages\Administration;

use App\Enums\InvoicePermission;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\InvoiceSettingsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class InvoiceSettings extends Page
{
    protected string $view = 'filament.pages.administration.invoice-settings';

    protected static ?string $slug = 'administration/invoice-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Invoice Settings';

    public string $invoicePrefix = 'TP-INV';

    public int $startingNumber = 9153;

    public string $vatRate = '5.00';

    public string $termsEn = '';

    public string $termsAr = '';

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
        $this->startingNumber = (int) $s->starting_number;
        $this->vatRate = (string) $s->vat_rate;
        $this->termsEn = $s->terms_en;
        $this->termsAr = (string) $s->terms_ar;
    }

    public function save(InvoiceSettingsService $service): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);
        $data = $this->validate(['invoicePrefix' => ['required', 'regex:/^[A-Z0-9 -]+$/', 'max:30'], 'startingNumber' => ['required', 'integer', 'min:1'], 'vatRate' => ['required', 'decimal:0,2', 'gt:0', 'lte:100'], 'termsEn' => ['required', 'string', 'max:10000'], 'termsAr' => ['nullable', 'string', 'max:10000']]);
        $service->save(['invoice_prefix' => $data['invoicePrefix'], 'starting_number' => $data['startingNumber'], 'vat_rate' => $data['vatRate'], 'terms_en' => $data['termsEn'], 'terms_ar' => $data['termsAr']], $u);
        Notification::make()->success()->title('Invoice Settings saved')->send();
    }
}
