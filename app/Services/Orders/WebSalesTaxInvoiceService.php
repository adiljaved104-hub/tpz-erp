<?php

namespace App\Services\Orders;

use App\Enums\InvoicePermission;
use App\Enums\ProductTitleMode;
use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebSalesTaxInvoiceService
{
    public function __construct(
        private readonly WebSalesAuthorization $authorization,
        private readonly WebSalesReadService $sales,
        private readonly InvoiceAuthorization $invoiceAuthorization,
        private readonly TaxInvoiceOrderImportService $imports,
        private readonly InvoiceSettingsService $settings,
        private readonly ReferenceSequenceService $references,
        private readonly TaxInvoiceService $invoices,
    ) {}

    public function generateOrFind(Order $order, User $actor, ProductTitleMode|string $mode = ProductTitleMode::Auto): TaxInvoice
    {
        $this->authorization->authorize($actor, WebSalesPermission::View, $order);
        throw_unless($this->sales->canAccess($actor, $order), AuthorizationException::class);
        $mode = $mode instanceof ProductTitleMode ? $mode : ProductTitleMode::tryFrom($mode) ?? ProductTitleMode::Auto;

        return Cache::lock('web-sales-tax-invoice:'.$order->id, 120)->block(10, function () use ($order, $actor, $mode): TaxInvoice {
            $preflight = DB::transaction(function () use ($order, $actor, $mode): array|TaxInvoice {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->authorization->authorize($actor, WebSalesPermission::View, $locked);
                throw_unless($this->sales->canAccess($actor, $locked), AuthorizationException::class);

                $existing = TaxInvoice::query()->where('source_order_id', $locked->id)->oldest('id')->first();
                if ($existing !== null) {
                    $this->invoiceAuthorization->authorize($actor, InvoicePermission::View, $existing);

                    return $existing;
                }

                $this->invoiceAuthorization->authorize($actor, InvoicePermission::Create);

                $prefill = $this->imports->prefill($actor, $locked->id, $mode);
                if ($prefill === null) {
                    throw ValidationException::withMessages(['invoice' => 'This Web Sale is not eligible for a Tax Invoice.']);
                }
                if (blank($prefill['customer_address'] ?? null)) {
                    throw ValidationException::withMessages(['invoice' => 'Add the customer address to this Web Sale before generating its Tax Invoice.']);
                }

                return $prefill;
            }, 5);

            if ($preflight instanceof TaxInvoice) {
                return $preflight;
            }

            $settings = $this->settings->settings();
            $number = $this->references->nextTaxInvoiceNumber($settings->invoice_prefix, (int) $settings->starting_number);

            return DB::transaction(function () use ($order, $actor, $mode, $preflight, $number): TaxInvoice {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->authorization->authorize($actor, WebSalesPermission::View, $locked);
                throw_unless($this->sales->canAccess($actor, $locked), AuthorizationException::class);

                $existing = TaxInvoice::query()->where('source_order_id', $locked->id)->oldest('id')->first();
                if ($existing !== null) {
                    $this->invoiceAuthorization->authorize($actor, InvoicePermission::View, $existing);

                    return $existing;
                }

                return $this->invoices->create([
                    ...$preflight,
                    'customer_address' => $preflight['customer_address'],
                    'customer_trn' => null,
                    'invoice_date' => now(config('app.timezone'))->toDateString(),
                    'title_mode' => $mode->value,
                    'idempotency_key' => (string) Str::uuid(),
                ], $actor, $number);
            }, 5);
        });
    }
}
