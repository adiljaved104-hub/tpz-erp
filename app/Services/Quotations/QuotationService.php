<?php

namespace App\Services\Quotations;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\QuotationDocumentType;
use App\Enums\QuotationItemSourceType;
use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\QuotationItemSourcingInstruction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\CompanyProfileService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        private readonly QuotationAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly CompanyProfileService $company,
        private readonly InvoiceSettingsService $settings,
        private readonly QuotationPricingService $pricing,
        private readonly ActivityLogger $activity,
        private readonly QuotationSourcingService $sourcing,
    ) {}

    public function create(array $data, User $actor): Quotation
    {
        $this->authorization->authorize($actor, QuotationPermission::Create);
        $validated = $this->validate($data);
        if ($existing = Quotation::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing->load('items');
        }
        $products = $this->products($validated['items']);
        $catalog = $this->manualCatalog($validated['items']);
        $instructions = $this->sourcing->validateInstructions($validated['items'], (int) $validated['warehouse_id'], $actor);
        $priced = $this->pricing->calculate($validated['items']);
        $reference = $this->references->nextQuotationReference((int) substr($validated['quotation_date'], 0, 4));
        $profile = $this->company->snapshot();
        $settings = $this->settings->settings();

        return DB::transaction(function () use ($validated, $actor, $products, $catalog, $priced, $reference, $profile, $settings, $instructions): Quotation {
            $quote = Quotation::query()->create([
                ...collect($validated)->except('items')->all(),
                'reference' => $reference,
                'status' => QuotationStatus::Draft,
                'currency' => 'AED',
                'subtotal_excluding_vat' => $priced['subtotal'],
                'discount_total' => $priced['discount'],
                'vat_amount' => $priced['vat'],
                'grand_total' => $priced['grand'],
                'seller_snapshot' => $profile,
                'terms_en_snapshot' => $settings->terms_en,
                'terms_ar_snapshot' => $settings->terms_ar,
                'salesperson_employee_id' => $actor->employee->id,
                'created_by_user_id' => $actor->id,
            ]);
            $this->storeItems($quote, $priced['lines'], $products, $catalog, $instructions);
            $this->activity->log('quotation.created', $actor, $quote, ['quotation_reference' => $quote->reference, 'item_count' => count($priced['lines']), 'actor_id' => $actor->id]);

            return $quote->load(['items', 'salesperson']);
        });
    }

    public function updateDraft(Quotation $quotation, array $data, User $actor): Quotation
    {
        $this->authorization->authorize($actor, QuotationPermission::Update, $quotation);
        $validated = $this->validate($data, false);
        $instructions = $this->sourcing->validateInstructions($validated['items'], (int) ($validated['warehouse_id'] ?? 0), $actor, $quotation);
        $products = $this->products($validated['items']);
        $catalog = $this->manualCatalog($validated['items']);
        $priced = $this->pricing->calculate($validated['items']);

        return DB::transaction(function () use ($quotation, $validated, $products, $catalog, $priced, $actor, $instructions): Quotation {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->status !== QuotationStatus::Draft || $locked->effectiveStatus() === QuotationStatus::Expired) {
                throw ValidationException::withMessages(['status' => 'Only an unexpired Draft quotation can be edited.']);
            }
            $existingInstructions = QuotationItemSourcingInstruction::query()->whereIn('quotation_item_id', $locked->items()->select('id'));
            if ((clone $existingInstructions)->exists()) {
                $this->sourcing->authorizeManage($actor, $locked);
                $existingInstructions->get(['id', 'quotation_item_id'])->each->delete();
            }
            $locked->forceFill([
                ...collect($validated)->except(['items', 'idempotency_key'])->all(),
                'subtotal_excluding_vat' => $priced['subtotal'], 'discount_total' => $priced['discount'],
                'vat_amount' => $priced['vat'], 'grand_total' => $priced['grand'],
            ])->save();
            $locked->items()->delete();
            $this->storeItems($locked, $priced['lines'], $products, $catalog, $instructions);
            $this->activity->log('quotation.updated', $actor, $locked, ['quotation_reference' => $locked->reference, 'item_count' => count($priced['lines']), 'actor_id' => $actor->id]);

            return $locked->load('items');
        });
    }

    public function transition(Quotation $quotation, QuotationStatus $to, User $actor, ?string $reason = null): Quotation
    {
        $permission = match ($to) {
            QuotationStatus::Sent => QuotationPermission::Send,
            QuotationStatus::Accepted => QuotationPermission::Accept,
            QuotationStatus::Rejected => QuotationPermission::Reject,
            QuotationStatus::Cancelled => QuotationPermission::Cancel,
            default => throw ValidationException::withMessages(['status' => 'Unsupported Quotation transition.']),
        };
        $this->authorization->authorize($actor, $permission, $quotation);

        return DB::transaction(function () use ($quotation, $to, $actor, $reason): Quotation {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $from = $locked->effectiveStatus();
            $allowed = match ($to) {
                QuotationStatus::Sent => $from === QuotationStatus::Draft,
                QuotationStatus::Accepted, QuotationStatus::Rejected => $from === QuotationStatus::Sent,
                QuotationStatus::Cancelled => in_array($from, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Accepted], true),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => "This Quotation cannot move from {$from->label()} to {$to->label()}."]);
            }
            if (in_array($to, [QuotationStatus::Rejected, QuotationStatus::Cancelled], true) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            $timestamp = match ($to) {
                QuotationStatus::Sent => 'sent_at', QuotationStatus::Accepted => 'accepted_at',
                QuotationStatus::Rejected => 'rejected_at', QuotationStatus::Cancelled => 'cancelled_at', default => null,
            };
            $locked->forceFill(['status' => $to, $timestamp => now()])->save();
            $this->activity->log('quotation.'.$to->value, $actor, $locked, ['quotation_reference' => $locked->reference, 'actor_id' => $actor->id], $reason);

            return $locked->refresh();
        });
    }

    public function preview(array $items): array
    {
        return $this->pricing->calculate(array_values(array_filter($items, fn ($i) => is_numeric($i['quantity'] ?? null) && (int) $i['quantity'] > 0
            && is_numeric($i['unit_price_including_vat'] ?? null) && (float) $i['unit_price_including_vat'] > 0)));
    }

    private function validate(array $data, bool $creating = true): array
    {
        $data['items'] = collect($data['items'] ?? [])->map(function (array $item): array {
            $item['source_type'] ??= QuotationItemSourceType::ExistingProduct->value;
            if ($item['source_type'] === QuotationItemSourceType::ManualSourced->value) {
                $item['product_id'] = null;
                $item['source_inventory'] = true;
            }

            return $item;
        })->all();
        $rules = [
            'warehouse_id' => [$creating ? 'required' : 'nullable', 'integer', Rule::exists('warehouses', 'id')->where('status', true)],
            'document_type' => ['required', Rule::enum(QuotationDocumentType::class)],
            'quotation_date' => ['required', 'date'], 'valid_until' => ['required', 'date', 'after_or_equal:quotation_date'],
            'customer_name' => ['required', 'string', 'max:190'], 'customer_company' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'], 'customer_email' => ['nullable', 'email', 'max:190'],
            'customer_address' => ['nullable', 'string', 'max:4000'], 'customer_trn' => ['nullable', 'string', 'max:50'],
            'external_reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.source_type' => ['required', Rule::enum(QuotationItemSourceType::class)],
            'items.*.product_id' => ['nullable', 'integer', 'required_if:items.*.source_type,'.QuotationItemSourceType::ExistingProduct->value],
            'items.*.manual_brand_id' => ['nullable', 'integer', 'required_if:items.*.source_type,'.QuotationItemSourceType::ManualSourced->value, Rule::exists('product_brands', 'id')->where('status', true)],
            'items.*.manual_category_id' => ['nullable', 'integer', 'required_if:items.*.source_type,'.QuotationItemSourceType::ManualSourced->value, Rule::exists('product_categories', 'id')->where('status', true)],
            'items.*.manual_condition' => ['nullable', 'required_if:items.*.source_type,'.QuotationItemSourceType::ManualSourced->value, Rule::enum(ProductCondition::class)],
            'items.*.manual_model' => ['nullable', 'string', 'max:120'],
            'items.*.description' => ['required', 'string', 'max:500'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_including_vat' => ['required', 'decimal:0,2', 'gt:0'],
            'items.*.discount_amount' => ['nullable', 'decimal:0,2', 'min:0'], 'items.*.vat_rate' => ['required', 'decimal:0,4', 'between:0,100'],
            'items.*.source_inventory' => ['sometimes', 'boolean'],
            'items.*.purchase_unit_cost' => ['nullable', 'numeric', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'items.*.source_note' => ['nullable', 'string', 'max:1000'],
        ];
        if ($creating) {
            $rules['idempotency_key'] = ['required', 'uuid'];
        }

        $validator = validator($data, $rules);
        $validator->after(function ($validator) use ($data): void {
            $existing = collect($data['items'] ?? [])
                ->where('source_type', QuotationItemSourceType::ExistingProduct->value)
                ->pluck('product_id')->filter();
            if ($existing->duplicates()->isNotEmpty()) {
                $validator->errors()->add('items', 'An existing Product may appear only once in a Quotation.');
            }
            foreach ($data['items'] ?? [] as $index => $item) {
                if (($item['source_type'] ?? null) === QuotationItemSourceType::ManualSourced->value
                    && mb_strlen(trim((string) ($item['description'] ?? ''))) > 255) {
                    $validator->errors()->add("items.{$index}.description", 'The manual Product name or description must not exceed 255 characters.');
                }
            }
        });
        $validated = $validator->validate();
        if (isset($validated['warehouse_id'])) {
            app(OrderFulfillmentLocationService::class)->assertSelectable(
                Warehouse::query()->findOrFail($validated['warehouse_id']), null,
            );
        }
        // Filament repeaters can use UUID keys; align internal instructions with priced lines.
        $validated['items'] = array_values($validated['items']);

        return $validated;
    }

    private function products(array $items)
    {
        $existingItems = collect($items)->where('source_type', QuotationItemSourceType::ExistingProduct->value);
        $products = Product::query()->products()->with(['brandRelation', 'categoryRelation'])
            ->whereKey($existingItems->pluck('product_id'))->get()->keyBy('id');
        foreach ($items as $index => $item) {
            if ($item['source_type'] === QuotationItemSourceType::ManualSourced->value) {
                continue;
            }
            if (($product = $products->get($item['product_id'])) === null || $product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'Select an active Product.']);
            }
        }

        return $products;
    }

    private function manualCatalog(array $items): array
    {
        $manual = collect($items)->where('source_type', QuotationItemSourceType::ManualSourced->value);

        return [
            'brands' => ProductBrand::query()->active()->whereKey($manual->pluck('manual_brand_id'))->get()->keyBy('id'),
            'categories' => ProductCategory::query()->active()->whereKey($manual->pluck('manual_category_id'))->get()->keyBy('id'),
        ];
    }

    private function storeItems(Quotation $quote, array $lines, $products, array $catalog, array $instructions = []): void
    {
        foreach ($lines as $index => $line) {
            $manual = $line['source_type'] === QuotationItemSourceType::ManualSourced->value;
            $product = $manual ? null : $products->get($line['product_id']);
            $brand = $manual ? $catalog['brands']->get($line['manual_brand_id']) : null;
            $category = $manual ? $catalog['categories']->get($line['manual_category_id']) : null;
            $item = $quote->items()->create([
                'source_type' => $line['source_type'],
                'product_id' => $product?->id,
                'sku' => $product?->sku,
                'product_name' => $product?->name ?? $line['description'],
                'brand_name' => $product?->displayBrandName() ?? $brand?->name,
                'category_name' => $product?->displayCategoryName() ?? $category?->name,
                'model_name' => $product?->model ?? ($line['manual_model'] ?? null),
                'manual_brand_id' => $manual ? $brand?->id : null,
                'manual_category_id' => $manual ? $category?->id : null,
                'manual_condition' => $manual ? $line['manual_condition'] : null,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price_including_vat' => $line['unit_price_including_vat'],
                'discount_amount' => $line['discount_amount'],
                'vat_rate' => $line['vat_rate'],
                'subtotal_excluding_vat' => $line['subtotal_excluding_vat'],
                'vat_amount' => $line['vat_amount'],
                'total_including_vat' => $line['total_including_vat'],
                'line_number' => $line['line_number'],
            ]);
            if (isset($instructions[$index])) {
                $item->sourcingInstruction()->create($instructions[$index]);
            }
        }
    }
}
