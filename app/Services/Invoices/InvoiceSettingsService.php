<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;

class InvoiceSettingsService
{
    public const TERMS_EN = "1 Year Manufacturer Warranty.\nGoods once sold will not be returned or exchanged unless defective.\nWarranty covers manufacturing defects only. Physical or liquid damage voids warranty.\nOriginal invoice must be presented for warranty claims.";

    public const TERMS_AR = "ضمان الشركة المصنعة لمدة سنة واحدة.\nالبضاعة المباعة لا تُرد ولا تُستبدل إلا إذا كانت معيبة.\nيغطي الضمان عيوب التصنيع فقط، ويلغى الضمان في حال الضرر المادي أو السوائل.\nيجب إبراز الفاتورة الأصلية للمطالبة بالضمان.";

    public function __construct(
        private readonly InvoiceAuthorization $authorization,
        private readonly ActivityLogger $activity,
        private readonly ReferenceSequenceService $references,
    ) {}

    public function settings(): InvoiceSetting
    {
        return InvoiceSetting::query()->find(1) ?? (new InvoiceSetting)->forceFill(['id' => 1, 'invoice_prefix' => 'TP-INV', 'starting_number' => 9153, 'vat_rate' => '5.00', 'terms_en' => self::TERMS_EN, 'terms_ar' => self::TERMS_AR]);
    }

    public function nextInvoiceNumber(?InvoiceSetting $settings = null): int
    {
        $settings ??= $this->settings();

        return $this->references->currentTaxInvoiceNextNumber((int) $settings->starting_number);
    }

    public function save(array $data, User $actor): InvoiceSetting
    {
        $this->authorization->authorize($actor, InvoicePermission::SettingsManage);

        return DB::transaction(function () use ($data, $actor): InvoiceSetting {
            $settings = InvoiceSetting::query()->lockForUpdate()->find(1) ?? (new InvoiceSetting)->forceFill(['id' => 1]);
            $startingNumber = (int) ($settings->starting_number ?: 9153);
            $requested = (int) ($data['next_invoice_number'] ?? $data['starting_number'] ?? $startingNumber);
            $expected = (int) ($data['expected_next_invoice_number'] ?? $this->nextInvoiceNumber($settings));
            $prefix = trim((string) ($data['invoice_prefix'] ?? $settings->invoice_prefix ?? 'TP-INV'));
            $sequence = $this->references->updateTaxInvoiceNextNumber($prefix, $requested, $expected, $startingNumber);

            unset($data['next_invoice_number'], $data['expected_next_invoice_number'], $data['starting_number']);
            $settings->fill(array_merge($data, [
                'invoice_prefix' => $prefix,
                'starting_number' => $sequence['next'],
                'updated_by_user_id' => $actor->id,
            ]))->save();

            $properties = ['settings_id' => 1, 'actor_id' => $actor->id];
            if ($sequence['changed']) {
                $properties['previous_next_number'] = $sequence['previous'];
                $properties['new_next_number'] = $sequence['next'];
            }
            $this->activity->log('invoice_settings.updated', $actor, $settings, $properties);

            return $settings->refresh();
        });
    }
}
