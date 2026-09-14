<?php

namespace App\Services\Mobile;

use App\Enums\DamagedStockPermission;
use App\Enums\InventoryPermission;
use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Chat\ChatQueryService;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Support\Facades\DB;

class MobileDashboardSummary
{
    public function enrich(array $data, User $user, array $filters = []): array
    {
        $cards = collect($data['cards']);
        $canDamage = app(DamagedStockAuthorization::class)->allows($user, DamagedStockPermission::View);
        if (! $canDamage) {
            $cards = $cards->reject(fn ($c) => $c['key'] === 'damaged');
            $data['attention'] = collect($data['attention'])->reject(fn ($a) => str_contains(strtolower($a['label'] ?? ''), 'damaged'))->values();
        }
        if (app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn)) {
            $query = app(MobileInventoryService::class)->query($user);
            $sum = (clone $query)->selectRaw('COUNT(DISTINCT p.id) products, COUNT(DISTINCT CASE WHEN p.status = ? THEN p.id END) active_products, COALESCE(SUM(pi.available_quantity),0) available, COALESCE(SUM(pi.reserved_quantity),0) reserved', ['active'])->first();
            $productStock = (clone $query)->select('p.id')->selectRaw('COALESCE(SUM(pi.available_quantity - pi.reserved_quantity),0) sellable')->groupBy('p.id');
            $threshold = app(StockStatus::class)->low();
            $low = DB::query()->fromSub(clone $productStock, 'stock')->where('sellable', '>', 0)->where('sellable', '<=', $threshold)->count();
            $out = DB::query()->fromSub(clone $productStock, 'stock')->where('sellable', '<=', 0)->count();
            $cards = $cards->reject(fn ($c) => in_array($c['key'], ['sellable_inventory', 'low_stock', 'out_of_stock'], true));
            foreach (['products' => ['Total products', (int) $sum->products], 'active_products' => ['Active products', (int) $sum->active_products],
                'available_stock' => ['Available stock', (int) $sum->available], 'reserved_stock' => ['Reserved stock', (int) $sum->reserved],
                'sellable_inventory' => ['Sellable stock', max(0, (int) $sum->available - (int) $sum->reserved)],
                'low_stock' => ['Low-stock SKUs', $low], 'out_of_stock' => ['Out-of-stock SKUs', $out]] as $key => [$title,$value]) {
                $cards->push(['key' => $key, 'label' => $title, 'title' => $title, 'value' => $value, 'description' => 'Current authorized inventory', 'module' => 'inventory']);
            }
        }
        $moduleKeys = collect(app(MobileWorkspaceCapabilities::class)->modules($user))->pluck('key');
        $periodFilter = $data['period'] === 'custom' ? array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]) : ['period' => $data['period']];
        $targets = [
            'orders' => ['sales', $periodFilter], 'revenue' => ['sales', $periodFilter],
            'inventory_units' => ['inventory', []], 'inventory_value' => ['inventory', []],
            'available_stock' => ['inventory', []], 'reserved_stock' => ['inventory', ['status' => 'reserved']],
            'sellable_inventory' => ['inventory', []], 'low_stock' => ['inventory', ['status' => 'low_stock']],
            'out_of_stock' => ['inventory', ['status' => 'out_of_stock']],
            'damaged' => ['inventory', ['status' => 'damaged']], 'qc_pending' => ['inventory', ['status' => 'qc_pending']],
            'products' => ['products', []], 'active_products' => ['products', ['status' => 'active']],
            'returns' => ['returns', ['filter' => 'open']], 'claims' => ['claims', ['filter' => 'open']],
            'warranty' => ['warranty', ['filter' => 'open', 'type' => 'external']],
            'internal_repairs' => ['internal_repairs', ['filter' => 'open']], 'complaints' => ['complaints', ['filter' => 'open']],
            'tasks' => ['tasks', ['filter' => 'open']], 'chat_unread' => ['chat', []],
            'notifications' => ['notifications', ['status' => 'unread']],
            'attendance_present' => ['hr', ['section' => 'attendance']], 'attendance_late' => ['hr', ['section' => 'attendance']],
            'attendance_absent' => ['hr', ['section' => 'attendance']], 'pending_leave' => ['hr', ['section' => 'leave']],
            'warning_acknowledgments' => ['hr', ['section' => 'warnings']], 'notice_acknowledgments' => ['hr', ['section' => 'notices']],
        ];
        $data['cards'] = $cards->filter(fn ($c) => isset($targets[$c['key']]) && $moduleKeys->contains($targets[$c['key']][0]))
            ->map(fn ($c) => [...$c, 'title' => $c['title'] ?? $c['label'] ?? 'ERP',
                'target' => ['module' => $targets[$c['key']][0], 'filter' => $targets[$c['key']][1]]])->values();
        $data['unread_notifications'] = app(NotificationInboxService::class)->unreadCount($user);
        $data['unread_chat'] = app(ChatQueryService::class)->totalUnread($user);

        return $data;
    }
}
