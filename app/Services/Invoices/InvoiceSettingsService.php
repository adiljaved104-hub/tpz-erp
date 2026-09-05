<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InvoiceAuthorization;
use Illuminate\Support\Facades\DB;

class InvoiceSettingsService
{
    public const TERMS_EN = "1 Year Manufacturer Warranty.\nGoods once sold will not be returned or exchanged unless defective.\nWarranty covers manufacturing defects only. Physical or liquid damage voids warranty.\nOriginal invoice must be presented for warranty claims.";

    public const TERMS_AR = "ضمان الشركة المصنعة لمدة سنة واحدة.\nالبضاعة المباعة لا تُرد ولا تُستبدل إلا إذا كانت معيبة.\nيغطي الضمان عيوب التصنيع فقط، ويلغى الضمان في حال الضرر المادي أو السوائل.\nيجب إبراز الفاتورة الأصلية للمطالبة بالضمان.";

    public function __construct(private readonly InvoiceAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function settings(): InvoiceSetting
    {
        return InvoiceSetting::query()->find(1) ?? (new InvoiceSetting)->forceFill(['id' => 1, 'invoice_prefix' => 'TP-INV', 'starting_number' => 9153, 'vat_rate' => '5.00', 'terms_en' => self::TERMS_EN, 'terms_ar' => self::TERMS_AR]);
    }

    public function save(array $data, User $actor): InvoiceSetting
    {
        $this->authorization->authorize($actor, InvoicePermission::SettingsManage);

        return DB::transaction(function () use ($data, $actor): InvoiceSetting {
            $settings = InvoiceSetting::query()->lockForUpdate()->find(1) ?? (new InvoiceSetting)->forceFill(['id' => 1]);
            $settings->fill($data + ['updated_by_user_id' => $actor->id])->save();
            $this->activity->log('invoice_settings.updated', $actor, $settings, ['settings_id' => 1, 'actor_id' => $actor->id]);

            return $settings->refresh();
        });
    }
}
