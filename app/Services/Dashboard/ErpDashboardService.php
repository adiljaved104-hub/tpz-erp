<?php

namespace App\Services\Dashboard;

use App\Enums\ChatPermission;
use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Filament\Pages\Chat;
use App\Filament\Pages\Hr\Attendance;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\Notifications;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Chat\ChatQueryService;
use App\Services\Hr\AttendanceQueryService;
use App\Services\Hr\HrScopeService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use App\Services\Responsibilities\ResponsibilityReadService;
use App\Services\Tasks\TaskQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ErpDashboardService
{
    public function __construct(
        private readonly OrderAuthorization $orders,
        private readonly OrderResponsibilityScopeService $orderScope,
        private readonly InventoryAuthorization $inventory,
        private readonly InventoryLocationOverviewService $inventoryOverview,
        private readonly CustomerReturnAuthorization $returns,
        private readonly SafetClaimAuthorization $claims,
        private readonly WarrantyRepairAuthorization $warranty,
        private readonly ComplaintAuthorization $complaints,
        private readonly TaskAuthorization $tasks,
        private readonly TaskQueryService $taskQueries,
        private readonly HrAuthorization $hr,
        private readonly HrScopeService $hrScope,
        private readonly AttendanceQueryService $attendance,
        private readonly ChatAuthorization $chat,
        private readonly ChatQueryService $chatQueries,
        private readonly ResponsibilityAuthorization $responsibilities,
        private readonly ResponsibilityReadService $responsibilityReads,
        private readonly ResponsibilityCapacityService $responsibilityCapacity,
        private readonly DashboardInventoryIntelligenceService $inventoryIntelligence,
        private readonly PurchaseAuthorization $purchases,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(
        User $user,
        string $period = 'today',
        ?string $customFrom = null,
        ?string $customTo = null,
        string $inventoryScope = 'company',
        ?int $inventoryTeamId = null,
        ?int $inventoryEmployeeId = null,
        ?array $widgetKeys = null,
    ): array {
        [$period, $from, $to, $periodLabel] = $this->period($period, $customFrom, $customTo);
        $cards = collect();
        $attention = collect();
        $requested = $widgetKeys === null ? null : collect($widgetKeys)->flip();
        $showAttention = $this->wants($requested, 'attention');
        $showSales = $this->wants($requested, 'sales');
        $showInventory = $this->wants($requested, 'inventory');
        $showInventoryIntelligence = $this->wants($requested, 'inventory_intelligence');
        $showService = $this->wants($requested, 'service');
        $showWork = $this->wants($requested, 'work');
        $showHr = $this->wants($requested, 'hr');

        if ($showSales || $showAttention) {
            $this->addOrderCards($cards, $user, $from, $to, $periodLabel, $showSales);
        }
        if ($showInventory || $showAttention) {
            $this->addInventoryCards($cards, $attention, $user, $showInventory);
        }
        if ($showService || $showAttention) {
            $this->addCaseCards($cards, $attention, $user, $showService);
        }
        if ($showWork || $showAttention) {
            $this->addTaskCards($cards, $attention, $user, $showWork);
        }
        $attendance = ($showHr || $showAttention)
            ? $this->attendanceData($cards, $attention, $user, $from, $to, $periodLabel, $showHr, $showAttention)
            : null;
        if ($showWork || $showHr || $showAttention) {
            $this->addCommunicationCards($cards, $attention, $user, $showWork, $showHr, $showAttention);
        }
        if ($showAttention) {
            $this->addConsolidatedLegacyAttention($attention, $user);
        }

        $inventoryData = null;
        if ($showInventory || $showInventoryIntelligence) {
            $inventoryData = $this->inventoryIntelligence->forUser(
                $user,
                $from,
                $to,
                $inventoryScope,
                $inventoryTeamId,
                $inventoryEmployeeId,
            );
        }

        if ($showInventory && $inventoryData !== null) {
            $inventoryUrl = $this->inventory->allows($user, InventoryPermission::View) ? ProductInventoryResource::getUrl() : MyInventory::getUrl();
            $cards->push($this->card('sellable_inventory', 'Sellable Inventory Units', $inventoryData['sellable_units'], $inventoryData['scope_label'].' scope · Available minus Reserved', $inventoryUrl));
            $cards->push($this->card('low_stock', 'Low Stock Products', $inventoryData['low_stock_count'], 'Sellable stock from 1 to '.$inventoryData['threshold'], $inventoryUrl, 'warning'));
            $cards->push($this->card('out_of_stock', 'Out of Stock Products', $inventoryData['out_of_stock_count'], 'Sellable stock is zero or below', $inventoryUrl, 'danger'));
        }

        return [
            'period' => $period,
            'period_label' => $periodLabel,
            'cards' => $cards->values(),
            'attention' => $showAttention ? $attention->sortByDesc('priority')->values() : collect(),
            'attendance' => $attendance,
            'inventory_intelligence' => $showInventoryIntelligence ? $inventoryData : null,
            'responsibilities' => $this->wants($requested, 'responsibilities') ? $this->responsibilityData($user) : null,
            'role_label' => str($user->employee->role->value)->headline()->toString(),
        ];
    }

    /** @return array{string, CarbonImmutable, CarbonImmutable, string} */
    private function period(string $period, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        if ($period === 'custom' && $customFrom !== null && $customTo !== null) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $customFrom, config('app.timezone'))->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $customTo, config('app.timezone'))->startOfDay();

            return ['custom', $from, $to, 'Custom Range'];
        }

        return match ($period) {
            'week' => ['week', $today->startOfWeek(), $today->endOfWeek(), 'This Week'],
            'month' => ['month', $today->startOfMonth(), $today->endOfMonth(), 'This Month'],
            default => ['today', $today, $today, 'Today'],
        };
    }

    private function addOrderCards(Collection $cards, User $user, CarbonImmutable $from, CarbonImmutable $to, string $label, bool $includeCards = true): void
    {
        if (! $this->orders->allows($user, OrderPermission::View)) {
            return;
        }

        $query = Order::query()
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString());
        if ($this->orderScope->requiresScope($user)) {
            $query->whereHas('items');
        }
        $this->orderScope->applyOrders($query, $user);

        if (! $includeCards) {
            return;
        }

        $cards->push($this->card('orders', "Orders {$label}", (clone $query)->count(), 'Authorized operational orders', OrderResource::getUrl()));

        if ($this->orders->allows($user, OrderPermission::ViewSellingPrice)) {
            $cards->push($this->card('revenue', "Sales {$label}", $this->money((string) (clone $query)->sum('grand_total')), 'Recorded Order total', OrderResource::getUrl(), 'success'));
        }
    }

    private function addInventoryCards(Collection $cards, Collection $attention, User $user, bool $includeCards = true): void
    {
        if (! $this->inventory->allows($user, InventoryPermission::View)) {
            return;
        }

        $products = $this->inventoryOverview->forUser($user, $includeCards);
        $summary = $this->inventoryOverview->summaryForProducts($products);
        $url = ProductInventoryResource::getUrl();

        if ($includeCards) {
            $cards->push($this->card('inventory_units', 'Inventory Units', $summary['total_company_stock'], 'Company-owned units in authorized scope', $url));
            $cards->push($this->card('damaged', 'Damaged Stock', $summary['damaged'], 'Units requiring operational attention', $url, $summary['damaged'] > 0 ? 'danger' : 'gray'));
            $cards->push($this->card('qc_pending', 'QC Pending', $summary['qc_pending'], 'Units awaiting quality control', $url, $summary['qc_pending'] > 0 ? 'warning' : 'gray'));
        }

        if ($includeCards && $this->inventory->allows($user, InventoryPermission::ViewFinancials)) {
            $value = $products->reduce(fn (string $carry, array $product): string => bcadd($carry, (string) ($product['total_inventory_value'] ?? '0'), 4), '0.0000');
            $cards->push($this->card('inventory_value', 'Inventory Value', $this->money($value), 'Authorized current inventory valuation', $url, 'success'));
        }

        if ($summary['damaged'] > 0) {
            $attention->push($this->attention('Damaged stock needs action', $summary['damaged'], $url, 80));
        }
        if ($summary['qc_pending'] > 0) {
            $attention->push($this->attention('QC inspections pending', $summary['qc_pending'], $url, 85));
        }
    }

    private function addCaseCards(Collection $cards, Collection $attention, User $user, bool $includeCards = true): void
    {
        if ($this->returns->allows($user, CustomerReturnPermission::View)) {
            $query = CustomerReturn::query()->whereIn('order_id', $this->scopedOrderIds($user))
                ->whereNotIn('status', ['completed', 'cancelled']);
            $count = $query->count();
            $url = CustomerReturnResource::getUrl();
            if ($includeCards) {
                $cards->push($this->card('returns', 'Open Returns', $count, 'Returns not yet completed', $url));
            }
            $this->attentionWhen($attention, $count, 'Returns need processing', $url, 75);
        }

        if ($this->claims->allows($user, SafetClaimPermission::View)) {
            $query = $this->claims->scopeQuery(SafetClaim::query(), $user)
                ->whereNotIn('status', ['rejected', 'closed', 'not_eligible']);
            $count = $query->count();
            $filing = (clone $query)->where('status', 'needs_filing')->count();
            $url = SafetClaimResource::getUrl();
            if ($includeCards) {
                $cards->push($this->card('claims', 'Open Claims', $count, 'Visible claim cases', $url));
            }
            $this->attentionWhen($attention, $filing, 'Claims need filing', $url, 95);
        }

        if ($this->warranty->allows($user, WarrantyRepairPermission::View)) {
            $base = $this->warranty->scopeQuery(WarrantyRepair::query(), $user)
                ->whereNotIn('status', ['completed', 'cancelled']);
            $external = (clone $base)->externalService()->count();
            $internal = (clone $base)->internalCompanyOwned()->count();
            if ($includeCards) {
                $cards->push($this->card('warranty', 'Warranty / Service', $external, 'Open external service cases', WarrantyRepairResource::getUrl()));
                $cards->push($this->card('internal_repairs', 'Internal Repairs', $internal, 'Company-owned repairs in progress', InternalRepairResource::getUrl()));
            }
            $this->attentionWhen($attention, $external + $internal, 'Repair cases need follow-up', WarrantyRepairResource::getUrl(), 65);
        }

        if ($this->complaints->allows($user, ComplaintPermission::View)) {
            $query = $this->complaints->scopeQuery(Complaint::query(), $user)
                ->whereNotIn('status', ['resolved', 'closed', 'cancelled']);
            $count = $query->count();
            $url = ComplaintResource::getUrl();
            if ($includeCards) {
                $cards->push($this->card('complaints', 'Open Complaints', $count, 'Visible unresolved complaints', $url));
            }
            $this->attentionWhen($attention, $count, 'Complaints need follow-up', $url, 70);
        }
    }

    private function addTaskCards(Collection $cards, Collection $attention, User $user, bool $includeCards = true): void
    {
        if (! $this->tasks->allows($user, TaskPermission::View)) {
            return;
        }

        $summary = $this->taskQueries->summary($this->taskQueries->visible($user));
        $open = $summary['pending'] + $summary['in_progress'] + $summary['waiting'] + $summary['awaiting_confirmation'];
        if ($includeCards) {
            $cards->push($this->card('tasks', 'Visible Open Tasks', $open, 'Personal, team, or company scope as authorized', MyWork::getUrl()));
        }
        $this->attentionWhen($attention, $summary['overdue'], 'Tasks are overdue', MyWork::getUrl(), 100);
        $this->attentionWhen($attention, $summary['awaiting_confirmation'], 'Task completion awaiting confirmation', TaskResource::getUrl(parameters: ['tab' => 'awaiting_confirmation']), 90);
    }

    /** @return array<string, mixed>|null */
    private function attendanceData(Collection $cards, Collection $attention, User $user, CarbonImmutable $from, CarbonImmutable $to, string $periodLabel, bool $includeCards = true, bool $includeAttention = true): ?array
    {
        $canView = $this->hr->allows($user, HrPermission::AttendanceViewOwn)
            || $this->hr->allows($user, HrPermission::AttendanceViewTeam)
            || $this->hr->allows($user, HrPermission::AttendanceViewAll);

        if (! $canView) {
            return null;
        }

        $query = $this->attendance->query($user, $from, $to);
        $summary = $this->attendance->summary(clone $query);
        $url = Attendance::getUrl();
        $isManagementDashboard = in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager], true);

        if ($includeCards && $isManagementDashboard) {
            $employees = $this->attendance->uniqueEmployeeSummary(clone $query);
            $cards->push($this->card('attendance_present', "Present Employees {$periodLabel}", $employees['present'], 'Unique employees present in authorized attendance scope', $url));
            $cards->push($this->card('attendance_late', "Employees Late {$periodLabel}", $employees['late'], $this->occurrenceDescription($summary['late'], 'late'), $url, $employees['late'] > 0 ? 'warning' : 'gray'));
            $cards->push($this->card('attendance_absent', "Employees Absent {$periodLabel}", $employees['absent'], $this->occurrenceDescription($summary['absent'], 'absence'), $url, $employees['absent'] > 0 ? 'danger' : 'gray'));
        } elseif ($includeCards) {
            $cards->push($this->card('attendance_present', "Present Days {$periodLabel}", $summary['present'], 'Your recorded present days', $url));
            $cards->push($this->card('attendance_late', "Late Occurrences {$periodLabel}", $summary['late'], 'Your recorded late attendance', $url, $summary['late'] > 0 ? 'warning' : 'gray'));
            $cards->push($this->card('attendance_absent', "Actual Absences {$periodLabel}", $summary['absent'], 'Your recorded actual absences', $url, $summary['absent'] > 0 ? 'danger' : 'gray'));
        }
        if ($includeAttention) {
            $this->attentionWhen($attention, $summary['missing_checkout'], 'Attendance missing check-out', $url, 60);
        }

        $pendingLeave = $this->pendingLeaveCount($user);
        if ($pendingLeave !== null) {
            if ($includeCards) {
                $cards->push($this->card('pending_leave', $this->hr->allows($user, HrPermission::LeaveApprove) ? 'Leave Awaiting Review' : 'My Pending Leave', $pendingLeave, 'Pending requests in authorized scope', LeaveManagement::getUrl()));
            }
            if ($includeAttention && $this->hr->allows($user, HrPermission::LeaveApprove)) {
                $this->attentionWhen($attention, $pendingLeave, 'Leave requests need review', LeaveManagement::getUrl(), 70);
            }
        }

        return ['summary' => $summary, 'url' => $url];
    }

    private function pendingLeaveCount(User $user): ?int
    {
        if ($this->hr->allows($user, HrPermission::LeaveApprove)) {
            return $this->hrScope->leaveQuery($user)->where('status', 'pending')
                ->where('employee_id', '!=', $user->employee->id)->count();
        }
        if ($this->hr->allows($user, HrPermission::LeaveViewOwn)) {
            return LeaveRequest::query()->where('employee_id', $user->employee->id)->where('status', 'pending')->count();
        }

        return null;
    }

    private function addCommunicationCards(Collection $cards, Collection $attention, User $user, bool $includeWorkCards = true, bool $includeHrCards = true, bool $includeAttention = true): void
    {
        if (($includeWorkCards || $includeAttention) && $this->chat->allows($user, ChatPermission::View)) {
            $unread = $this->chatQueries->totalUnread($user);
            if ($includeWorkCards) {
                $cards->push($this->card('chat_unread', 'Unread Chat', $unread, 'Unread accessible conversations', Chat::getUrl()));
            }
            if ($includeAttention) {
                $this->attentionWhen($attention, $unread, 'Unread chat messages', Chat::getUrl(), 45);
            }
        }

        if ($includeWorkCards) {
            $notifications = $user->unreadNotifications()->count();
            $cards->push($this->card('notifications', 'Unread Notifications', $notifications, 'Your notification inbox', Notifications::getUrl()));
        }

        if (($includeHrCards || $includeAttention) && $this->hr->allows($user, HrPermission::WarningViewOwn)) {
            $warningCount = EmployeeWarning::query()->where('employee_id', $user->employee->id)
                ->where('status', 'active')->where('acknowledgment_required', true)
                ->whereDoesntHave('acknowledgments', fn (Builder $query) => $query->where('employee_id', $user->employee->id)->whereNotNull('acknowledged_at'))
                ->count();
            if ($includeHrCards) {
                $cards->push($this->card('warning_acknowledgments', 'Warnings to Acknowledge', $warningCount, 'Your pending acknowledgments', EmployeeWarningResource::getUrl()));
            }
            if ($includeAttention) {
                $this->attentionWhen($attention, $warningCount, 'Warning acknowledgment required', EmployeeWarningResource::getUrl(), 88);
            }
        }

        if (($includeHrCards || $includeAttention) && $this->hr->allows($user, HrPermission::NoticeView)) {
            $noticeQuery = HrNotice::query()->where('status', 'active')
                ->where('acknowledgment_required', true)
                ->whereHas('recipients', fn (Builder $query) => $query->where('employee_id', $user->employee->id))
                ->whereDoesntHave('acknowledgments', fn (Builder $query) => $query->where('employee_id', $user->employee->id)->whereNotNull('acknowledged_at'));
            $noticeCount = $noticeQuery->count();
            if ($includeHrCards) {
                $cards->push($this->card('notice_acknowledgments', 'Notices to Acknowledge', $noticeCount, 'Your pending notice acknowledgments', HrNoticeResource::getUrl()));
            }
            if ($includeAttention) {
                $this->attentionWhen($attention, $noticeCount, 'Notice acknowledgment required', HrNoticeResource::getUrl(), 87);
            }
        }
    }

    private function addConsolidatedLegacyAttention(Collection $attention, User $user): void
    {
        if ($this->purchases->allows($user, PurchasePermission::View)) {
            $approved = Purchase::query()->where('status', PurchaseStatus::Approved)->count();
            $partial = Purchase::query()->where('status', PurchaseStatus::PartiallyReceived)->count();
            $this->attentionWhen($attention, $approved, 'Approved purchases awaiting receipt', PurchaseResource::getUrl(), 72);
            $this->attentionWhen($attention, $partial, 'Purchases partially received', PurchaseResource::getUrl(), 73);
        }

        if (! $this->responsibilities->allows($user, ResponsibilityPermission::ViewAll)) {
            return;
        }

        $inventoryIds = DB::table('inventory_responsibility_quantities as dashboard_capacity_irq')
            ->join('responsibility_assignments as dashboard_capacity_ra', 'dashboard_capacity_ra.id', '=', 'dashboard_capacity_irq.assignment_id')
            ->where('dashboard_capacity_ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('dashboard_capacity_ra.ended_at')
            ->distinct()->pluck('dashboard_capacity_irq.product_inventory_id');
        $counts = (object) ['over_assigned' => 0, 'at_capacity' => 0];
        foreach ($inventoryIds as $inventoryId) {
            $status = $this->responsibilityCapacity->summary((int) $inventoryId)['status']->value;
            if ($status === 'over_assigned') {
                $counts->over_assigned++;
            } elseif ($status === 'at_capacity') {
                $counts->at_capacity++;
            }
        }

        $this->attentionWhen($attention, (int) ($counts->over_assigned ?? 0), 'Responsibility inventory is over-assigned', MyInventory::getUrl(), 92);
        $this->attentionWhen($attention, (int) ($counts->at_capacity ?? 0), 'Responsibility inventory is at capacity', MyInventory::getUrl(), 68);
    }

    /** @return array<string, mixed>|null */
    private function responsibilityData(User $user): ?array
    {
        if (! $this->responsibilities->allows($user, ResponsibilityPermission::ViewOwn)) {
            return null;
        }

        $assignments = $this->responsibilityReads->assignmentsFor($user)
            ->where('employee_id', $user->employee->id)
            ->where('status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNull('ended_at')
            ->limit(8)
            ->get()
            ->map(function ($assignment): string {
                $parts = array_filter([
                    $assignment->categoryScope?->category?->name ? 'Category: '.$assignment->categoryScope->category->name : null,
                    $assignment->brandScope?->brand?->name ? 'Brand: '.$assignment->brandScope->brand->name : null,
                    $assignment->platformScope?->platform?->name ? 'Platform: '.$assignment->platformScope->platform->name : null,
                    $assignment->productScope?->product?->sku ? 'Product: '.$assignment->productScope->product->sku : null,
                    $assignment->quantityScope?->inventory?->product?->sku ? 'Stock: '.$assignment->quantityScope->inventory->product->sku : null,
                ]);

                return $parts === [] ? $assignment->reference : implode(' · ', $parts);
            });

        return ['items' => $assignments, 'url' => MyInventory::getUrl()];
    }

    private function scopedOrderIds(User $user): Builder
    {
        $query = Order::query()->select('id');
        if ($this->orderScope->requiresScope($user)) {
            $query->whereHas('items');
        }

        return $this->orderScope->applyOrders($query, $user);
    }

    /** @return array<string, mixed> */
    private function card(string $key, string $label, int|string $value, string $description, string $url, string $color = 'primary'): array
    {
        return compact('key', 'label', 'value', 'description', 'url', 'color');
    }

    /** @return array<string, mixed> */
    private function attention(string $label, int $count, string $url, int $priority): array
    {
        return compact('label', 'count', 'url', 'priority');
    }

    private function attentionWhen(Collection $attention, int $count, string $label, string $url, int $priority): void
    {
        if ($count > 0) {
            $attention->push($this->attention($label, $count, $url, $priority));
        }
    }

    private function money(string $amount): string
    {
        return 'AED '.number_format((float) $amount, 2);
    }

    private function occurrenceDescription(int $count, string $type): string
    {
        return number_format($count).' total '.$type.' '.($count === 1 ? 'occurrence' : 'occurrences');
    }

    private function wants(?Collection $requested, string $key): bool
    {
        return $requested === null || $requested->has($key);
    }
}
